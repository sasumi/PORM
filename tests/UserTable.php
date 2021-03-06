<?php
namespace LFPhp\PORM\tests;
use LFPhp\PDODSN\Database\MySQL;
use LFPhp\PORM\ORM\Attribute;
use LFPhp\PORM\ORM\Model;

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
class UserTable extends Model {
	public static function getTableName(){
		return 'blog_test';
	}

	public static function getAttributes(){
		return [
			new Attribute(['name' =>'hello', 'type' =>Attribute::TYPE_STRING]),
			new Attribute(['name' =>'hello', 'type' =>Attribute::TYPE_STRING]),
			new Attribute(['name' =>'hello', 'type' =>Attribute::TYPE_STRING]),
			new Attribute(['name' =>'hello', 'type' =>Attribute::TYPE_STRING]),
			new Attribute(['name' =>'hello', 'type' =>Attribute::TYPE_STRING]),
		];
	}

	/**
	 * @param int $operate_type
	 * @return \LFPhp\PDODSN\Database\MySQL
	 * @throws \Exception
	 */
	static public function getDbDsn($operate_type = self::OP_READ){
		return new MySQL([
			'host'=>'localhost',
			'user'=>'root',
			'password'=>'123456',
			'database'=>'zardem'
		]);
	}
}