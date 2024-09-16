<?php
namespace LFPhp\PORM\DB;

use Exception;
use LFPhp\Logger\Logger;
use LFPhp\PDODSN\DSN;
use LFPhp\PORM\Exception\DBException;
use LFPhp\PORM\Exception\Exception as PORMException;
use LFPhp\PORM\Exception\NullOperation;
use LFPhp\PORM\Exception\QueryException;
use LFPhp\PORM\Misc\PaginateInterface;
use PDO;
use PDOException;
use PDOStatement;
use function LFPhp\Func\array_first;
use function LFPhp\Func\event_fire;
use function LFPhp\Func\event_register;

/**
 * DB驱动
 * @package LFPhp\PORM\Driver
 */
class DBDriver {
	const EVENT_BEFORE_DB_QUERY = __CLASS__.'EVENT_BEFORE_DB_QUERY'; //回调参数[sql]
	const EVENT_AFTER_DB_QUERY = __CLASS__.'EVENT_AFTER_DB_QUERY'; //回调参数[sql, result]
	const EVENT_ON_DB_QUERY_ERROR = __CLASS__.'EVENT_ON_DB_QUERY_ERROR'; //回调参数[query, exception]
	const EVENT_BEFORE_DB_CONNECT = __CLASS__.'EVENT_BEFORE_DB_CONNECT'; //回调参数[dsn, counter第几次连接]
	const EVENT_AFTER_DB_CONNECT = __CLASS__.'EVENT_AFTER_DB_CONNECT'; //回调参数[dsn, counter第几次连接]
	const EVENT_ON_DB_CONNECT_FAIL = __CLASS__.'EVENT_ON_DB_CONNECT_FAIL'; //回调参数[exception, dsn, counter第几次连接]

	//LIKE 保留字符
	const LIKE_RESERVED_CHARS = ['%', '_'];

	//最大重试次数，如果该数据配置为0，将不进行重试
	protected $max_reconnect_count = 0;

	//重新连接间隔时间（毫秒）
	protected $reconnect_interval = 1000;

	//是否在更新空数据时抛异常，缺省不抛异常
	public static $THROW_EXCEPTION_ON_UPDATE_EMPTY_DATA = false;

	// select查询去重，默认关闭（避免影响业务）
	// 这部分逻辑可能针对某些业务逻辑有影响，如：做某些操作之后立即查询这种
	// so，如果程序需要，可以通过 DBAbstract::distinctQueryOff() 关闭这个选项
	private static $query_cache_on = false;
	private static $query_cache_data = [];

	/** @var \LFPhp\PDODSN\DSN */
	public $dsn;
	private $last_affect_num = 0;

	/**
	 * PDO TYPE MAP
	 * @var array
	 */
	private static $PDO_TYPE_MAP = array(
		'bool'    => PDO::PARAM_BOOL,
		'null'    => PDO::PARAM_BOOL,
		'int'     => PDO::PARAM_INT,
		'float'   => PDO::PARAM_INT,
		'decimal' => PDO::PARAM_INT,
		'double'  => PDO::PARAM_INT,
		'string'  => PDO::PARAM_STR,
	);

	/**
	 * @var PDO pdo connect resource
	 */
	private $conn = null;

	/**
	 * 数据库连接初始化，连接数据库，设置查询字符集，设置时区
	 * @param DSN $dsn
	 */
	private function __construct(DSN $dsn){
		$this->dsn = $dsn;
		$this->max_reconnect_count = $dsn->max_reconnect_count;
		$this->connect($this->dsn);
	}

