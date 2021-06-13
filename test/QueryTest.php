<?php

use LFPhp\PORM\Driver\DBAbstract;
use LFPhp\PORM\Exception\Exception as ExceptionAlias;
use LFPhp\PORM\Misc\DBConfig;
use LFPhp\PORM\Query;
use PHPUnit\Framework\TestCase;
use function LFPhp\Func\dump;

class QueryTest extends TestCase {
	private function getConfig(){
		$cfg = DBConfig::createMySQLConfig('localhost', 'root', '123456', 'sales');
		return $cfg;
	}

	public function testConnect(){
		try {
			$config = $this->getConfig();
			$ins = DBAbstract::instance($config);
			$query = (new Query())->select()->from('t_tag');
			$ret = $ins->query($query);
			$count = $ins->getCount($query);
			dump($ret, $count, 1);
		} catch (ExceptionAlias $e){
			dump($e, 1);
		}
	}
}