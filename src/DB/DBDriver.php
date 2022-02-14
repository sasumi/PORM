<?php
namespace LFPhp\PORM\DB;

use Exception;
use LFPhp\PDODSN\DSN;
use LFPhp\PORM\Exception\DBException;
use LFPhp\PORM\Exception\NullOperation;
use LFPhp\PORM\Exception\QueryException;
use LFPhp\PORM\Misc\LoggerTrait;
use LFPhp\PORM\Misc\PaginateInterface;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Class DBAbstract
 * @package LFPhp\PORM\Driver
 */
class DBDriver {
	use LoggerTrait;
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

	// select查询去重，默认关闭（避免影响业务）
	// 这部分逻辑可能针对某些业务逻辑有影响，如：做某些操作之后立即查询这种
	// so，如果程序需要，可以通过 DBAbstract::distinctQueryOff() 关闭这个选项
	private static $query_cache_on = false;
	private static $query_cache_data = [];

	/**
	 * @var DBQuery current processing db query, support for exception handle
	 */
	private static $processing_query;

	/** @var \LFPhp\PDODSN\DSN */
	public $dsn;

	/**
	 * @var PDOStatement
	 */
	private $_last_query_result = null;

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
		$this->connect($this->dsn);
	}

	/**
	 * 是否切换到严格模式
	 * @param bool $to_strict
	 */
	public function toggleStrictMode($to_strict = false){
		if($to_strict){
			$sql = "set session sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'";
		} else {
			$sql = "set session sql_mode='NO_ENGINE_SUBSTITUTION'";
		}
		$this->conn->prepare($sql);
	}

	/**
	 * PDO判别是否为连接丢失异常
	 * @param \Exception $exception
	 * @return bool
	 */
	protected static function isConnectionLost(Exception $exception){
		if($exception instanceof PDOException){
			$lost_code_map = ['08S01', 'HY000'];
			if(in_array($exception->getCode(), $lost_code_map)){
				return true;
			}
		}
		return false;
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
	 * @param string|DBQuery $sql
	 * @return PDOStatement
	 */
	protected function dbQuery($sql){
		$this->_last_query_result = null;
		$result = $this->conn->query($sql.'');
		$this->_last_query_result = $result;
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
	 * @return mixed
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
	 * @return array | mixed
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
	 * @description 该方法调用时候需要谨慎，需要避免_last_query_result被覆盖
	 * @return integer
	 */
	public function getAffectNum() {
		return $this->_last_query_result ? $this->_last_query_result->rowCount() : 0;
	}

	/**
	 * 数据库数据字典
	 * @return array
	 */
	public function getDictionary(){
		$tables = self::getTables();
		foreach($tables as $k=>$tbl_info){
			$fields = self::getFields($tbl_info['TABLE_NAME']);
			$tables[$k]['FIELDS'] = $fields;
		}
		return $tables;
	}

	/**
	 * 获取数据库表清单
	 * @return array
	 */
	public function getTables(){
		$query = "SELECT `table_name`, `engine`, `table_collation`, `table_comment` FROM `information_schema`.`tables` WHERE `table_schema`=?";
		$sth = $this->conn->prepare($query);
		$sth->execute([$this->dsn->database]);
		return $sth->fetchAll(PDO::FETCH_ASSOC);
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
		return $sth->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * 解析SQL语句
	 * @param $sql
	 * @return array
	 * @throws DBException
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
	 * @return \LFPhp\PORM\DB\DBDriver
	 * @throws \LFPhp\PORM\Exception\DBException
	 */
	public function setCharset($charset){
		$this->query("SET NAMES '".$charset."'");
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
	 * 获取正在提交中的查询
	 * @return mixed
	 */
	public static function getProcessingQuery(){
		return self::$processing_query;
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
	 * @param \LFPhp\PORM\DB\DBQuery $q
	 * @param PaginateInterface|array|number $pager
	 * @return array
	 * @throws \LFPhp\PORM\Exception\DBException
	 */
	public function getPage(DBQuery $q, $pager = null){
		$query = clone($q);
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
		$cache_key = $this->dsn.'/'.$query->__toString();
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
		return $result;
	}

	/**
	 * 获取所有查询记录
	 * @param DBQuery $query
	 * @return mixed
	 * @throws \LFPhp\PORM\Exception\DBException
	 */
	public function getAll(DBQuery $query){
		return $this->getPage($query, null);
	}

	/**
	 * 获取一条查询记录
	 * @param DBQuery $query
	 * @return array | null
	 * @throws \LFPhp\PORM\Exception\DBException
	 */
	public function getOne(DBQuery $query){
		$rst = $this->getPage($query, 1);
		if($rst){
			return $rst[0];
		}
		return null;
	}

	/**
	 * 获取一个字段
	 * @param DBQuery $query
	 * @param string $key
	 * @return mixed|null
	 * @throws \LFPhp\PORM\Exception\DBException
	 */
	public function getField(DBQuery $query, $key=''){
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
		$prefix = $this->dsn['prefix'] ?: '';
		$query = $this->genQuery();
		$sql = "UPDATE {$prefix}{$table} SET {$field} = {$field}".($offset_count > 0 ? " + {$offset_count}" : " - {$offset_count}");
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
	 * @throws NullOperation
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
	 * @throws NullOperation
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
	 * @return mixed
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
		$prefix = isset($this->dsn['prefix']) ? $this->dsn['prefix'] : '';
		$ins = new DBQuery();
		$ins->setTablePrefix($prefix);
		return $ins;
	}

	/**
	 * SQL查询，支持重连数据库选项
	 * @param \LFPhp\PORM\DB\DBQuery|string $query
	 * @return mixed
	 * @throws DBException
	 */
	final public function query($query){
		try{
			self::$processing_query = $query;
			self::getLogger()->info('query:'.$query.'');
			$result = $this->dbQuery($query);
			self::$processing_query = null;

			//由于PHP对数据库查询返回结果并非报告Exception，
			//因此这里不会将查询结果false情况包装成为Exception，但会继续触发错误事件。
			return $result;
		}catch(Exception $ex){
			static $reconnect_count_map;
			$k = $this->dsn->__toString();
			if(!isset($reconnect_count_map[$k])){
				$reconnect_count_map[$k] = 0;
			}
			if(static::isConnectionLost($ex) && $this->max_reconnect_count && ($reconnect_count_map[$k] < $this->max_reconnect_count)){
				self::getLogger()->warning('DB lost connection, reconnecting');
				//间隔时间之后重新连接
				if($this->reconnect_interval){
					usleep($this->reconnect_interval*1000);
				}
				$reconnect_count_map[$k]++;
				try{
					$this->connect($this->dsn, true);
				}catch(Exception $e){
					//ignore reconnect exception
				}
				return $this->query($query);
			}
			throw new QueryException($ex->getMessage(), $ex->getCode(), $ex, $query, $this->dsn);
		}
	}

	/**
	 * 获取条数
	 * @param $sql
	 * @return int
	 * @throws DBException
	 */
	public function getCount($sql){
		$sql .= '';
		$sql = str_replace(array("\n", "\r"), '', trim($sql));

		//为了避免order中出现field，在select里面定义，select里面被删除了，导致order里面的field未定义。
		//同时提升Count性能
		$sql = preg_replace('/\sorder\s+by\s.*$/i', '', $sql);

		if(preg_match('/^\s*SELECT.*?\s+FROM\s+/i', $sql)){
			if(preg_match('/\sGROUP\s+by\s/i', $sql) || preg_match('/^\s*SELECT\s+DISTINCT\s/i', $sql)){
				$sql = "SELECT COUNT(*) AS __NUM_COUNT__ FROM ($sql) AS cnt_";
			}else{
				$sql = preg_replace('/^\s*select.*?\s+from/i', 'SELECT COUNT(*) AS __NUM_COUNT__ FROM', $sql);
			}
			$result = $this->getOne(new DBQuery($sql));
			if($result){
				return (int)$result['__NUM_COUNT__'];
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
	 * 连接数据库接口
	 * @param DSN $dsn <p>数据库连接配置，
	 * 格式为：['type'=>'', 'driver'=>'', 'charset' => '', 'host'=>'', 'database'=>'', 'user'=>'', 'password'=>'', 'port'=>'']
	 * </p>
	 * @param boolean $re_connect 是否重新连接
	 * @return \PDO|null
	 */
	public function connect(DSN $dsn, $re_connect = false){
		if(!$re_connect && $this->conn){
			return $this->conn;
		}
		$this->conn = $dsn->pdoConnect();
		return $this->conn;
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
}
