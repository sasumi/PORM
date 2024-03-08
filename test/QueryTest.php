<?php
namespace LFPhp\PORM\tests;
use LFPhp\PDODSN\Database\MySQL;
use LFPhp\PORM\DB\DBDriver;
use LFPhp\PORM\DB\DBQuery;
use LFPhp\PORM\Exception\DBException as ExceptionAlias;
use PHPUnit\Framework\TestCase;
use function LFPhp\Func\dump;

class QueryTest extends TestCase {
	private function getConfig(){
		return new MySQL([
			'host'=>'localhost',
			'user'=>'root',
			'password'=>'123456',
			'database'=>'zardem'
		]);
	}

	public function testConnect(){
		try {
			$config = $this->getConfig();
			$ins = DBDriver::instance($config);
			$query = (new DBQuery())->select()->field('id', 'title')->from('blog_article');
			$ret = $ins->getPage($query);
			$count = $ins->getCount($query);
			dump($ret, $count, 1);
		} catch (ExceptionAlias $e){
			dump($e, 1);
		}
	}
}