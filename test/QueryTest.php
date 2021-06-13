<?php

use LFPhp\PORM\Driver\DBAbstract;
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
		$config = $this->getConfig();
		$ins = DBAbstract::instance($config);
		$query = (new Query())->select()->from('t_tag');
		$ret = $ins->query($query);
		dump($ret, 1);
	}
}