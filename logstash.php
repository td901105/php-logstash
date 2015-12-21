<?php
/**
 * Created by PhpStorm.
 * User: anythink
 * Date: 15/12/13
 * Time: 下午7:07
 */
define('php_logstash','0.1.1');

class LogStash{
	private $config;
	private $redis;
	protected $message;
	private $begin;

	private $cmd;
	private $args;
	private $file_pointer=0;

	/**
	 * @param array $config
	 */
	function handler(array $cfg=[]){
		$opt = getopt('',['listen::','indexer::','conf::','build::']);
		switch($opt){
			case isset($opt['listen']):
				$this->cmd = 'listen';
				$this->args = $opt['listen'];
				break;
			case isset($opt['indexer']):
				$this->cmd = 'indexer';
				$this->args = $opt['indexer'];
				break;
			case isset($opt['build']):
				$this->cmd = 'build';
				$this->args = $opt['build'];
				break;
			default:
				$this->cmd = 'listen';
				break;
		}

		$this->default_value($cfg,'host','127.0.0.1');
		$this->default_value($cfg,'port',6379);
		$this->default_value($cfg,'agent_log',__DIR__ .'/agent.log');
		$this->default_value($cfg,'type','log');
		$this->default_value($cfg,'input_sync_memory',5*1024*1024);
		$this->default_value($cfg,'input_sync_second',5);
		$this->default_value($cfg,'parser',[$this,'parser']);
		$this->default_value($cfg,'log_level','product');

		$this->default_value($cfg,'elastic_host',['http://127.0.0.1:9200']);
		$this->default_value($cfg,'prefix','phplogstash');
		$this->default_value($cfg,'elastic_user');
		$this->default_value($cfg,'elastic_pwd');
		$this->default_value($cfg,'shards',5);
		$this->default_value($cfg,'replicas',2);
		$this->config = $cfg;
		$this->redis();
		return $this;
	}

	function run(){
		$cmd = $this->cmd;
		$this->$cmd($this->args);
	}


	/**
	 * redis 链接
	 */
	private function redis(){
		try {
			$redis = new redis();
			$redis->pconnect($this->config['host'],$this->config['port'],0);
			$this->redis = $redis;
			$this->redis->ping();
		}catch (Exception $e){
			$this->log($e->getMessage() .' now retrying');
			sleep(1); //如果报错等一秒再重连
			$this->redis();
		}
	}

	/**
	 * 获取stdin输入
	 */
	function listen(){
		$this->begin = time();
		if($this->args){
			$this->log('begin in file mode' . $this->begin,'debug');
			if(!file_exists($this->args))exit(' file not found');

			while(true){
				$handle = fopen($this->args,'r');
				if ($handle){
					fseek($handle, $this->file_pointer);
					while($line = trim(fgets($handle))){
						$this->message[] = call_user_func($this->config['parser'],$line);
						$this->inputAgent();
					}
					$this->file_pointer = ftell($handle);
					fclose($handle);
					$this->log('listen for in ' . $this->args . ', file pointer ' . $this->file_pointer, 'debug');
					$this->inputAgent();
					sleep(1);
				}
			}
		}else{
			$this->log('begin in tail mode ' . $this->begin,'debug');
			while($line = fgets(STDIN)){
				$line = trim($line);
				if($line){
					$this->message[] = call_user_func($this->config['parser'],$line);
					$this->inputAgent();
				}
			}
		}
	}


	function indexer(){
		$this->begin = time();
		$this->esCurl('/_template/'.$this->config['prefix'],json_encode($this->esIndices()),'PUT');
		while (true) {
			while($msg = $this->redis->rPop($this->config['type'])){
				if (false !== $msg) {
					$this->message[] = $msg;
					$this->indexerAgent();
				}
			}
			sleep(1);
			$this->indexerAgent();
			$this->log('waiting for queue','debug');
		}
	}

	/**
	 * 创建假数据
	 * 需要内存128MB
	 */
	public function build($args){
		$all_count = !empty($args) ? $args : 200000;
		$start = microtime(true);
		$s = "";
		echo "start" . PHP_EOL;
		for($i=0;$i<$all_count;$i++){
			$s .= '{"timestamp":"'.date('c').'","host":"10.10.23.139","message":"'.$i.'","server":"v10.gaonengfun.com","client":"39.164.172.250","size":197,"responsetime":0.010,"domain":"v10.gaonengfun.com","method":"GET","url":"/index.php","requesturi":"/task/ballot?appkey=1&v=2.7.1&ch=xiaomi","via":"HTTP/1.1","request":"GET /task/ballot?appkey=1&v=2.7.1&ch=xiaomi HTTP/1.1","uagent":"NaoDong android 2.7.1","referer":"-","status":"200"}'.PHP_EOL;
			$rate = number_format(($i/$all_count * 100),0);
			if(memory_get_usage(true)/1024/1024 >= 50){
				file_put_contents('case.log',$s,FILE_APPEND);
				$s='';
			}
		}
		if($s){
			file_put_contents('case.log',$s,FILE_APPEND);
		}
		echo 'all complete '. (microtime(true)-$start) .' seconds'.PHP_EOL;
	}


