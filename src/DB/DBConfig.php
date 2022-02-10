<?php

namespace LFPhp\PORM\DB;

use LFPhp\PDODSN\Database\Firebird;
use LFPhp\PDODSN\Database\Informix;
use LFPhp\PDODSN\Database\MySQL;
use LFPhp\PDODSN\Database\ODBC;
use LFPhp\PDODSN\Database\PostgreSQL;
use LFPhp\PDODSN\Database\SQLite;
use LFPhp\PDODSN\Database\SQLServer;
use LFPhp\PORM\Exception\Exception;

/**
 * 数据库配置对象
 */
class DBConfig {
	/** @var Firebird|Informix|MySQL|ODBC|PostgreSQL|SQLite|SQLServer */
	public $dsn;

	const TYPE_MYSQL = 'mysql';
	const TYPE_SQLITE = 'sqlite';
	const TYPE_MSSQL = 'sqlsrv';

	const TYPE_CLASS_MAP = [
		self::TYPE_MYSQL  => MySQL::class,
		self::TYPE_SQLITE => SQLite::class,
		self::TYPE_MSSQL  => SQLite::class,
	];

	const DEFAULT_TYPE = self::TYPE_MYSQL;

	public $table_prefix = '';
	public $user;
	public $password = '';
	public $timezone = null;
	public $strict_mode = false;
	public $persist = false;
	public $connect_timeout = null;
	public $query_timeout = null;

	private function __construct(){
	}

	/**
	 * @param array $cfg
	 * host
	 * port
	 * database
	 * user
	 * password
	 * timezone
	 * strict_mode
	 * persist
	 * connect_timeout
	 * query_timeout
	 * @return self
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public static function fromConfig(array $cfg){
		$cfg_obj = new self();

		$type = isset($cfg['type']) ? $cfg['type'] : self::DEFAULT_TYPE;
		if(!isset(self::TYPE_CLASS_MAP[$type])){
			throw new Exception('type no supported:'.$type);
		}
		if(isset($cfg['user'])){
			$cfg_obj->user = $cfg['user'];
		}
		if(isset($cfg['password'])){
			$cfg_obj->password = $cfg['password'];
		}
		if(isset($cfg['timezone'])){
			$cfg_obj->timezone = $cfg['timezone'];
		}
		if(isset($cfg['strict_mode'])){
			$cfg_obj->strict_mode = $cfg['strict_mode'];
		}
		if(isset($cfg['persist'])){
			$cfg_obj->persist = $cfg['persist'];
		}
		if(isset($cfg['connect_timeout'])){
			$cfg_obj->connect_timeout = $cfg['connect_timeout'];
		}
		if(isset($cfg['query_timeout'])){
			$cfg_obj->query_timeout = $cfg['query_timeout'];
		}

		$class = isset(self::TYPE_CLASS_MAP[$type]) ? self::TYPE_CLASS_MAP[$type] : null;
		switch($class){
			case MySQL::class:
				$dsn = new MySQL();
				$dsn->host = $cfg['host'];
				$dsn->database = $cfg['database'];
				$dsn->port = $cfg['port'];
				$dsn->user = $cfg_obj->user;
				$dsn->password = $cfg_obj->password;
				$dsn->charset = $cfg['charset'];
				$cfg_obj->dsn = $dsn;
				break;
			default:
				throw new Exception('No support yet');
		}
		return $cfg_obj;
	}

	/**
	 * create mysql config
	 * @param string $host
	 * @param string $user
	 * @param string $password
	 * @param string $database
	 * @param int $port
	 * @return \LFPhp\PORM\DB\DBConfig
	 */
	public static function createMySQLConfig($host, $user, $password, $database, $port = 3306){
		return self::fromConfig([
			'type'     => self::TYPE_MYSQL,
			'host'     => $host,
			'database' => $database,
			'user'     => $user,
			'password' => $password,
			'port'     => $port,
		]);
	}

	public function __toString(){
		return $this->dsn->__toString();
	}
}