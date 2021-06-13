<?php
namespace LFPhp\PORM\toolkit;

$opts = getopt('p::d:');
$path = $opts['p'];
$dsn = $opts['d'];

$help = '
[Generate model]
-o=PATH Set file save path
-d=mysql:localhost DSN
-m=table Specify table name, default for all tables
-o=
';

if(!$path || !$dsn){
	die($help);
}