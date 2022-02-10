<?php
namespace LFPhp\PORM\ORM;

use LFPhp\Logger\Logger;
use ReflectionClass;
use function LFPhp\Func\explode_by;

/**
 * 类库注解解析
 * @package LFPhp\PORM\Misc
 */
trait AttributeAnnotation {
	public static function getAttributes(){
		$class = get_called_class();
		$rc = new ReflectionClass($class);
		$doc = $rc->getDocComment();
		return self::_parseClassAnnotation($doc);
	}

	private static function _parseClassAnnotation($doc){
		$lines = explode_by("\n", $doc);
		$attrs = [];
		$first_pk = null;
		foreach($lines as $line){
			$line = trim($line, '* ');
			$seg = explode_by(' ', $line);
			list($flag, $type, $name, $description) = $seg;
			$type = strtolower($type);
			if(preg_match('/@property/i', $flag) && in_array($type, Attribute::TYPE_MAPS) && preg_match('/\\$?\w+/', $name)){
				$attr = new Attribute();
				$attr->name = trim($name, '$');
				$attr->is_readonly = stripos($flag, 'property-read') !== false;
				$attr->type = $type;
				$attr->alias = $description;
				if($attr->is_readonly && !$first_pk){ //set first readonly field as primary key
					$attr->is_primary_key = true;
					$first_pk = true;
				}
				$attrs[] = $attr;
			} else {
				Logger::info('Attr annotation no match:'.$line);
			}
		}
		return $attrs;
	}
}