<?php
namespace LFPhp\PORM\Exception;

use LFPhp\PORM\Database\DBConfig;
use Throwable;

class DBException extends Exception {
	protected $db_config;

	public function __construct($message = "", $code = 0, Throwable $previous = null, $data = null, DBConfig $db_config = null){
		$this->db_config = $db_config;
		parent::__construct($message, $code, $previous, $data);
	}

	public function __debugInfo(){
		$debug = parent::__debugInfo();
		$debug['db_config'] = $this->db_config;
		return $debug;
	}
}