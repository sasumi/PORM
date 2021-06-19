<?php

use LFPhp\PORM\Driver\DBInstance;
use LFPhp\PORM\Exception\DBException as ExceptionAlias;
use LFPhp\PORM\Misc\DBConfig;
use LFPhp\PORM\DBQuery;
use PHPUnit\Framework\TestCase;
use function LFPhp\Func\dump;

class OrmTest extends TestCase {
	private function getConfig(){
		$cfg = DBConfig::createMySQLConfig('localhost', 'root', '123456', 'zardem');
		return $cfg;
	}

	public function testConnect(){
		try {
			$config = $this->getConfig();
			$ins = DBInstance::instance($config);
			$query = (new DBQuery())->select()->field('id', 'title')->from('blog_article');
			$ret = $ins->getPage($query);
			$count = $ins->getCount($query);
			dump($ret, $count, 1);
		} catch (ExceptionAlias $e){
			dump($e, 1);
		}
	}
}