	/**
	 * 绑定logger
	 * @param \LFPhp\Logger\Logger $logger
	 * @return void
	 */
	public static function setLogger(Logger $logger){
		$st = null;
		event_register(self::EVENT_BEFORE_DB_QUERY, function() use (&$st){
			$st = microtime(true);
		});
		event_register(self::EVENT_AFTER_DB_QUERY, function($sql) use ($logger, &$st){
			$tms = round((microtime(true) - $st)*1000).'ms';
			$logger->debug("Query[$tms] $sql");
		});
		event_register(self::EVENT_ON_DB_QUERY_ERROR, function($query, $exception) use ($logger){
			$logger->error("DB Query Fail: $query");
			$logger->exception($exception);
		});
		event_register(self::EVENT_BEFORE_DB_CONNECT, function($dsn, $counter) use ($logger){
			if($counter > 1){
				$logger->warning("DB re-connecting [$counter]", $dsn->__toString());
			}else{
				$logger->debug('DB connecting', $dsn->__toString());
			}
		});
		event_register(self::EVENT_AFTER_DB_CONNECT, function($dsn, $counter) use ($logger){
			$logger->debug('DB connect success', $dsn->__toString(), $counter);
		});
		event_register(self::EVENT_ON_DB_CONNECT_FAIL, function($ex, $dsn, $counter) use ($logger){
			$logger->error('DB connect fail:'.$ex->getMessage(), $dsn->__toString(), $counter);
			$logger->exception($ex);
		});
	}

	private static function _str_contains_all($haystack){
		$args = func_get_args();
		array_shift($args);
		foreach($args as $str){
			if(strpos($haystack, $str) === false){
				return false;
			}
		}
		return true;
	}

	/**
	 * PDO判别是否为连接丢失异常
	 * @return bool
	 */
	protected static function isConnectionLostException(Exception $exception){
		if($exception instanceof PDOException){
			$msg = $exception->getMessage();
			//HY000 means general error.

			//https://stackoverflow.com/questions/21091850/error-2013-hy000-lost-connection-to-mysql-server-at-reading-authorization-pa
			//ERROR 2013 (HY000): Lost connection to MySQL server at 'reading initial communication packet', system error: 0
			//这种情况可能是连接超时时间（connect_timeout）设置不够，导致mysql服务连接中被断开
			if(self::_str_contains_all($msg, 'HY000', '2013')){
				return true;
			}

			//https://stackoverflow.com/questions/7942154/mysql-error-2006-mysql-server-has-gone-away
			//2006, MySQL server has gone away
			//这种有可能是执行过程超时导致，例如packet太小（my.cnf max_allowed_packet 设置太小，或者超时时间 wait_timeout 之类的）
			if(self::_str_contains_all($msg, 'HY000', '2006')){
				return true;
			}

			//more
			//[ERROR 2003 (HY000): Can't connect to MySQL server on 'localhost:3306' (10061)]
		}
		return false;
	}

	/**
	 * 连接数据库接口
	 * @param DSN $dsn <p>数据库连接配置，
	 * 格式为：['type'=>'', 'driver'=>'', 'charset' => '', 'host'=>'', 'database'=>'', 'user'=>'', 'password'=>'', 'port'=>'']
	 * </p>
	 * @param boolean $force_reconnect 是否强制重新连接
	 * @return \PDO|null
	 */
	public function connect(DSN $dsn, $force_reconnect = false){
		if(!$force_reconnect && $this->conn){
			return $this->conn;
		}

		$dsn_key = $this->dsn->__toString();
		static $connect_counter;
		if(!isset($connect_counter[$dsn_key])){
			$connect_counter[$dsn_key] = 0;
		}
		if(!$this->max_reconnect_count && $connect_counter[$dsn_key]){
			throw new PORMException('Connect Lost');
		}
		while(true){
			try{
				$connect_counter[$dsn_key]++;
				event_fire(self::EVENT_BEFORE_DB_CONNECT, $dsn, $connect_counter[$dsn_key]);
				$this->conn = $dsn->pdoConnect();
				event_fire(self::EVENT_AFTER_DB_CONNECT, $dsn, $connect_counter[$dsn_key]);
				return $this->conn;
			}catch(Exception $ex){
				event_fire(self::EVENT_ON_DB_CONNECT_FAIL, $ex, $dsn, $connect_counter[$dsn_key]);
				if($connect_counter[$dsn_key] > $this->max_reconnect_count){
					throw $ex;
				}
				//间隔时间之后重新连接
				if($this->reconnect_interval){
					usleep($this->reconnect_interval*1000);
				}
			}
		}
	}

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
	 * @return \LFPhp\PORM\DB\DBDriver
	 */
	public function setMaxReconnectCount($max_reconnect_count){
		$this->max_reconnect_count = $max_reconnect_count;
		return $this;
	}

