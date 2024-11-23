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
 * DB driver
 * @package LFPhp\PORM\Driver
 */
class DBDriver {
	const EVENT_BEFORE_DB_QUERY = __CLASS__.'EVENT_BEFORE_DB_QUERY'; //Callback parameter [sql]
	const EVENT_AFTER_DB_QUERY = __CLASS__.'EVENT_AFTER_DB_QUERY'; //Callback parameters [sql, result]
	const EVENT_ON_DB_QUERY_ERROR = __CLASS__.'EVENT_ON_DB_QUERY_ERROR'; //Callback parameters [query, exception]
	const EVENT_BEFORE_DB_CONNECT = __CLASS__.'EVENT_BEFORE_DB_CONNECT'; //Callback parameters [dsn, counter, the number of connections]
	const EVENT_AFTER_DB_CONNECT = __CLASS__.'EVENT_AFTER_DB_CONNECT'; //Callback parameters [dsn, counter, the number of connections]
	const EVENT_ON_DB_CONNECT_FAIL = __CLASS__.'EVENT_ON_DB_CONNECT_FAIL'; //Callback parameters [exception, dsn, counter, the number of connections]

	//LIKE reserved characters
	const LIKE_RESERVED_CHARS = ['%', '_'];

	//Maximum number of retries. If this data is configured as 0, no retries will be performed.
	protected $max_reconnect_count = 0;

	//Reconnection interval (milliseconds)
	protected $reconnect_interval = 1000;

	//Whether to throw an exception when updating empty data, by default no exception is thrown
	public static $THROW_EXCEPTION_ON_UPDATE_EMPTY_DATA = false;

	// Select query deduplication, closed by default (to avoid affecting business)
	// This part of the logic may have an impact on some business logic, such as: querying this immediately after performing certain operations
	// so, if the program needs it, you can turn off this option via DBAbstract::distinctQueryOff()
	private static $query_cache_on = false;
	private static $query_cache_data = [];

	/** @var \LFPhp\PDODSN\DSN */
	public $dsn;
	private $last_affect_num = 0;

	/**
	 * PDO TYPE MAP
	 * @var array
	 */
	private static $PDO_TYPE_MAP = [
		'bool'    => PDO::PARAM_BOOL,
		'null'    => PDO::PARAM_BOOL,
		'int'     => PDO::PARAM_INT,
		'float'   => PDO::PARAM_INT,
		'decimal' => PDO::PARAM_INT,
		'double'  => PDO::PARAM_INT,
		'string'  => PDO::PARAM_STR,
	];

	/**
	 * @var PDO pdo connect resource
	 */
	private $conn = null;

	/**
	 * Initialize database connection, connect to database, set query character set, set time zone
	 * @param DSN $dsn
	 */
	private function __construct(DSN $dsn){
		$this->dsn = $dsn;
		$this->max_reconnect_count = $dsn->max_reconnect_count;
		$this->connect($this->dsn);
	}

	/**
	 * Bind logger
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
	 * PDO determines whether the connection is lost abnormally
	 * @return bool
	 */
	protected static function isConnectionLostException(Exception $exception){
		if($exception instanceof PDOException){
			$msg = $exception->getMessage();
			//HY000 means general error.

			//https://stackoverflow.com/questions/21091850/error-2013-hy000-lost-connection-to-mysql-server-at-reading-authorization-pa
			//ERROR 2013 (HY000): Lost connection to MySQL server at 'reading initial communication packet', system error: 0
			//This may be because the connection timeout (connect_timeout) is not set enough, causing the MySQL service connection to be disconnected
			if(self::_str_contains_all($msg, 'HY000', '2013')){
				return true;
			}

			//https://stackoverflow.com/questions/7942154/mysql-error-2006-mysql-server-has-gone-away
			//2006, MySQL server has gone away
			//This may be caused by a timeout in the execution process, for example, the packet is too small (the my.cnf max_allowed_packet setting is too small, or the timeout wait_timeout, etc.)
			if(self::_str_contains_all($msg, 'HY000', '2006')){
				return true;
			}

			//more
			//[ERROR 2003 (HY000): Can't connect to MySQL server on 'localhost:3306' (10061)]
		}
		return false;
	}

