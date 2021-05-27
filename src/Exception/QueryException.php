<?php

namespace LFPhp\PORM\Exception;

use Throwable;

class QueryException extends Exception {
	private $query;
	private $config;

	public function __construct($query, $message = "", $code = 0, Throwable $previous = null, $config = null){
		$this->query = $query;
		$this->config = $config;
		parent::__construct($message, $code, $previous);
	}
}