	/**
	 * 获取重连间隔时间
	 * @return int 毫秒
	 */
	public function getReconnectInterval(){
		return $this->reconnect_interval;
	}

	/**
	 * 设置重连间隔时间（毫秒）
	 * @param int $reconnect_interval
	 * @return DBDriver
	 */
	public function setReconnectInterval($reconnect_interval){
		$this->reconnect_interval = $reconnect_interval;
		return $this;
	}

	/**
	 * 获取最后插入ID
	 * @param string $name
	 * @return string
	 */
	public function getLastInsertId($name = null) {
		return $this->conn->lastInsertId($name);
	}

	/**
	 * database query function
	 * @param string|DBQuery $query
	 * @return PDOStatement
	 */
	protected function dbQuery($query){
		$sql = $query.'';
		$this->last_affect_num = 0;
		event_fire(self::EVENT_BEFORE_DB_QUERY, $sql);
		$result = $this->conn->query($sql);
		event_fire(self::EVENT_AFTER_DB_QUERY, $sql, $result);
		$this->last_affect_num = $result->rowCount();
		return $result;
	}

	/**
	 * 开启事务操作
	 * @return bool
	 */
	public function beginTransaction(){
		return $this->conn->beginTransaction();
	}

	/**
	 * 回滚事务
	 * @return bool
	 */
	public function rollback(){
		return $this->conn->rollBack();
	}

	/**
	 * 提交事务
	 * @return bool
	 */
	public function commit(){
		return $this->conn->commit();
	}

	/**
	 * 取消事务自动提交状态
	 * @return bool
	 */
	public function cancelTransactionState(){
		return $this->conn->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
	}

	/**
	 * 数据转义
	 * @param string $data
	 * @param string $type
	 * @return false|string
	 */
	public function quote($data, $type = null) {
		if(is_array($data)){
			$data = join(',', $data);
		}
		$type = in_array($type, self::$PDO_TYPE_MAP) ? $type : PDO::PARAM_STR;
		return $this->conn->quote($data, $type);
	}

	/**
	 * 设置SQL查询条数限制信息
	 * @param $sql
	 * @param $limit
	 * @return string
	 * @throws \LFPhp\PORM\Exception\DBException
	 */
	public function setLimit($sql, $limit) {
		if(preg_match('/\sLIMIT\s/i', $sql)){
			throw new DBException('SQL LIMIT BEEN SET:' . $sql);
		}
		if(is_array($limit)){
			return $sql . ' LIMIT ' . $limit[0] . ',' . $limit[1];
		}
		return $sql . ' LIMIT ' . $limit;
	}

	/**
	 * 获取所有行
	 * @param PDOStatement $resource
	 * @return array
	 */
	public function fetchAll($resource) {
		$resource->setFetchMode(PDO::FETCH_ASSOC);
		return $resource->fetchAll();
	}

	/**
	 * fetch one column
	 * @param PDOStatement $rs
	 * @return string
	 */
	public static function fetchColumn(PDOStatement $rs) {
		return $rs->fetchColumn();
	}

	/**
	 * 查询最近db执行影响行数
	 * @description 该方法调用时候需要谨慎，需要避免 last_affect_num 被覆盖
	 * @return integer
	 */
	public function getAffectNum() {
		return $this->last_affect_num;
	}

	/**
	 * 数据库数据字典
	 * @return array
	 */
	public function getDictionary(){
		$tables = $this->getTables();
		foreach($tables as $k=>$tbl_info){
			$fields = $this->getFields($tbl_info['table_name']);
			$tables[$k]['fields'] = $fields;
		}
		return $tables;
	}

