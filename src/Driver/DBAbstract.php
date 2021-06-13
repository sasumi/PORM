<?php
namespace LFPhp\PORM\Driver;

use LFPhp\PORM\Exception\Exception;
use LFPhp\PORM\Exception\NullOperation;
use LFPhp\PORM\Exception\QueryException;
use LFPhp\PORM\Misc\DBConfig;
use LFPhp\PORM\Misc\PaginateInterface;
use LFPhp\PORM\Misc\RefParam;
use LFPhp\PORM\Query;

abstract class DBAbstract{
	const EVENT_BEFORE_DB_QUERY = __CLASS__.'EVENT_BEFORE_DB_QUERY';
	const EVENT_AFTER_DB_QUERY = __CLASS__.'EVENT_AFTER_DB_QUERY';
	const EVENT_DB_QUERY_ERROR = __CLASS__.'EVENT_DB_QUERY_ERROR';
	const EVENT_BEFORE_DB_GET_LIST = __CLASS__.'EVENT_BEFORE_DB_GET_LIST';
	const EVENT_AFTER_DB_GET_LIST = __CLASS__.'EVENT_AFTER_DB_GET_LIST';
	const EVENT_ON_DB_CONNECT = __CLASS__.'EVENT_ON_DB_CONNECT';
	const EVENT_ON_DB_CONNECT_FAIL = __CLASS__.'EVENT_ON_DB_CONNECT_FAIL';
	const EVENT_ON_DB_QUERY_DISTINCT = __CLASS__.'EVENT_ON_DB_QUERY_DISTINCT';
	const EVENT_ON_DB_RECONNECT = __CLASS__.'EVENT_ON_DB_RECONNECT';

	//LIKE 保留字符
	const LIKE_RESERVED_CHARS = ['%', '_'];

	//最大重试次数，如果该数据配置为0，将不进行重试
	protected $max_reconnect_count = 0;
	
	//重新连接间隔时间（毫秒）
	protected $reconnect_interval = 1000;

	//是否在更新空数据时抛异常，缺省不抛异常
	public static $THROW_EXCEPTION_ON_UPDATE_EMPTY_DATA = false;
	
	// select查询去重
	// 这部分逻辑可能针对某些业务逻辑有影响，如：做某些操作之后立即查询这种
	// so，如果程序需要，可以通过 DBAbstract::distinctQueryOff() 关闭这个选项
	private static $QUERY_DISTINCT = true;
	private static $query_cache = array();
	
	/**
	 * @var Query current processing db query, support for exception handle
	 */
	private static $processing_query;
	
	/**
	 * database config
	 * @var DBConfig
	 */
	public $db_config;

	/**
	 * 数据库连接初始化，连接数据库，设置查询字符集，设置时区
	 * @param \LFPhp\PORM\Misc\DBConfig $config
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	private function __construct(DBConfig $config){
		$this->db_config = $config;

		$this->connect($this->db_config);

		//charset
		if($this->db_config->charset){
			$this->setCharset($this->db_config->charset);
		}
		
		//timezone
		if(isset($this->db_config->timezone) && $this->db_config->timezone){
			$this->setTimeZone($this->db_config->timezone);
		}
	}
	
	/**
	 * 解析SQL语句
	 * @param $sql
	 * @return array
	 * @throws Exception
	 */
	public function explain($sql){
		$sql = "EXPLAIN $sql";
		$rst = $this->query($sql);
		$data = $this->fetchAll($rst);
		return $data[0];
	}

	/**
	 * 设置查询字符集
	 * @param $charset
	 * @return \LFPhp\PORM\Driver\DBAbstract
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public function setCharset($charset){
		$this->query("SET NAMES '".$charset."'");
		return $this;
	}

	/**
	 * 设置时区
	 * @param string $timezone
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public function setTimeZone($timezone){
		if(preg_match('/[a-zA-Z]/', $timezone)){
			$def_tz = date_default_timezone_get();
			date_default_timezone_set('UTC');
			date_default_timezone_set($timezone);
			$timezone = date('P');
			date_default_timezone_set($def_tz); //reset system default timezone setting
		}
		$this->query("SET time_zone = '$timezone'");
	}

	/**
	 * 单例
	 * @param DBConfig $db_config
	 * @return static
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	final public static function instance(DBConfig $db_config){
		$key = md5($db_config->toDSNString());

		static $instance_list;
		if(!$instance_list){
			$instance_list = [];
		}
		
		if(!isset($instance_list[$key]) || !$instance_list[$key]){
			/** @var self $class */

