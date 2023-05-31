<?php
namespace LFPhp\PORM\tests;

use LFPhp\PDODSN\Database\MySQL;
use LFPhp\PORM\ORM\DSLHelper;
use function LFPhp\Func\dump;

include_once "autoload.inc.php";

$dsn = new MySQL();
$dsn->host = '';
$dsn->user = '';
$dsn->password = '';
$dsn->database = '';

const TEST_TABLE = 'table_log';

$dsl = DSLHelper::getTableDSLByDSN($dsn, TEST_TABLE);
dump($dsl);

list($table, $table_desc, $attributes) = DSLHelper::resolveDSL($dsl);
dump($table, $table_desc);

$str = DSLHelper::buildTemplate($table, $table_desc, $attributes);
file_put_contents(__DIR__."/$table.php", $str);
dump(__DIR__."/$table.php", 1);