	/**
	 * Connect to database interface
	 * @param DSN $dsn <p>Database connection configuration,
	 * The format is: ['type'=>'', 'driver'=>'', 'charset' => '', 'host'=>'', 'database'=>'', 'user'=>'', 'password'=>'', 'port'=>'']
	 * </p>
	 * @param boolean $force_reconnect whether to force reconnect
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
				//Reconnect after the interval
				if($this->reconnect_interval){
					usleep($this->reconnect_interval*1000);
				}
			}
		}
	}

	/**
	 * Get the maximum number of link retries
	 * @return int
	 */
	public function getMaxReconnectCount(){
		return $this->max_reconnect_count;
	}

	/**
	 * Set the number of link retries
	 * @param int $max_reconnect_count
	 * @return \LFPhp\PORM\DB\DBDriver
	 */
	public function setMaxReconnectCount($max_reconnect_count){
		$this->max_reconnect_count = $max_reconnect_count;
		return $this;
	}

	/**
	 * Get the reconnection interval
	 * @return int milliseconds
	 */
	public function getReconnectInterval(){
		return $this->reconnect_interval;
	}

	/**
	 * Set the reconnection interval (milliseconds)
	 * @param int $reconnect_interval
	 * @return DBDriver
	 */
	public function setReconnectInterval($reconnect_interval){
		$this->reconnect_interval = $reconnect_interval;
		return $this;
	}

