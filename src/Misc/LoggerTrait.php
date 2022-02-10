<?php

namespace LFPhp\PORM\Misc;

use LFPhp\Logger\Logger;

trait LoggerTrait {
	/** @var Logger|null */
	public static $__logger;

	public static function setLogger(Logger $__logger){
		static::$__logger = $__logger;
	}

	public static function getLogger(){
		if(!static::$__logger){
			static::$__logger = new Logger(static::class);
		}
		return static::$__logger;
	}
}