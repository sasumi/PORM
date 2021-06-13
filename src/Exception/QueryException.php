<?php

namespace LFPhp\PORM\Exception;

use Throwable;

class QueryException extends Exception {
	private $query;

	public function __construct($query, $message = "", $code = 0, Throwable $previous = null, $config = null){
		$this->query = $query;
		parent::__construct($message, $code, $previous, $config);
	}

	public function __debugInfo(){
		$info = parent::__debugInfo();
		return array_merge(['query'=>$this->query.''], $info);
	}
}