	/**
	 * Get the last inserted ID
	 * @param string $name
	 * @return string
	 */
	public function getLastInsertId($name = null){
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
	 * Start transaction operation
	 * @return bool
	 */
	public function beginTransaction(){
		return $this->conn->beginTransaction();
	}

	/**
	 * Rollback transaction
	 * @return bool
	 */
	public function rollback(){
		return $this->conn->rollBack();
	}

	/**
	 * Commit the transaction
	 * @return bool
	 */
	public function commit(){
		return $this->conn->commit();
	}

	/**
	 * Cancel the automatic commit state of the transaction
	 * @return bool
	 */
	public function cancelTransactionState(){
		return $this->conn->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
	}

	/**
	 * Data escape
	 * @param string $data
	 * @param string $type
	 * @return false|string
	 */
	public function quote($data, $type = null){
		if(is_array($data)){
			$data = join(',', $data);
		}
		$type = in_array($type, self::$PDO_TYPE_MAP) ? $type : PDO::PARAM_STR;
		return $this->conn->quote($data, $type);
	}

	/**
	 * Set SQL query limit information
	 * @param $sql
	 * @param $limit
	 * @return string
	 * @throws \LFPhp\PORM\Exception\DBException
	 */
	public function setLimit($sql, $limit){
		if(preg_match('/\sLIMIT\s/i', $sql)){
			throw new DBException('SQL LIMIT BEEN SET:'.$sql);
		}
		if(is_array($limit)){
			return $sql.' LIMIT '.$limit[0].','.$limit[1];
		}
		return $sql.' LIMIT '.$limit;
	}

	/**
	 * Get all rows
	 * @param PDOStatement $resource
	 * @return array
	 */
	public function fetchAll($resource){
		$resource->setFetchMode(PDO::FETCH_ASSOC);
		return $resource->fetchAll();
	}

	/**
	 * fetch one column
	 * @param PDOStatement $rs
	 * @return string
	 */
	public static function fetchColumn(PDOStatement $rs){
		return $rs->fetchColumn();
	}

	/**
	 * Query the number of rows affected by the recent db execution
	 * @description This method needs to be called with caution to avoid last_affect_num being overwritten
	 * @return integer
	 */
	public function getAffectNum(){
		return $this->last_affect_num;
	}

	/**
	 * Database data dictionary
	 * @return array
	 */
	public function getDictionary(){
		$tables = $this->getTables();
		foreach($tables as $k => $tbl_info){
			$fields = $this->getFields($tbl_info['table_name']);
			$tables[$k]['fields'] = $fields;
		}
		return $tables;
	}

	/**
	 * Get the database list
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
	 * Get a list of database tables
	 * @return array
	 */
	public function getTables(){
		$query = "SELECT `table_name` AS table_name, `engine` AS engine, `table_collation` AS table_collation, `table_comment` AS table_comment
					FROM `information_schema`.`tables`
					WHERE `table_schema`=?";
		$sth = $this->conn->prepare($query);
		$sth->execute([$this->dsn->database]);
		$tmp = $sth->fetchAll(PDO::FETCH_ASSOC) ?: [];
		//Different versions of MySQL may return uppercase field names, so they need to be converted once
		foreach($tmp as $k => $item){
			$tmp[$k] = array_change_key_case($item, CASE_LOWER);
		}
		return $tmp;
	}

	/**
	 * Get the database table field list
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
		//Different versions of MySQL may return uppercase field names, so they need to be converted once
		foreach($tmp as $k => $item){
			$tmp[$k] = array_change_key_case($item, CASE_LOWER);
		}
		return $tmp;
	}

	/**
	 * Parse SQL statements
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
	 * Set the query character set
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
	 * Set time zone
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
	 * Singleton
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
	 * Get the current deduplication query opening status
	 * @return bool
	 */
	public static function getQueryCacheState(){
		return self::$query_cache_on;
	}

	/**
	 * Enable query cache
	 */
	public static function setQueryCacheOn(){
		self::$query_cache_on = true;
	}

	/**
	 * Disable query cache
	 */
	public static function setQueryCacheOff(){
		self::$query_cache_on = false;
	}

	/**
	 * Query in non-deduplicate mode (forced query mode)
	 * @param callable $callback
	 */
	public static function noQueryCacheMode(callable $callback){
		$st = self::$query_cache_on;
		self::setQueryCacheOff();
		call_user_func($callback);
		self::$query_cache_on = $st;
	}

	/**
	 * Escape array
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
	 * Get a page of data
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
	 * Get all query records
	 * @param DBQuery|string $query
	 * @return array
	 * @throws \LFPhp\PORM\Exception\DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function getAll($query){
		return $this->getPage($query);
	}

	/**
	 * Get the specified table creation statement
	 * @param string $table
	 * @return string create table DSL
	 * @throws \LFPhp\PORM\Exception\DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function getDSLSchema($table){
		$ret = $this->getAll(new DBQuery("SHOW CREATE TABLE `$table`"));
		return $ret[0]['Create Table'];
	}

	/**
	 * Get a query record
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
	 * Get a field
	 * @param DBQuery|string $query
	 * @param string $key
	 * @return mixed|null
	 * @throws \LFPhp\PORM\Exception\DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function getField($query, $key = ''){
		$rst = $this->getOne($query);
		if($rst){
			return $key ? $rst[$key] : current($rst);
		}
		return null;
	}

	/**
	 * Update quantity
	 * @param string $table
	 * @param string $field
	 * @param integer $offset_count increment (real number)
	 * @return int the number of items affected by the update
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
	 * Data update
	 * @param string $table
	 * @param array $data
	 * @param string $condition
	 * @param int $limit The number of items affected by the update
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
	 * Insert data
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
	 * Delete database data
	 * @param $table
	 * @param $condition
	 * @param int $limit parameter is 0, which means no limit
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
	 * Data insertion
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
	 * Generate Query object
	 * @return DBQuery
	 */
	protected function genQuery(){
		return new DBQuery();
	}

	/**
	 * SQL query, support for reconnecting to database option
	 * @param DBQuery|string $query
	 * @return \PDOStatement
	 * @throws DBException
	 */
	final public function query($query){
		try{
			//Since PHP does not report Exception when querying the database,
			//Therefore, the false query result will not be packaged as an Exception, but the error event will continue to be triggered.
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
	 * Get the number of entries
	 * @param DBQuery|string $query
	 * @return int
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function getCount($query){
		$query .= '';
		$query = trim(trim($query), ';');

		//For insufficient recognition of order by, https://github.com/greenlion/PHP-SQL-Parser will be introduced for further processing
		//In order to avoid the field appearing in order, it is defined in select, but deleted in select, resulting in undefined field in order.
		//At the same time improve Count performance
		//$query = preg_replace('/\sORDER\s+BY\s.*$/i', '', $query);

		if(preg_match('/^\s*SELECT.*?\s+FROM\s+/is', $query)){
			if(preg_match('/\sGROUP\s+by\s/im', $query) || preg_match('/^\s*SELECT\s+DISTINCT\s/im', $query) || preg_match('/\sLIMIT\s/im', $query)){
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
	 * Like operation statement escape
	 * @param $statement
	 * @param string $escape_char
	 * @return string
	 */
	public function quoteLike($statement, $escape_char = '\\'){
		return $this->quote($statement)." ESCAPE '$escape_char'";
	}
}
