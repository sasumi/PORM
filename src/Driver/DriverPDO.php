<?php
namespace LFPhp\PORM\Driver;

use Exception;
use LFPhp\PORM\Exception\ConnectException;
use LFPhp\PORM\Exception\DBException;
use PDO;
use PDOException;
use PDOStatement;
use function LFPhp\Func\get_max_socket_timeout;
use function LFPhp\Func\server_in_windows;

/**
 *
 * Database operate class
 * this class no care current operate database read or write able
 * u should check this yourself
 *
 * 当前类不关注调用方的操作是读操作还是写入操作，
 * 这部分选择有调用方自己选择提供不同的初始化config配置
 */
class DriverPDO extends DBInstance {
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
	 * @param \LFPhp\PORM\Driver\DBConfig $db_config
	 * @param bool $re_connect
	 * @return \PDO
	 * @throws \LFPhp\PORM\Exception\ConnectException
	 * @throws \LFPhp\PORM\Exception\DBException
	 */
	public function connect(DBConfig $db_config, $re_connect = false) {
		if(!$re_connect && $this->conn){
			return $this->conn;
		}

		//build in connect attribute
		$opt = [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		];

		//最大超时时间
		$max_connect_timeout = isset($db_config->connect_timeout) ? $db_config->connect_timeout : get_max_socket_timeout(2);

		if($max_connect_timeout){
			$opt[PDO::ATTR_TIMEOUT] = $max_connect_timeout;
		}

		if($db_config->persist){
			$opt[PDO::ATTR_PERSISTENT] = true;
		}

		//connect & process windows encode issue
		try{
			$conn = new PDO($db_config->toDSNString(), $db_config->user, $db_config->password, $opt);
		}catch(PDOException $e){
			$err = server_in_windows() ? mb_convert_encoding($e->getMessage(), 'utf-8', 'gb2312') : $e->getMessage();
			$db_config->password = $db_config->password ? '******' : 'no password';
			throw new ConnectException("Database connect failed:{$err}", $e->getCode(), $e, $db_config);
		}
		$this->conn = $conn;
		$this->toggleStrictMode(isset($db_config->strict_mode) ? !!$db_config->strict_mode : false);
		return $conn;
	}

	public function setCharset($charset){
		$type = $this->db_config->type;
		if($type == DBConfig::TYPE_MYSQL){
			if(stripos($charset, 'utf-') === 0){
				return str_replace('-', '', $charset);
			}
		} else if(preg_match('/^utf\d/i', $charset, $matches)){
			return preg_replace('/^utf(\d)/', 'UTF-$2', $charset);
		}
		return $charset;
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
		return parent::isConnectionLost($exception);
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
		$sth->execute([$this->db_config->database]);
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
		$db = $this->db_config->database;
		$sth->execute([$db, $table]);
		return $sth->fetchAll(PDO::FETCH_ASSOC);
	}
}
