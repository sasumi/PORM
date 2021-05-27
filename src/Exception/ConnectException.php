<?php

namespace LFPhp\PORM\Exception;

use LFPhp\PORM\Misc\DBConfig;
use Throwable;

class ConnectException extends Exception {
	private $config;

	public function __construct($message = "", $code = 0, Throwable $previous = null, DBConfig $config = null){
		$this->config = $config;
		parent::__construct($message, $code, $previous);
	}
}