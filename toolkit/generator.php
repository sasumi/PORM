<?php
namespace LFPhp\PORM\toolkit;

use LFPhp\PDODSN\DSN;

/**
 * @todo
 */

$opts = getopt('p::d:t::p');
$path = $opts['p'];
$dsn = $opts['d'];
$table = $opts['t'];
$template = $opts['p'];

$help = '
[Generate model]
-o=PATH Set file save path
-d=mysql:localhost DSN
-t=table Specify table name, default for all tables
-p=Template file name 
';

if(!$path || !$dsn){
	die($help);
}
