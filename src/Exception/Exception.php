<?php
namespace LFPhp\PORM\Exception;

use Throwable;

class Exception extends \Exception {
	protected $data;

	public function __construct($message = "", $code = 0, Throwable $previous = null, $data = null){
		$this->data = $data;
		parent::__construct($message, $code, $previous);
	}

	public function __debugInfo(){
		return [
			'message' => $this->message,
			'code'    => $this->code,
			'file'    => $this->file.'#'.$this->line,
			'data'    => $this->data,
			'trace'   => $this->getTrace(),
		];
	}
}