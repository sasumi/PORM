<?php

use LFPhp\PORM\Misc\DBConfig;
use LFPhp\PORM\DBModel;

class User extends DBModel {
	private function getConfig(){
		return DBConfig::createMySQLConfig('localhost', 'root', '123456', 'zardem');
	}

	public function testConnect(){
	}

	public static function getTableName(){
		return 'blog_article';
	}

	static protected function getDBConfig($operate_type = self::OP_READ){
		// TODO: Implement getDBConfig() method.
	}
}