<?php

use LFPhp\PORM\Driver\DBAbstract;
use LFPhp\PORM\Exception\DBException as ExceptionAlias;
use LFPhp\PORM\Misc\DBConfig;
use LFPhp\PORM\Query;
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
			$ins = DBAbstract::instance($config);
			$query = (new Query())->select()->field('id', 'title')->from('blog_article');
			$ret = $ins->getPage($query);
			$count = $ins->getCount($query);
			dump($ret, $count, 1);
		} catch (ExceptionAlias $e){
			dump($e, 1);
		}
	}
}