	/**
	 * 获取数据库列表
	 * @return string[]
	 * @throws \LFPhp\PORM\Exception\DBException
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public function getDatabaseList(){
		$tmp = $this->getAll('SHOW DATABASES');
		$list = [];
		foreach($tmp as $item){
			$list[] = array_first($item);
		}
		return $list;
	}

	/**
	 * 获取数据库表清单
	 * @return array
	 */
	public function getTables(){
		$query = "SELECT `table_name` AS table_name, `engine` AS engine, `table_collation` AS table_collation, `table_comment` AS table_comment 
					FROM `information_schema`.`tables` 
					WHERE `table_schema`=?";
		$sth = $this->conn->prepare($query);
		$sth->execute([$this->dsn->database]);
		$tmp = $sth->fetchAll(PDO::FETCH_ASSOC) ?: [];
		//不同版本mysql返回字段名可能是大写的，需要强制转换一次
		foreach($tmp as $k => $item){
			$tmp[$k] = array_change_key_case($item, CASE_LOWER);
		}
		return $tmp;
	}

	/**
	 * 获取数据库表字段清单
	 * @param $table
	 * @return array
	 */
	public function getFields($table){
		$query = "SELECT `column_name`, `column_type`, `collation_name`, `is_nullable`, `column_key`, `column_default`, `extra`, `privileges`, `column_comment`
				    FROM `information_schema`.`columns`
				    WHERE `table_schema`=? AND `table_name`=?";
		$sth = $this->conn->prepare($query);
		$db = $this->dsn->database;
		$sth->execute([$db, $table]);
		$tmp = $sth->fetchAll(PDO::FETCH_ASSOC) ?: [];
		//不同版本mysql返回字段名可能是大写的，需要强制转换一次
		foreach($tmp as $k => $item){
			$tmp[$k] = array_change_key_case($item, CASE_LOWER);
		}
		return $tmp;
	}

	/**
	 * 解析SQL语句
	 * @param DBQuery|string $query
	 * @return array
	 * @throws DBException
	 */
	public function explain($query){
		$query = "EXPLAIN $query";
		$rst = $this->query($query);
		$data = $this->fetchAll($rst);
		return $data[0];
	}

	/**
	 * 设置查询字符集
	 * @param $charset
	 * @return \LFPhp\PORM\DB\DBDriver
	 * @throws \LFPhp\PORM\Exception\DBException
	 * @see https://stackoverflow.com/questions/3513773/change-mysql-default-character-set-to-utf-8-in-my-cnf
	 */
	public function setCharset($charset){
		$this->query("SET NAMES '$charset'");
		$this->query("SET CHARACTER SET '$charset'");
		return $this;
	}

	/**
	 * 设置时区
	 * @param string $timezone
	 * @throws \LFPhp\PORM\Exception\DBException
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
	 * @param DSN $dsn
	 * @return static
	 */
	final public static function instance(DSN $dsn){
		$key = md5($dsn);

		static $instance_list;
		if(!$instance_list){
			$instance_list = [];
		}

		if(!isset($instance_list[$key]) || !$instance_list[$key]){
			$ins = new self($dsn);
			$instance_list[$key] = $ins;
		}
		return $instance_list[$key];
	}

	/**
	 * 获取当前去重查询开启状态
	 * @return bool
	 */
	public static function getQueryCacheState(){
		return self::$query_cache_on;
	}

	/**
	 * 打开查询缓存
	 */
	public static function setQueryCacheOn(){
		self::$query_cache_on = true;
	}

	/**
	 * 关闭查询缓存
	 */
	public static function setQueryCacheOff(){
		self::$query_cache_on = false;
	}

