<?php
namespace LFPhp\PORM\tests;
use LFPhp\PORM\DB\DBConfig;
use LFPhp\PORM\DB\DBDriver;
use LFPhp\PORM\DB\DBQuery;
use LFPhp\PORM\Exception\DBException as ExceptionAlias;
use PHPUnit\Framework\TestCase;
use function LFPhp\Func\dump;

class QueryTest extends TestCase {
	private function getConfig(){
		$cfg = DBConfig::createMySQLConfig('localhost', 'root', '123456', 'zardem');
		return $cfg;
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