<?php

use LFPhp\PORM\ORM\Model;

class TestModel extends Model {
	static public function getTableName(){
		return 'information_schema';
	}

	static public function getDbDsn($operate_type = self::OP_READ){
		$mysql_connect = new LFPhp\PDODSN\Database\MySQL();
		$mysql_connect->host = 'localhost';
		$mysql_connect->user = 'root';
		$mysql_connect->password = '123456';
		return $mysql_connect;
	}
}
