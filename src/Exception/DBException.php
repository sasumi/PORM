<?php
namespace LFPhp\PORM\Exception;

use LFPhp\PDODSN\DSN;
use Throwable;

class DBException extends Exception {
	protected $dsn;

	public function __construct($message = "", $code = 0, Throwable $previous = null, $data = null, DSN $dsn = null){
		$this->dsn = $dsn;
		parent::__construct($message, $code, $previous, $data);
	}

	public function __debugInfo(){
		$debug = parent::__debugInfo();
		$debug['dsn'] = $this->dsn;
		return $debug;
	}
}