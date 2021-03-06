<?php

namespace LFPhp\PORM\Exception;

use Throwable;

class QueryException extends DBException {
	public function __construct($message = "", $code = 0, Throwable $previous = null, $query = null, $dsn = null){
		parent::__construct($message, $code, $previous, $query, $dsn);
	}
}