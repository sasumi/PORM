<?php
namespace LFPhp\PORM\ORM;

use LFPhp\PORM\Driver\DBConfig;
use LFPhp\PORM\Exception\Exception;
use function LFPhp\Func\explode_by;

/**
 * 表注解解析
 * @package LFPhp\PORM\Misc
 */
trait TableAnnotation {
	public static function getAttributes(){
		$table = static::getTableName();
		$obj = static::setQuery("SHOW CREATE TABLE `$table`");
		$ret = $obj->all(true);
		$sql = $ret[0]['Create Table'];

		/** @var DBConfig $cfg */
		$cfg = static::getDBConfig();
		$key = md5($cfg->toDSNString()).'_'.$table;
		return static::__cacheHandle($key, function()use($sql){
			return self::__createSqlResolve($sql);
		});
	}

	/**
	 * cache handler, override enabled
	 * @param $key
	 * @param callable $fetcher
	 * @return mixed|null
	 */
	protected static function __cacheHandle($key, callable $fetcher){
		$tmp_file = sys_get_temp_dir().DIRECTORY_SEPARATOR."DBAnnotation".DIRECTORY_SEPARATOR.$key;
		if(is_file($tmp_file)){
			$str = file_get_contents($tmp_file);
			if($str){
				return unserialize($str);
			}
		}
		$attrs = $fetcher();
		if(isset($attrs)){
			$data = serialize($attrs);
			mkdir(dirname($tmp_file));
			file_put_contents($tmp_file, $data);
		}
		return $attrs;
	}

	/**
	 * @param $sql
	 * @return array
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	private static function __createSqlResolve($sql){
		$lines = explode_by("\n", $sql);
		$attrs = [];
		foreach($lines as $line){
			if(preg_match('/^`(\w+)`\s+(.*?)\s+(.*),?$/', $line, $matches)){
				$name = $matches[1];
				$left = $matches[3];
				list($type, $len, $opts) = self::__resolveTypes($matches[2], $left);
				$attr = new DBAttribute();
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
			'varchar'    => [DBAttribute::TYPE_STRING, true],
			'char'       => [DBAttribute::TYPE_STRING, true],
			'longtext'   => [DBAttribute::TYPE_STRING, true, 4294967295],
			'mediumtext' => [DBAttribute::TYPE_STRING, true, 16777215],
			'text'       => [DBAttribute::TYPE_STRING, true, 65535],
			'tinytext'   => [DBAttribute::TYPE_STRING, true, 255],

			'tinyint'   => [DBAttribute::TYPE_INT, true],
			'smallint'  => [DBAttribute::TYPE_INT, true],
			'int'       => [DBAttribute::TYPE_INT, true],
			'mediumint' => [DBAttribute::TYPE_INT, true],
			'bigint'    => [DBAttribute::TYPE_INT, true],

			'decimal' => [DBAttribute::TYPE_DECIMAL, true],
			'float'   => [DBAttribute::TYPE_FLOAT, true],
			'double'  => [DBAttribute::TYPE_DOUBLE, true],
			'set'     => [DBAttribute::TYPE_SET, false],

			'datetime'  => [DBAttribute::TYPE_DATETIME, false],
			'date'      => [DBAttribute::TYPE_DATE, false],
			'time'      => [DBAttribute::TYPE_TIME, false],
			'year'      => [DBAttribute::TYPE_YEAR, false],
			'timestamp' => [DBAttribute::TYPE_TIMESTAMP, false],
			'enum'      => [DBAttribute::TYPE_ENUM, false],
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
				else if($type == DBAttribute::TYPE_ENUM || $type == DBAttribute::TYPE_SET){
					$keys = explode_by(',', str_replace("'", '', $val));

					//必须匹配 {NAME}(MARK1, MARK2)
					if(preg_match('/\sCOMMENT\s\'(.*?)\(([^)]+)\)\'/', $tail_sql, $ms)){
						$remarks = explode_by(',', $ms[2]);
						if(count($remarks) == count($keys)){
							return [DBAttribute::TYPE_ENUM, null, array_combine($keys, $remarks)];
						}
					} else {
						return [DBAttribute::TYPE_ENUM, null, array_combine($keys, $keys)];
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