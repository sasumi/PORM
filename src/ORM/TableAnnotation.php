<?php
namespace LFPhp\PORM\ORM;

use LFPhp\Cache\CacheFile;
use LFPhp\PORM\Exception\Exception;
use function LFPhp\Func\explode_by;

/**
 * 自动表注解解析
 * 数据库类型需要支持 SHOW CREATE TABLE 语句
 */
trait TableAnnotation {
	/**
	 * 根据实际数据库表设计，转换成Model属性，同时缓存到本地
	 * @return mixed
	 * @throws \Exception
	 */
	public static function getAttributes(){
		$table = static::getTableName();
		/** @var \LFPhp\PDODSN\DSN $dsn */
		$dsn = static::getDbDsn();
		$key = md5($dsn).'_'.$table;
		return CacheFile::instance(['dir' => static::_getTableAnnotationCacheDir()])
			->cache($key, function() use ($table){
				$obj = static::setQuery("SHOW CREATE TABLE `$table`");
				$ret = $obj->all(true);
				$dsl = $ret[0]['Create Table'];
				return self::__DSLResolve($dsl);
			});
	}

	/**
	 * 获取缓存目录，方法支持覆盖
	 * @return string path
	 */
	public static function _getTableAnnotationCacheDir(){
		return sys_get_temp_dir().'/_table_annotation';
	}

	/**
	 * 清空缓存目录
	 */
	public static function _clearTableAnnotationCache(){
		CacheFile::instance(['dir'=>static::_getTableAnnotationCacheDir()])->flush();
	}

	/**
	 * 从DSL中解析生成Attribute清单
	 * @param string $dsl
	 * @return Attribute[]
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public static function __DSLResolve($dsl){
		$lines = explode_by("\n", $dsl);
		$attrs = [];
		foreach($lines as $ln=>$line){
			$attr = new Attribute();
			if(preg_match('/^`(\w+)`\s+(.*?)\s+(.*),?$/', $line, $matches)){
				$name = $matches[1];
				$left = $matches[3];
				list($type, $length, $precision, $opts) = self::__resolveTypes($matches[2], $left);
				$attr->name = $name;
				$attr->type = $type;
				$attr->length = $length;
				$attr->precision = $precision;
				$attr->options = $opts;
			}
			if(self::_resolveDirective($line, 'CHARACTER SET', $charset)){
				$attr->charset = $charset;
			}
			if(self::_resolveDirective($line, 'COLLATE', $collate)){
				$attr->collate = $collate;
			}
			if(self::_resolveDirective($line, 'DEFAULT', $default)){
				$default = trim($default, "'");
				if($default === 'CURRENT_TIMESTAMP'){
					$attr->default = date('Y-m-d H:i:s');
				}
				elseif($default === 'NULL'){
					$attr->default = null;
					$attr->is_null_allow = true;
				}
				elseif($attr->type === Attribute::TYPE_INT){
					$attr->default = intval($default);
				}
				elseif(in_array($attr->type, [Attribute::TYPE_DECIMAL, Attribute::TYPE_FLOAT, Attribute::TYPE_DOUBLE])){
					$attr->default = floatval(trim($default, "'"));
				}
				else {
					$attr->default = $default;
				}
			}
			if(self::_resolveDirective($line, 'NOT NULL')){
				$attr->is_null_allow = false;
			}
			if(self::_resolveDirective($line, 'COMMENT', $comment)){
				$comment = trim($comment, "'");
				if(preg_match('/^(.+)\((.*?)\)/', $comment, $matches)){
					$attr->alias = $matches[1];
					$attr->description = $matches[2];
				}else{
					$attr->alias = $comment;
					$attr->description = '';
				}
			}
			if(strpos($line, ' AUTO_INCREMENT') !== false ||
				self::_resolveDirective($line, 'ON UPDATE CURRENT_TIMESTAMP')){
				$attr->is_readonly = true;
			}

			if(preg_match('/^PRIMARY\sKEY\s\(`(.*)`\)/', $line, $matches)){
				array_walk($attrs, function($at)use($matches){
					/** @var \LFPhp\PORM\ORM\Attribute $at */
					if($at->name === $matches[1]){
						$at->is_primary_key = true;
					}
				});
			}
			//当前仅支持单字段唯一
			if(preg_match('/^UNIQUE\sKEY.*\(`([^`]+)`\)/i', $line, $matches)){
				array_walk($attrs, function($at)use($matches){
					/** @var \LFPhp\PORM\ORM\Attribute $at */
					if($at->name === $matches[1]){
						$at->is_unique = true;
					}
				});
			}
			if($attr->name){
				$attrs[] = $attr;
			}
		}
		return $attrs;
	}

	/**
	 * 解析SQL DSL语言中的指令
	 * @param string $line
	 * @param string $directive 指令
	 * @param null $result 返回结果
	 * @return bool 是否匹配
	 */
	private static function _resolveDirective($line, $directive, &$result = null){
		if($directive == 'COMMENT' && preg_match('/\sCOMMENT\s(.*)$/', $line, $matches)){
			$result = rtrim($matches[1], ',');
			return true;
		}
		if(preg_match('/\s'.preg_quote($directive).'\s(\S+)/', $line, $matches)){
			$result = $matches[1];
			return true;
		}
		return false;
	}

	/**
	 * 解析数据库定义类型
	 * @param string $type_def
	 * @param string $tail_sql
	 * @return array [$type:类型, $len:长度, $precision:精度, $opts:其他选项]
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
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
			'decimal'   => [Attribute::TYPE_DECIMAL, true],
			'float'     => [Attribute::TYPE_FLOAT, true],
			'double'    => [Attribute::TYPE_DOUBLE, true],

			'set' => [Attribute::TYPE_SET, false],

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
					$precision = 0;
					if(strpos($val, ',') !== false){
						list($val, $precision) = explode(',', $val);
					}
					return [$type, (int)$val ?: $def_len, $precision];
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
						return [Attribute::TYPE_ENUM, null, null, array_combine($keys, $keys)];
					}
				}
				//time
				else{
					return [$type];
				}
			}
		}
		throw new Exception('type resolve fail:'.$type_def);
	}
}