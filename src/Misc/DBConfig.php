<?php

namespace LFPhp\PORM\Misc;

use LFPhp\PORM\Exception\Exception;

/**
 * 数据库配置对象
 */
class DBConfig {
	const TYPE_MYSQL = 'mysql';
	const TYPE_SQLITE = 'sqlite';

	const DRIVER_MYSQLI = 'mysqli';
	const DRIVER_SQLITE = 'sqlite';
	const DRIVER_PDO = 'pdo';

	const DEFAULT_CHARSET = 'UTF-8';

	public $type;
	public $driver;
	public $host;
	public $database;
	public $table_prefix = '';
	public $user;
	public $password = '';
	public $port = null;
	public $strict_mode = false;
	public $charset = self::DEFAULT_CHARSET;
	public $persist = false;
	public $connect_timeout = null;
	public $query_timeout = null;

	private function __construct(){
	}

	public static function createFromConfig(array $config){
		$ins = new self();
		$ins->type = $config['type'] ?? self::TYPE_MYSQL;
		$ins->driver = $config['driver'] ?? self::detectDriver($ins->type);
		$ins->host = $config['host'] ?? null;
		$ins->database = $config['database'] ?? null;
		$ins->user = $config['user'] ?? null;
		$ins->password = $config['password'] ?? null;
		$ins->port = $config['port'] ?? null;
		$ins->charset = $config['charset'] ?? self::DEFAULT_CHARSET;
		$ins->persist = $config['persist'] ?? false;
		$ins->connect_timeout = $config['connect_timeout'] ?? null;
		$ins->query_timeout = $config['query_timeout'] ?? null;
		return $ins;
	}

	public static function createMySQLConfig($host, $user, $password, $port = 3306){
		$ins = new self();
		$ins->type = self::TYPE_MYSQL;
		$ins->driver = self::detectDriver(self::TYPE_MYSQL);
		$ins->host = $host;
		$ins->user = $user;
		$ins->password = $password;
		$ins->port = $port;
	}

	/**
	 * 侦测PHP扩展，获取使用的数据库驱动
	 * 优先使用PDO扩展
	 * @param $type
	 * @return string
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	private static function detectDriver($type){
		$extensions = get_loaded_extensions();
		switch($type){
			case self::TYPE_MYSQL:
				if(in_array('pdo_mysql', $extensions)){
					return self::DRIVER_PDO;
				}
				if(in_array('mysqli', $extensions)){
					return self::DRIVER_MYSQLI;
				}
				break;
			case self::TYPE_SQLITE:
				if(in_array('pdo_sqlite', $extensions)){
					return self::DRIVER_PDO;
				}
				if(in_array('sqlite3', $extensions)){
					return self::DRIVER_SQLITE;
				}
				break;
		}
		throw new Exception('No driver found for:'.$type);
	}

	public function toDSNString(){
		switch($this->type){
			case self::TYPE_MYSQL:
				$connect = ["mysql:dbname={$this->database}", "host={$this->host}"];
				if($this->port){
					$connect[] = "port={$this->port}";
				}
				if($this->charset){
					$connect[] = "charset={$this->charset}";
				}
				if($this->password){
					$connect[] = "password={$this->password}";
				}
				return join(';', $connect);

			case self::TYPE_SQLITE:
			default:
				throw new Exception('no support now');
		}
	}

	public function __toString(){
		return $this->toDSNString();
	}
}