	/**
	 * 以非去重模式（强制查询模式）进行查询
	 * @param callable $callback
	 */
	public static function noQueryCacheMode(callable $callback){
		$st = self::$query_cache_on;
		self::setQueryCacheOff();
		call_user_func($callback);
		self::$query_cache_on = $st;
	}

	/**
	 * 转义数组
	 * @param array $data
	 * @param array $types
	 * @return array
	 */
	public function quoteArray(array $data, array $types){
		foreach($data as $k => $item){
			$data[$k] = $this->quote($item, $types[$k]);
		}
		return $data;
	}

	/**
	 * 获取一页数据
	 * @param DBQuery|string $q
	 * @param PaginateInterface|array|number $pager
	 * @return array
	 * @throws \LFPhp\PORM\Exception\DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function getPage($q, $pager = null){
		if($q instanceof DBQuery){
			$query = clone($q);
		}else if(gettype($q) === 'string'){
			$query = new DBQuery($q);
		}else{
			throw new DBException('Query type error');
		}
		if($pager instanceof PaginateInterface){
			$total = $this->getCount($query);
			$pager->setItemTotal($total);
			$limit = $pager->getLimit();
		}else{
			$limit = $pager;
		}
		if($limit){
			$query->limit($limit);
		}

		$cache_key = $this->dsn.'/'.$query;
		$result = null;
		if(self::$query_cache_on){
			$result = isset(self::$query_cache_data[$cache_key]) ? self::$query_cache_data[$cache_key] : null;
		}
		if(!isset($result)){
			$rs = $this->query($query);
			if($rs){
				$result = $this->fetchAll($rs);
				if(self::$query_cache_on){
					self::$query_cache_data[$cache_key] = $result;
				}
			}
		}
		return $result ?: [];
	}

	/**
	 * 获取所有查询记录
	 * @param DBQuery|string $query
	 * @return array
	 * @throws \LFPhp\PORM\Exception\DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function getAll($query){
		return $this->getPage($query);
	}

	/**
	 * 获取指定表创建语句
	 * @param string $table
	 * @return string create table DSL
	 * @throws \LFPhp\PORM\Exception\DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function getDSLSchema($table){
		$ret = $this->getAll(new DBQuery("SHOW CREATE TABLE `$table`"));
		return $ret[0]['Create Table'];
	}

	/**
	 * 获取一条查询记录
	 * @param DBQuery|string $query
	 * @return array | null
	 * @throws \LFPhp\PORM\Exception\DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function getOne($query){
		$rst = $this->getPage($query, 1);
		if($rst){
			return $rst[0];
		}
		return null;
	}

	/**
	 * 获取一个字段
	 * @param DBQuery|string $query
	 * @param string $key
	 * @return mixed|null
	 * @throws \LFPhp\PORM\Exception\DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function getField($query, $key=''){
		$rst = $this->getOne($query);
		if($rst){
			return $key ? $rst[$key] : current($rst);
		}
		return null;
	}

	/**
	 * 更新数量
	 * @param string $table
	 * @param string $field
	 * @param integer $offset_count 增量（实数）
	 * @return int 更新影响条数
	 * @throws \LFPhp\PORM\Exception\DBException
	 */
	public function updateCount($table, $field, $offset_count = 1){
		$query = $this->genQuery();
		$sql = "UPDATE {$table} SET {$field} = {$field}".($offset_count > 0 ? " + {$offset_count}" : " - {$offset_count}");
		$query->setSql($sql);
		$this->query($query);
		return $this->getAffectNum();
	}

