<?php
namespace LFPhp\PORM\tests;

use function LFPhp\Func\dump;

include_once "autoload.inc.php";

$info = ini_get_all();

dump($info, 1);