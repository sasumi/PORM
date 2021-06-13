<?php
namespace LFPhp\PORM\Exception;

use LFPhp\PORM\Misc\DBConfig;
use Throwable;

class Exception extends \Exception {
	protected $db_config;

	public function __construct($message = "", $code = 0, Throwable $previous = null, DBConfig $db_config = null){
		$this->db_config = $db_config;
		parent::__construct($message, $code, $previous);
	}

	public function __debugInfo(){
		return [
			'message' => $this->message,
			'code'    => $this->code,
			'file'    => $this->file.'#'.$this->line,
			'config'  => $this->db_config,
		];
	}
}