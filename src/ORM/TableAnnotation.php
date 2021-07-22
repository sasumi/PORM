<?php
namespace LFPhp\PORM\ORM;

use LFPhp\Cache\CacheFile;
use LFPhp\PORM\DB\DBConfig;
use LFPhp\PORM\Exception\Exception;
use function LFPhp\Func\explode_by;

/**
 * 表注解解析
 * @package LFPhp\PORM\Misc
 */
trait TableAnnotation {
	protected static $_cache_dir_name = '_table_annotation';
	public static function getAttributes(){
		$table = static::getTableName();
		$obj = static::setQuery("SHOW CREATE TABLE `$table`");
		$ret = $obj->all(true);
		$sql = $ret[0]['Create Table'];

		/** @var DBConfig $cfg */
		$cfg = static::getDBConfig();
		$key = md5($cfg).'_'.$table;
		return CacheFile::instance(['dir'=>sys_get_temp_dir().'/'.static::$_cache_dir_name])
			->cache($key, function()use($sql){
			return self::__createSqlResolve($sql);
		});
	}

	/**
	 * @param $sql
	 * @return array
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public static function __createSqlResolve($sql){
		$lines = explode_by("\n", $sql);
		$attrs = [];
		foreach($lines as $line){
			if(preg_match('/^`(\w+)`\s+(.*?)\s+(.*),?$/', $line, $matches)){
				$name = $matches[1];
				$left = $matches[3];
				list($type, $len, $opts) = self::__resolveTypes($matches[2], $left);
				$attr = new Attribute();
				$attr->name = $name;
				$attr->type = $type;
				$attr->length = $len;
				$attr->options = $opts;
				$attrs[] = $attr;
			}
		}
		return $attrs;
	}

	private static function __resolveTypes($type_def, $tail_sql){
		$type_map = [
			'varchar'    => [Attribute::TYPE_STRING, true],
			'char'       => [Attribute::TYPE_STRING, true],
			'longtext'   => [Attribute::TYPE_STRING, true, 4294967295],
			'mediumtext' => [Attribute::TYPE_STRING, true, 16777215],
			'text'       => [Attribute::TYPE_STRING, true, 65535],
			'tinytext'   => [Attribute::TYPE_STRING, true, 255],

			'tinyint'   => [Attribute::TYPE_INT, true],
			'smallint'  => [Attribute::TYPE_INT, true],
			'int'       => [Attribute::TYPE_INT, true],
			'mediumint' => [Attribute::TYPE_INT, true],
			'bigint'    => [Attribute::TYPE_INT, true],

			'decimal' => [Attribute::TYPE_DECIMAL, true],
			'float'   => [Attribute::TYPE_FLOAT, true],
			'double'  => [Attribute::TYPE_DOUBLE, true],
			'set'     => [Attribute::TYPE_SET, false],

			'datetime'  => [Attribute::TYPE_DATETIME, false],
			'date'      => [Attribute::TYPE_DATE, false],
			'time'      => [Attribute::TYPE_TIME, false],
			'year'      => [Attribute::TYPE_YEAR, false],
			'timestamp' => [Attribute::TYPE_TIMESTAMP, false],
			'enum'      => [Attribute::TYPE_ENUM, false],
		];

		if(preg_match('/(\w+)\(([^)]+)\)/', $type_def, $matches) || preg_match('/(\w+)\s*$/', $type_def, $matches)){
			$ts = $matches[1];
			$val = $matches[2];
			if(isset($type_map[$ts])){
				list($type, $is_scalar, $def_len) = $type_map[$ts];

				//scalar
				if($is_scalar){
					return [$type, (int)$val ?: $def_len];
				}
				//enum、set
				else if($type == Attribute::TYPE_ENUM || $type == Attribute::TYPE_SET){
					$keys = explode_by(',', str_replace("'", '', $val));

					//必须匹配 {NAME}(MARK1, MARK2)
					if(preg_match('/\sCOMMENT\s\'(.*?)\(([^)]+)\)\'/', $tail_sql, $ms)){
						$remarks = explode_by(',', $ms[2]);
						if(count($remarks) == count($keys)){
							return [Attribute::TYPE_ENUM, null, array_combine($keys, $remarks)];
						}
					} else {
						return [Attribute::TYPE_ENUM, null, array_combine($keys, $keys)];
					}
				}
				//time
				else{
					return [$type, null];
				}
			}
		}
		throw new Exception('type resolve fail:'.$type_def);
	}
}