<?php

namespace LFPhp\PORM\Driver;

use LFPhp\PORM\Exception\DBException;

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
	public $timezone = null;
	public $strict_mode = false;
	public $charset = self::DEFAULT_CHARSET;
	public $persist = false;
	public $connect_timeout = null;
	public $query_timeout = null;

	private function __construct(){}

	/**
	 * @param array $config
	 * @return \LFPhp\PORM\Driver\DBConfig
	 * @throws \LFPhp\PORM\Exception\DBException
	 */
	public static function createFromConfig(array $config){
		$ins = new self();
		$ins->type = $config['type'] ?? self::TYPE_MYSQL;
		$ins->driver = $config['driver'] ?? self::detectDriver($ins->type);
		$ins->host = $config['host'];
		$ins->database = $config['database'];
		$ins->user = $config['user'];
		$ins->password = $config['password'] ?? null;
		$ins->port = $config['port'] ?? null;
		$ins->charset = $config['charset'] ?? self::DEFAULT_CHARSET;
		$ins->persist = $config['persist'] ?? false;
		$ins->connect_timeout = $config['connect_timeout'] ?? null;
		$ins->query_timeout = $config['query_timeout'] ?? null;
		return $ins;
	}

	/**
	 * create mysql config
	 * @param string $host
	 * @param string $user
	 * @param string $password
	 * @param string $database
	 * @param int $port
	 * @return \LFPhp\PORM\Driver\DBConfig
	 * @throws \LFPhp\PORM\Exception\DBException
	 */
	public static function createMySQLConfig($host, $user, $password, $database, $port = 3306){
		$ins = new self();
		$ins->type = self::TYPE_MYSQL;
		$ins->driver = self::detectDriver(self::TYPE_MYSQL);
		$ins->host = $host;
		$ins->user = $user;
		$ins->password = $password;
		$ins->database = $database;
		$ins->port = $port;
		return $ins;
	}

	/**
	 * 侦测PHP扩展，获取使用的数据库驱动
	 * 优先使用PDO扩展
	 * @param $type
	 * @return string
	 * @throws \LFPhp\PORM\Exception\DBException
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
		throw new DBException('No driver found for:'.$type);
	}

	/**
	 * fix charset for MySQL database or No-MySQL database
	 * @param $type
	 * @param string $charset
	 * @return string|string[]|null
	 */
	private static function fixCharset($type, string $charset){
		if($type == self::TYPE_MYSQL){
			if(stripos($charset, 'utf-') === 0){
				return str_replace('-', '', $charset);
			}
		} else if(preg_match('/^utf\d/i', $charset, $matches)){
			return preg_replace('/^utf(\d)/', 'UTF-$2', $charset);
		}
		return $charset;
	}

	/**
	 * 转化成DSN
	 * @return string
	 * @throws \LFPhp\PORM\Exception\DBException
	 */
	public function toDSNString(){
		switch($this->type){
			case self::TYPE_MYSQL:
				$connect = ["mysql:dbname={$this->database}", "host={$this->host}"];
				if($this->port){
					$connect[] = "port={$this->port}";
				}
				if($this->charset){
					$charset = self::fixCharset($this->type, $this->charset);
					$connect[] = "charset={$charset}";
				}
				if($this->password){
					$connect[] = "password={$this->password}";
				}
				return join(';', $connect);

			case self::TYPE_SQLITE:
			default:
				throw new DBException('no support now');
		}
	}

	public function __toString(){
		return $this->toDSNString();
	}
}