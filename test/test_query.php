<?php
namespace LFPhp\PORM\tests;

use LFPhp\PDODSN\Database\MySQL;
use LFPhp\PORM\DB\DBDriver;
use LFPhp\PORM\DB\DBQuery;
use function LFPhp\Func\dump;

include_once "autoload.inc.php";

$dsn = new MySQL();
$dsn->host = 'localhost';
$dsn->user = 'root';
$dsn->password = '123456';
$dsn->port = '3308';
$dsn->database = 'ucbi';
$sql = "SELECT * FROM operation_log order by id desc limit 10";

$driver = DBDriver::instance($dsn);
$data = $driver->getPage(new DBQuery($sql), [0,5]);
$data = $driver->getPage(new DBQuery($sql), 11);
$data = $driver->getPage(new DBQuery($sql), [5,2]);
$data = $driver->getPage(new DBQuery($sql), [5,10]);

function console_print_table($list, $column_width = []){
	if(!$column_width){
		$column_width = [];
		foreach($list as $item){
			$col_idx = -1;
			foreach($item as $val){
				$col_idx++;
				$size = 0;
				if(is_string($val) || is_numeric($val)){
					$size = strlen($val.'');
				}
				else if(is_null($val)){
					$size = 4; //NULL
				}
				else if($val === true){
					$size = 4; //true
				}
				else if($val === false){
					$size = 5;//false
				}
				else if(is_object($val) || is_array($val)){
					$size = strlen(json_encode($val, JSON_UNESCAPED_UNICODE));
				} else {
					//default size = 0;
				}
				if(!isset($column_width[$col_idx])){
					$column_width[$col_idx] = $size;
				} else {
					$column_width[$col_idx] = max($column_width[$col_idx], $size);
				}
			}
		}
	}
}

console_print_table($data);
dump($data, 1);