	/**
	 * 数据更新
	 * @param string $table
	 * @param array $data
	 * @param string $condition
	 * @param int $limit 更新影响条数
	 * @return int affect line number
	 * @throws \LFPhp\PORM\Exception\DBException
	 * @throws NullOperation|\LFPhp\PORM\Exception\Exception
	 */
	public function update($table, array $data, $condition = '', $limit = 1){
		if(empty($data)){
			if(static::$THROW_EXCEPTION_ON_UPDATE_EMPTY_DATA){
				throw new NullOperation('NO UPDATE DATA FOUND', 0, null, $table);
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
	 * @return int
	 * @throws DBException
	 * @throws NullOperation|\LFPhp\PORM\Exception\Exception
	 */
	public function replace($table, array $data, $condition = '', $limit = 0){
		if(empty($data)){
			throw new NullOperation('NO REPLACE DATA FOUND', 0, null, $table, $this->dsn);
		}
		$count = $this->getCount($this->genQuery()->select()->from($table)->where($condition)->limit(1));
		if($count){
			$query = $this->genQuery()->update()->from($table)->setData($data)->where($condition)->limit($limit);
			$this->query($query);
			return $count;
		}else{
			$query = $this->genQuery()->insert()->from($table)->setData($data);
			$this->query($query);
			return $this->getAffectNum();
		}
	}

	/**
	 * 插入数据
	 * @param $table
	 * @param $field
	 * @param int $offset
	 * @param string $statement
	 * @param int $limit
	 * @return int
	 * @throws DBException
	 */
	public function increase($table, $field, $offset = 1, $statement = '', $limit = 0){
		$off = $offset > 0 ? "+ $offset" : "$offset";
		$where = $statement ? "WHERE $statement" : '';
		$limit_str = $limit > 0 ? "LIMIT $limit" : '';
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
	 * @throws DBException
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
	 * @return PDOStatement
	 * @throws DBException
	 * @throws NullOperation
	 */
	public function insert($table, array $data, $condition = null){
		if(empty($data)){
			throw new NullOperation('NO INSERT DATA FOUND', 0, null, $table, $this->dsn);
		}
		$query = $this->genQuery()->insert()->from($table)->setData($data)->where($condition);
		return $this->query($query);
	}

	/**
	 * 产生Query对象
	 * @return DBQuery
	 */
	protected function genQuery(){
		return new DBQuery();
	}

	/**
	 * SQL查询，支持重连数据库选项
	 * @param DBQuery|string $query
	 * @return \PDOStatement
	 * @throws DBException
	 */
	final public function query($query){
		try{
			//由于PHP对数据库查询返回结果并非报告Exception，
			//因此这里不会将查询结果false情况包装成为Exception，但会继续触发错误事件。
			return $this->dbQuery($query);
		}catch(Exception $ex){
			event_fire(self::EVENT_ON_DB_QUERY_ERROR, $query, $ex);
			if(static::isConnectionLostException($ex)){
				$this->connect($this->dsn, true);
				return $this->query($query);
			}
			throw new QueryException($ex->getMessage(), $ex->getCode(), $ex, $query, $this->dsn);
		}
	}

	/**
	 * 获取条数
	 * @param DBQuery|string $query
	 * @return int
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function getCount($query){
		$query .= '';
		$query = trim(trim($query), ';');

		//针对 order by 识别不足，后期引入 https://github.com/greenlion/PHP-SQL-Parser 再处理
		//为了避免order中出现field，在select里面定义，select里面被删除了，导致order里面的field未定义。
		//同时提升Count性能
		//$query = preg_replace('/\sORDER\s+BY\s.*$/i', '', $query);

		if(preg_match('/^\s*SELECT.*?\s+FROM\s+/is', $query)){
			if(preg_match('/\sGROUP\s+by\s/im', $query) ||
				preg_match('/^\s*SELECT\s+DISTINCT\s/im', $query) ||
				preg_match('/\sLIMIT\s/im', $query)
			){
				$query = "SELECT COUNT(*) AS __NUM_COUNT__ FROM ($query) AS cnt_";
			}else{
				$query = preg_replace('/^\s*select.*?\s+from/is', 'SELECT COUNT(*) AS __NUM_COUNT__ FROM', $query);
			}
			$result = $this->getPage($query);
			if($result){
				return (int)$result[0]['__NUM_COUNT__'];
			}
			throw new PORMException("Query get counter fail: $query");
		}
		throw new PORMException("Query resolve select seg fail: $query");
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
}
