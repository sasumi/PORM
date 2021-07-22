<?php
namespace LFPhp\PORM\tests;
use LFPhp\PORM\Database\DBConfig;
use LFPhp\PORM\Database\DBDriver;
use LFPhp\PORM\Database\DBQuery;
use LFPhp\PORM\Exception\DBException as ExceptionAlias;
use PHPUnit\Framework\TestCase;
use function LFPhp\Func\dump;

include_once "../vendor/autoload.php";
include_once "./UserTable.php";
class OrmTest extends TestCase {
	private function getConfig(){
		return DBConfig::createMySQLConfig('localhost', 'root', '123456', 'zardem');
	}

	public function testConnect(){
		try {
			$config = $this->getConfig();
			$ins = DBDriver::instance($config);
			$query = (new DBQuery())->select()->field('id', 'title')->from('blog_test');
			$ret = $ins->getPage($query);
			$count = $ins->getCount($query);
			dump($ret, $count, 1);
		} catch (ExceptionAlias $e){
			dump($e, 1);
		}
	}

	public function testUser(){
		$user = UserTable::findOneByPk(1);
		dump($user, 1);
	}
}