			switch($db_config->driver){
				case DBConfig::DRIVER_MYSQLI:
					$ins = new DriverMySQLi($db_config);
					break;

				case DBConfig::DRIVER_PDO:
					$ins = new DriverPDO($db_config);
					break;
				
				default:
					throw new Exception("database config driver: [$db_config->driver] no support", 0, $db_config);
			}
			$instance_list[$key] = $ins;
		}
		return $instance_list[$key];
	}

	/**
	 * 获取当前去重查询开启状态
	 * @return bool
	 */
	public static function distinctQueryState(){
		return self::$QUERY_DISTINCT;
	}
	
	/**
	 * 打开去重查询模式
	 */
	public static function distinctQueryOn(){
		self::$QUERY_DISTINCT = true;
	}
	
	/**
	 * 关闭去重查询模式
	 */
	public static function distinctQueryOff(){
		self::$QUERY_DISTINCT = false;
	}
	
	/**
	 * 以非去重模式（强制查询模式）进行查询
	 * @param callable $callback
	 */
	public static function noDistinctQuery(callable $callback){
		$st = self::$QUERY_DISTINCT;
		self::distinctQueryOn();
		call_user_func($callback);
		self::$QUERY_DISTINCT = $st;
	}
	
	/**
	 * 获取正在提交中的查询
	 * @return mixed
	 */
	public static function getProcessingQuery(){
		return self::$processing_query;
	}
	
	/**
	 * 转义数据，缺省为统一使用字符转义
	 * @param string $data
	 * @param string $type @todo 支持数据库查询转义数据类型
	 * @return mixed
	 */
	public function quote($data, $type = null){
		if(is_array($data)){
			$data = join(',', $data);
		}
		if($data === null){
			return 'null';
		}
		if(is_bool($data)){
			return $data ? 'TRUE' : 'FALSE';
		}
		if(!is_string($data) && is_numeric($data)){
			return $data;
		}
		return "'".addslashes($data)."'";
	}
	
	/**
	 * 转义数组
	 * @param $data
	 * @param array $types
	 * @return mixed
	 */
	public function quoteArray(array $data, array $types){
		foreach($data as $k => $item){
			$data[$k] = $this->quote($item, $types[$k]);
		}
		return $data;
	}

	/**
	 * 获取一页数据
	 * @param \LFPhp\PORM\Query $q
	 * @param PaginateInterface|array|number $pager
	 * @return array
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public function getPage(Query $q, $pager = null){
		$query = clone($q);
		if($pager instanceof PaginateInterface){
			$total = $this->getCount($query);
			$pager->setItemTotal($total);
			$limit = $pager->getLimit();
		} else{
			$limit = $pager;
		}
		if($limit){
			$query->limit($limit);
		}
		$param = new RefParam(array(
			'query'  => $query,
			'result' => null
		));
		if(!is_array($param['result'])){
			if(self::$QUERY_DISTINCT){
				$param['result'] = isset(self::$query_cache[$query.'']) ? self::$query_cache[$query.''] : null; //todo 这里通过 isFRQuery 可以做全表cache
			}
			if(!isset($param['result'])){
				$rs = $this->query($param['query']);
				if($rs){
					$param['result'] = $this->fetchAll($rs);
					if(self::$QUERY_DISTINCT){
						self::$query_cache[$query.''] = $param['result'];
					}
				}
			}
		}
		return $param['result'] ?: array();
	}

	/**
	 * 获取所有查询记录
	 * @param Query $query
	 * @return mixed
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public function getAll(Query $query){
		return $this->getPage($query, null);
	}

	/**
	 * 获取一条查询记录
	 * @param Query $query
	 * @return array | null
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public function getOne(Query $query){
		$rst = $this->getPage($query, 1);
		if($rst){
			return $rst[0];
		}
		return null;
	}

	/**
	 * 获取一个字段
	 * @param Query $query
	 * @param string $key
	 * @return mixed|null
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public function getField(Query $query, $key){
		$rst = $this->getOne($query);
		if($rst){
			return $rst[$key];
		}
		return null;
	}

	/**
	 * 更新数量
	 * @param string $table
	 * @param string $field
	 * @param integer $offset_count
	 * @return boolean
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public function updateCount($table, $field, $offset_count = 1){
		$prefix = $this->db_config['prefix'] ?: '';
		$query = $this->genQuery();
		$sql = "UPDATE {$prefix}{$table} SET {$field} = {$field}".($offset_count>0 ? " + {$offset_count}" : " - {$offset_count}");
		$query->setSql($sql);
		$this->query($query);
		return $this->getAffectNum();
	}

	/**
	 * 数据更新
	 * @param string $table
	 * @param array $data
	 * @param string $condition
	 * @param int $limit
	 * @return int affect line number
	 * @throws \LFPhp\PORM\Exception\Exception
	 * @throws \LFPhp\PORM\Exception\NullOperation
	 */
	public function update($table, array $data, $condition = '', $limit = 1){
		if(empty($data)){
			if(static::$THROW_EXCEPTION_ON_UPDATE_EMPTY_DATA){
				throw new NullOperation('NO UPDATE DATA FOUND');
			}
			return false;
		}
		$query = $this->genQuery()->update()->from($table)->setData($data)->where($condition)->limit($limit);
		$this->query($query);
		return $this->getAffectNum();
	}

	/**
	 * replace data
	 * @param $table
	 * @param array $data
	 * @param string $condition
	 * @param int $limit
	 * @return mixed
	 * @throws \LFPhp\PORM\Exception\Exception
	 * @throws \LFPhp\PORM\Exception\NullOperation
	 */
	public function replace($table, array $data, $condition = '', $limit = 0){
		if(empty($data)){
			throw new NullOperation('NO REPLACE DATA FOUND');
		}
		
		$count = $this->getCount($this->genQuery()->select()->from($table)->where($condition)->limit(1));
		if($count){
			$query = $this->genQuery()->update()->from($table)->setData($data)->where($condition)->limit($limit);
			$this->query($query);
			return $count;
		} else {
			$query = $this->genQuery()->insert()->from($table)->setData($data);
			$this->query($query);
			return $this->getAffectNum();
		}
	}

	/**
	 * @param $table
	 * @param $field
	 * @param int $offset
	 * @param string $statement
	 * @param int $limit
	 * @return int
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public function increase($table, $field, $offset = 1, $statement = '', $limit = 0){
		$off = $offset>0 ? "+ $offset" : "$offset";
		$where = $statement ? "WHERE $statement" : '';
		$limit_str = $limit>0 ? "LIMIT $limit" : '';
		$query = "UPDATE `$table` SET `$field` = `$field` $off $where $limit_str";
		$this->query($query);
		return $this->getAffectNum();
	}

	/**
	 * 删除数据库数据
	 * @param $table
	 * @param $condition
	 * @param int $limit 参数为0表示不进行限制
	 * @return bool
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public function delete($table, $condition, $limit = 0){
		$query = $this->genQuery()->from($table)->delete()->where($condition);
		if($limit != 0){
			$query = $query->limit($limit);
		}
		$result = $this->query($query);
		return !!$result;
	}

	/**
	 * 数据插入
	 * @param $table
	 * @param array $data
	 * @param null $condition
	 * @return mixed
	 * @throws \LFPhp\PORM\Exception\Exception
	 * @throws \LFPhp\PORM\Exception\NullOperation
	 */
	public function insert($table, array $data, $condition = null){
		if(empty($data)){
			throw new NullOperation('NO INSERT DATA FOUND');
		}
		$query = $this->genQuery()->insert()->from($table)->setData($data)->where($condition);
		return $this->query($query);
	}
	
	/**
	 * 产生Query对象
	 * @return Query
	 */
	protected function genQuery(){
		$prefix = isset($this->db_config['prefix']) ? $this->db_config['prefix'] : '';
		$ins = new Query();
		$ins->setTablePrefix($prefix);
		return $ins;
	}

	/**
	 * SQL查询
	 * @param $query
	 * @return mixed
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	final public function query($query){
		try{
			self::$processing_query = $query;
			$result = $this->dbQuery($query);
			self::$processing_query = null;

			//由于PHP对数据库查询返回结果并非报告Exception，
			//因此这里不会将查询结果false情况包装成为Exception，但会继续触发错误事件。
			return $result;
		}catch(\Exception $ex){
			static $reconnect_count;
			if(static::isConnectionLost($ex) && $this->max_reconnect_count && ($reconnect_count < $this->max_reconnect_count)){
				//间隔时间之后重新连接
				if($this->reconnect_interval){
					usleep($this->reconnect_interval*1000);
				}
				$reconnect_count++;
				try{
					$this->connect($this->db_config, true);
				}catch(\Exception $e){
					//ignore reconnect exception
				}
				return $this->query($query);
			}
			throw new QueryException($query.'', $ex->getMessage(), $ex->getCode(), $ex, $this->db_config);
		}
	}
	
	/**
	 * 根据message检测服务器是否丢失、断开、重置链接
	 * @param \Exception $exception
	 * @return bool
	 */
	protected static function isConnectionLost(\Exception $exception){
		$error = $exception->getMessage();
		$ms = ['server has gone away', 'shut down'];
		foreach($ms as $kw){
			if(stripos($kw, $error) !== false){
				return true;
			}
		}
		return false;
	}
	
	/**
	 * 执行查询
	 * 规划dbQuery代替实际的数据查询主要目的是：为了统一对数据库查询动作做统一的行为监控
	 * @param $query
	 * @return mixed|false 返回查询结果，如果查询失败，则返回false
	 */
	protected abstract function dbQuery($query);

	/**
	 * 获取条数
	 * @param $sql
	 * @return mixed
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public function getCount($sql){
		$sql .= '';
		$sql = str_replace(array("\n", "\r"), '', trim($sql));

		//为了避免order中出现field，在select里面定义，select里面被删除了，导致order里面的field未定义。
		//同时提升Count性能
		$sql = preg_replace('/\sorder\s+by\s.*$/i', '', $sql);

		if(preg_match('/^\s*SELECT.*?\s+FROM\s+/i', $sql)){
			if(preg_match('/\sGROUP\s+by\s/i', $sql) ||
				preg_match('/^\s*SELECT\s+DISTINCT\s/i', $sql)){
				$sql = "SELECT COUNT(*) AS __NUM_COUNT__ FROM ($sql) AS cnt_";
			} else {
				$sql = preg_replace('/^\s*select.*?\s+from/i', 'SELECT COUNT(*) AS __NUM_COUNT__ FROM', $sql);
			}
			$result = $this->getOne(new Query($sql));
			if($result){
				return (int) $result['__NUM_COUNT__'];
			}
		}
		return 0;
	}

	/**
	 * Like操作语句转义
	 * @param $statement
	 * @param string $escape_char
	 * @return string
	 */
	public function quoteLike($statement, $escape_char = '\\'){
		return $this->quote($statement)." ESCAPE '$escape_char'";
	}

	/**
	 * 获取操作影响条数
	 * @return integer
	 */
	public abstract function getAffectNum();
	
	/**
	 * 获取所有记录
	 * @param $resource
	 * @return mixed
	 */
	public abstract function fetchAll($resource);
	
	/**
	 * 设置SQL查询条数限制信息
	 * @param $sql
	 * @param $limit
	 * @return mixed
	 */
	public abstract function setLimit($sql, $limit);
	
	/**
	 * 获取最后插入ID
	 * @return mixed
	 */
	public abstract function getLastInsertId();
	
	/**
	 * 事务提交
	 * @return bool
	 */
	public abstract function commit();
	
	/**
	 * 事务回滚
	 * @return bool
	 */
	public abstract function rollback();
	
	/**
	 * 开始事务操作
	 * @return mixed
	 */
	public abstract function beginTransaction();
	
	/**
	 * 取消事务操作状态
	 * @return mixed
	 */
	public abstract function cancelTransactionState();

	/**
	 * 连接数据库接口
	 * @param \LFPhp\PORM\Misc\DBConfig $db_config <p>数据库连接配置，
	 * 格式为：['type'=>'', 'driver'=>'', 'charset' => '', 'host'=>'', 'database'=>'', 'user'=>'', 'password'=>'', 'port'=>'']
	 * </p>
	 * @param boolean $re_connect 是否重新连接
	 * @return resource
	 */
	public abstract function connect(DBConfig $db_config, $re_connect = false);

	/**
	 * 获取最大链接重试次数
	 * @return int
	 */
	public function getMaxReconnectCount(){
		return $this->max_reconnect_count;
	}

	/**
	 * 设置链接重试次数
	 * @param int $max_reconnect_count
	 * @return \LFPhp\PORM\Driver\DBAbstract
	 */
	public function setMaxReconnectCount($max_reconnect_count){
		$this->max_reconnect_count = $max_reconnect_count;
		return $this;
	}

	/**
	 * @return int
	 */
	public function getReconnectInterval(){
		return $this->reconnect_interval;
	}

	/**
	 * @param int $reconnect_interval
	 * @return \LFPhp\PORM\Driver\DBAbstract
	 */
	public function setReconnectInterval($reconnect_interval){
		$this->reconnect_interval = $reconnect_interval;
		return $this;
	}
}