	/**
	 * 判断es配置类型
	 * @return array
	 */
	private function getEsHost(){
		if(is_array($this->config['elastic_host'])){
			$rand = mt_rand(0,count($this->config['elastic_host'])-1);
			$cfg = $this->config['elastic_host'][$rand];
		}else{
			$cfg = $this->config['elastic_host'];
		}
		$parse_url = parse_url($cfg);
		return [
			'url' => $parse_url['scheme'] .'://'. $parse_url['host'] .':'. $parse_url['port'],
			'user' => isset($parse_url['user']) ?  $parse_url['user'] : null,
			'pass' => isset($parse_url['pass']) ? $parse_url['pass'] : null,
		];
	}

	private function esCurl($url,$data='',$method='POST'){
		$cfg = $this->getEsHost();
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $cfg['url'].$url);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch,CURLOPT_TIMEOUT,5);
		//curl_setopt ($ch, CURLOPT_PROXY, 'http://192.168.1.40:8888');

		if($cfg['user'] || $cfg['pass']){
			curl_setopt($ch, CURLOPT_USERPWD, "{$cfg['user']}:{$cfg['pass']}");
		}

		$method = strtoupper($method);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		if($data){
			curl_setopt ( $ch, CURLOPT_POSTFIELDS, $data );
		}


		$body = curl_exec($ch);
		$code = curl_getinfo($ch,CURLINFO_HTTP_CODE);





		if(curl_error($ch) || $code > 201){
			$this->log('ElasticSearch error ' .PHP_EOL.
				'code: ' .$code. PHP_EOL.
				$cfg['url'] .$url .' '. $method .PHP_EOL.
				'Curl error:' . curl_error($ch) .PHP_EOL.
				'body: ' .$body .PHP_EOL.
				'data: '.mb_substr($data,0,400)
			);
		}

		curl_close($ch);
		unset($code,$err,$data);

		return json_decode($body,true);
	}

	private function esIndices(){
		$string_not_analyzed = ['type'=>'string','index'=>'not_analyzed','doc_values'=>true];
		//put /d curl -XPUT localhost:9200/_template/template_1 -d
		$indices['template'] = $this->config['prefix'].'-*';
		$indices['settings']['index'] = [
			'number_of_shards' => $this->config['shards'],
			'number_of_replicas' => $this->config['replicas'],
			'refresh_interval'=>'5s'
		];

		$indices['mappings']['_default_']['dynamic_templates'][]['string_fields'] = [
			'match_mapping_type' => 'string',
			'mapping' => [
				'index' => 'analyzed',
				'omit_norms' => true,
				'type' => 'string',
				'fields' =>[
					'raw' => [
						'index' => 'not_analyzed',
						'ignore_above' => 256,
						'doc_values' => true,
						'type' => 'string'
					],
				],
			],
		];

		$indices['mappings']['_default_']['dynamic_templates'][]['float_fields'] = [
			'match_mapping_type' => 'float',
			'mapping' => [
				'doc_values' => true,
				'type' => 'float',
			],
		];

		$indices['mappings']['_default_']['dynamic_templates'][]['double_fields'] = [
			'match_mapping_type' => 'double',
			'mapping' => [
				'doc_values' => true,
				'type' => 'double',
			],
		];

		$indices['mappings']['_default_']['dynamic_templates'][]['byte_fields'] = [
			'match_mapping_type' => 'byte',
			'mapping' => [
				'doc_values' => true,
				'type' => 'byte',
			],
		];

		$indices['mappings']['_default_']['dynamic_templates'][]['short_fields'] = [
			'match_mapping_type' => 'short',
			'mapping' => [
				'doc_values' => true,
				'type' => 'short',
			],
		];

		$indices['mappings']['_default_']['dynamic_templates'][]['integer_fields'] = [
			'match_mapping_type' => 'integer',
			'mapping' => [
				'doc_values' => true,
				'type' => 'integer',
			],
		];

		$indices['mappings']['_default_']['dynamic_templates'][]['long_fields'] = [
			'match_mapping_type' => 'long',
			'mapping' => [
				'doc_values' => true,
				'type' => 'long',
			],
		];

		$indices['mappings']['_default_']['dynamic_templates'][]['date_fields'] = [
			'match_mapping_type' => 'date',
			'mapping' => [
				'doc_values' => true,
				'type' => 'date',
			],
		];

		$indices['mappings']['_default_']['dynamic_templates'][]['geo_point_fields'] = [
			'match_mapping_type' => 'geo_point',
			'mapping' => [
				'doc_values' => true,
				'type' => 'geo_point',
			],
		];

		$indices['mappings']['_default_']['_all'] = [
			'enabled' => true,
			'omit_norms'=> true,
		];
		$indices['mappings']['_default_']['properties'] = [
			'timestamp' => ['type'=>'date','doc_values' => true],
			'client'    =>['type'=>'ip'],
			'host'		=> ['type'=>'string','index'=>'not_analyzed'],
			'size'      => ['type'=>'integer','doc_values'=>true],
			'responsetime' => ['type'=>'float','doc_values'=>true],
			'request' => ['type'=>'string'],
			'status' => ['type'=>'integer','doc_values'=>true],
			'args' => ['type'=>'object'],
		];
		return $indices;
	}

	/**
	 * 默认的处理log的方法
	 * @param $message
	 * @return mixed
	 */
	private function parser($message){
		$json = json_decode($message,true);
		list($request_url,$params) = explode('?',$json['requesturi']);
		parse_str($params,$paramsOutput);
		$json['responsetime'] = floatval($json['responsetime']);
		$json['resquesturi'] = $request_url;
		$json['args'] = $paramsOutput;
		unset($request_url,$params,$paramsOutput);
		return $json;
	}



	private function inputAgent(){
		$current_usage = memory_get_usage();
		$sync_second = $this->config['input_sync_second'];
		$sync_memory = $this->config['input_sync_memory'];
		$time = ($this->begin + $sync_second) - time() ;
		if((memory_get_usage() > $sync_memory) or ( $this->begin+$sync_second  < time())){

			try{
				$this->redis->ping();
			}catch(Exception $e){
				$this->log('input Agent check redis :' . $e->getMessage(),'debug');
				$this->redis();
			}

			if(!empty($this->message)){
				try{
					$pipe = $this->redis->multi(Redis::PIPELINE);
					foreach($this->message as $pack){
						$pipe->lPush($this->config['type'],json_encode($pack));
					}
					$replies = $pipe->exec();
					$this->log('count memory > '.$sync_memory.' current:'.$current_usage.' or time > '.$sync_second.
						' current: '.$time.'s ','sync');
				}catch (Exception $e){
					$this->log('multi push error :' . $e->getMessage());
				}
			}

			$this->begin = time(); //reset begin time
			unset($this->message); //reset message count
		}
	}

	private function indexerAgent(){
		$current_usage = memory_get_usage();
		$sync_second = $this->config['input_sync_second'];
		$sync_memory = $this->config['input_sync_memory'];
		$time = ($this->begin + $sync_second) - time() ;
		if((memory_get_usage() > $sync_memory) or ( $this->begin+$sync_second  < time())){
			$row = '';
			if(!empty($this->message)){
				foreach($this->message as $pack){
					$json = json_decode($pack,true);
					$date = date('Y.m.d',strtotime($json['timestamp']));
					$type = $this->config['type'];
					$index = $this->config['prefix'].'-'.date('Y.m.d',strtotime($json['timestamp']));
					$row .= json_encode(['create' => ['_index' => $index ,'_type'  => $type]])."\n";
					$row .= json_encode($json)."\n";
				}
				if(!empty($row)){
					$this->esCurl('/_bulk',$row);
				}
			}


			$this->log('count memory > '.$sync_memory.' current:'.$current_usage.' or time > '.$sync_second.
				' current: '.$time.'s ','elasticsearch');
			$this->begin = time(); //reset begin time
			unset($this->message,$current_usage,$sync_second,$sync_memory,$time); //reset message count
		}
	}

	private function default_value(&$arr,$k,$v = ''){
		return $arr[$k] = isset($arr[$k]) ? $arr[$k] : $v;
	}

	private function log($msg,$level='warning'){
		if($this->config['log_level'] == 'debug' || ($this->config['log_level'] != 'debug' and $level != 'debug')){
			$message = '['.$level.'] ['.date('Y-m-d H:i:s').'] ['.(memory_get_usage()/1024/1024).'MB] ' .$msg. PHP_EOL;
			file_put_contents($this->config['agent_log'], $message, FILE_APPEND);
		}
	}
}

