<?php
namespace LFPhp\PORM\tests;
use LFPhp\PORM\Database\DBConfig;
use LFPhp\PORM\ORM\DBAttribute;
use LFPhp\PORM\ORM\DBModel;
use LFPhp\PORM\ORM\TableAnnotation;

/**
 * Class UserTable
 * @package LFPhp\PORM\tests
 * @property-read int $id
 * @property string $code 应用的编码
 * @property string $name 名称
 * @property int $max_thread_cnt 控制最大线程数
 * @property int $current_thread_cnt 当前线程数
 * @property double $current_memory_size 当前进程内容
 * @property string $last_heart_time 最近一次心跳时间
 * @property mixed $status 状态
 * @property mixed $type job类型
 */
class UserTable extends DBModel {
	use TableAnnotation;
	public static function getTableName(){
		return 'blog_test';
	}

	public static function getAttributes(){
		return [
			new DBAttribute(['name'=>'hello', 'type'=>DBAttribute::TYPE_STRING]),
			new DBAttribute(['name'=>'hello', 'type'=>DBAttribute::TYPE_STRING]),
			new DBAttribute(['name'=>'hello', 'type'=>DBAttribute::TYPE_STRING]),
			new DBAttribute(['name'=>'hello', 'type'=>DBAttribute::TYPE_STRING]),
			new DBAttribute(['name'=>'hello', 'type'=>DBAttribute::TYPE_STRING]),
		];
	}

	/**
	 * @param int $operate_type
	 * @return \LFPhp\PORM\Database\DBConfig
	 * @throws \LFPhp\PORM\Exception\DBException
	 */
	static protected function getDBConfig($operate_type = self::OP_READ){
		return DBConfig::createMySQLConfig('localhost', 'root', '123456', 'zardem');
	}
}