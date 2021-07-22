<?php

namespace LFPhp\PORM\Database;

use LFPhp\PDODSN\Database\Firebird;
use LFPhp\PDODSN\Database\Informix;
use LFPhp\PDODSN\Database\MySQL;
use LFPhp\PDODSN\Database\ODBC;
use LFPhp\PDODSN\Database\PostgreSQL;
use LFPhp\PDODSN\Database\SQLite;
use LFPhp\PDODSN\Database\SQLServer;

/**
 * 数据库配置对象
 */
class DBConfig {
	/** @var Firebird|Informix|MySQL|ODBC|PostgreSQL|SQLite|SQLServer */
	public $dsn;

	public $table_prefix = '';
	public $user;
	public $password = '';
	public $timezone = null;
	public $strict_mode = false;
	public $persist = false;
	public $connect_timeout = null;
	public $query_timeout = null;

	private function __construct(){}

	/**
	 * create mysql config
	 * @param string $host
	 * @param string $user
	 * @param string $password
	 * @param string $database
	 * @param int $port
	 * @return \LFPhp\PORM\Database\DBConfig
	 */
	public static function createMySQLConfig($host, $user, $password, $database, $port = 3306){
		$dsn = new MySQL();
		$dsn->host = $host;
		$dsn->user = $user;
		$dsn->password = $password;
		$dsn->database = $database;
		$dsn->port = $port;

		$ins = new self();
		$ins->dsn = $dsn;
		return $ins;
	}

	public function __toString(){
		return $this->dsn->__toString();
	}
}