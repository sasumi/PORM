<?php
namespace LFPhp\PORM\Driver;

use LFPhp\PORM\Exception\ConnectException;
use LFPhp\PORM\Exception\DBException;
use LFPhp\PORM\Misc\DBConfig;
use mysqli_result;
use function LFPhp\Func\get_max_socket_timeout;
use function LFPhp\Func\server_in_windows;

/**
 * MySQLi驱动类
 * @package LFPhp\PORM\Driver
 */
class DriverMySQLi extends DBAbstract{
	/** @var \mysqli $conn */
	private $conn;

	protected function dbQuery($query){
		return $this->conn->query($query.'');
	}

	public function getAffectNum(){
		return $this->conn->affected_rows;
	}

	/**
	 * @param mysqli_result $resource
	 * @return array
	 */
	public function fetchAll($resource){
		return $resource->fetch_all(MYSQLI_ASSOC);
	}

	/**
	 * Set charset
	 * @param $charset
	 * @return \LFPhp\PORM\Driver\DriverMySQLi
	 * @throws \LFPhp\PORM\Exception\DBException
	 */
	public function setCharset($charset){
		if(stripos($charset, 'utf-') === 0){
			$charset = str_replace('-', '', $charset);
		}
		return parent::setCharset($charset);
	}

	public function setLimit($sql, $limit){
		if(preg_match('/\sLIMIT\s/i', $sql)){
			throw new DBException('SQL LIMIT BEEN SET:' . $sql);
		}
		if(is_array($limit)){
			return $sql . ' LIMIT ' . $limit[0] . ',' . $limit[1];
		}
		return $sql . ' LIMIT ' . $limit;
	}

	public function getLastInsertId(){
		return $this->conn->insert_id;
	}

	public function commit(){
		return $this->conn->commit();
	}

	public function rollback(){
		return $this->conn->rollback();
	}

	public function beginTransaction(){
		$this->conn->autocommit(false);
	}

	public function cancelTransactionState(){
		$this->conn->autocommit(true);
	}

	/**
	 * connect to specified config database
	 * @param \LFPhp\PORM\Misc\DBConfig $db_config
	 * @param boolean $re_connect 是否重新连接
	 * @return \mysqli
	 * @throws \LFPhp\PORM\Exception\ConnectException
	 */
	public function connect(DBConfig $db_config, $re_connect = false){
		$connection = mysqli_init();

		//最大超时时间
		$max_connect_timeout = isset($db_config->connect_timeout) ? $db_config->connect_timeout : get_max_socket_timeout(2);

		if($max_connect_timeout){
			mysqli_options($connection, MYSQLI_OPT_CONNECT_TIMEOUT, $max_connect_timeout);
		}

		//通过mysqli error方式获取数据库连接错误信息，转接到Exception
		$ret = @mysqli_real_connect($connection, $db_config->host, $db_config->user, $db_config->password, $db_config->database, $db_config->port);
		if(!$ret){
			$code = mysqli_connect_errno();
			$error = mysqli_connect_error();
			if(server_in_windows()){
				$error = mb_convert_encoding($error, 'utf-8', 'gb2312');
			}
			$db_config['password'] = $db_config['password'] ? '******' : 'no using password';
			throw new ConnectException("Database connect failed:{$error}, HOST：{$db_config->host}", $code, null, $db_config);
		}
		$this->conn = $connection;
		return $connection;
	}
}
