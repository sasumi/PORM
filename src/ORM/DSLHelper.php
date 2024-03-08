<?php
namespace LFPhp\PORM\ORM;

use LFPhp\PORM\DB\DBDriver;
use LFPhp\PORM\Exception\Exception;
use function LFPhp\Func\explode_by;
use function LFPhp\Func\get_constant_name;
use function LFPhp\Func\var_export_min;

/**
 * 数据库元数据抽象辅助
 */
abstract class DSLHelper {
	const DEFAULT_MODEL_TPL = __DIR__.'/model.tpl.php';

	/**
	 * 属性类型映射到PHP类型
	 */
	const PHP_TYPE_MAP = [
		Attribute::TYPE_DATE      => 'string',
		Attribute::TYPE_TIME      => 'string',
		Attribute::TYPE_DATETIME  => 'string',
		Attribute::TYPE_JSON      => 'string',
		Attribute::TYPE_TIMESTAMP => 'string',
		Attribute::TYPE_YEAR      => 'int',
		Attribute::TYPE_SET       => 'array',
		Attribute::TYPE_ENUM      => 'string',
		Attribute::TYPE_DECIMAL   => 'float',
	];

	/**
	 * MySQL类型映射到属性类型
	 * 数据库字段类型 => [属性类型, 是否标量, 系统定义长度]
	 */
	const DB_FIELD_TYPE_MAP = [
		'varchar'    => [Attribute::TYPE_STRING, true],
		'char'       => [Attribute::TYPE_STRING, true],
		'json'       => [Attribute::TYPE_JSON, true],
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

		'datetime'  => [Attribute::TYPE_DATETIME, false],
		'date'      => [Attribute::TYPE_DATE, false],
		'time'      => [Attribute::TYPE_TIME, false],
		'year'      => [Attribute::TYPE_YEAR, false],
		'timestamp' => [Attribute::TYPE_TIMESTAMP, false],

		'enum' => [Attribute::TYPE_ENUM, false],
		'set'  => [Attribute::TYPE_SET, false],
	];

	/**
	 * 从Model中解析获取DSL信息
	 * @param Model|string $model_class
	 * @return array [string:表名, string:表备注, array:Attribute[]]
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public static function getTableInfoByModel($model_class){
		if(!class_exists($model_class)){
			throw new Exception('Class no exists:'.$model_class);
		}
		if(!in_array(Model::class, class_parents($model_class)) || $model_class === Model::class){
			throw new Exception('Class should inherit from '.Model::class);
		}
		$dsn = $model_class::getDbDsn();
		$table = $model_class::getTableName();
		return self::getTableInfoByDSN($dsn, $table);
	}

	/**
	 * 获取表信息（表名、表备注、表属性列表）
	 * @param \LFPhp\PDODSN\DSN $dsn
	 * @param string $table
	 * @return array [string:表名, string:表备注, array:Attribute[]]
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public static function getTableInfoByDSN($dsn, $table){
		$dsl = self::getTableDSLByDSN($dsn, $table);
		return self::resolveDSL($dsl);
	}

	/**
	 * 获取表DSL
	 * @param \LFPhp\PDODSN\DSN $dsn
	 * @param $table
	 * @return string
	 * @throws \LFPhp\PORM\Exception\DBException|\LFPhp\PORM\Exception\Exception
	 */
	public static function getTableDSLByDSN($dsn, $table){
		$pdo = DBDriver::instance($dsn);
		return $pdo->getDSLSchema($table);
	}

	/**
	 * 转化属性表到文档声明
	 * @param Attribute[] $attrs
	 * @return string
	 */
	public static function convertAttrsToDoctrine(array $attrs){
		$code = '';
		foreach($attrs as $attr){
			$readonly_patch = $attr->is_readonly ? '-read' : '';
			$type = self::PHP_TYPE_MAP[$attr->type] ?? $attr->type;
			$ext_desc = $attr->ext_attr === Attribute::ON_UPDATE_CURRENT_TIMESTAMP ? '(更新时自动更新时间)' : '';
			$str = "@property{$readonly_patch} ".$type." \${$attr->name} {$attr->alias} {$attr->description} {$ext_desc}";
			$str = preg_replace("/\s+/", " ", $str);
			$str = trim($str);
			$code .= " * $str".PHP_EOL;
		}
		return $code;
	}

	/**
	 * @param Attribute $attr
	 * @param bool $full_define
	 * @return string
	 * @throws \ReflectionException
	 */
	public static function convertAttrToCode($attr, $full_define = false){
		$default_attr = new Attribute();
		$result = [];
		$const_placeholder = '__CONST__';
		foreach($attr as $f => $v){
			if($full_define || $default_attr->{$f} !== $v){
				if($f === 'type'){
					$v = $const_placeholder.get_constant_name(Attribute::class, $v);
				}
				$result[$f] = $v;
			}
		}
		$code = '';
		$str = var_export_min($result, true);
		$s = preg_replace([
			'/^array\(/m',
			"/'$const_placeholder(.*?)'/",
			"/'".preg_quote(Attribute::DEFAULT_CURRENT_TIMESTAMP)."'/",
			"/'".preg_quote(Attribute::DEFAULT_NULL)."'/",
			"/'".preg_quote(Attribute::ON_UPDATE_CURRENT_TIMESTAMP)."'/",
			'/\)$/',
		], [
			'[',
			'Attribute::'.'$1',
			"Attribute::DEFAULT_CURRENT_TIMESTAMP",
			"Attribute::DEFAULT_NULL",
			"Attribute::ON_UPDATE_CURRENT_TIMESTAMP",
			']',
		], $str);
		$code .= "new Attribute($s)";
		return $code;
	}

	/**
	 * 从DSL中解析生成：表名+表备注+Attribute清单
	 * @param string $dsl
	 * @return array[] [string:表名, string:表备注, array:Attribute[]]
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public static function resolveDSL($dsl){
		$lines = explode_by("\n", $dsl);
		$attrs = [];
		$table_name = '';
		$table_description = '';
		foreach($lines as $line){
			$attr = new Attribute();
			$attr->is_virtual = false;
			if(!$table_name && preg_match('/^CREATE\s+TABLE\s+`([^`]+)`/', $line, $matches)){
				$table_name = $matches[1];
				continue;
			}
			if(!$table_description && preg_match("/\s+COMMENT='(.*?)'$/", $line, $matches)){
				$table_description = $matches[1];
				continue;
			}
			if(preg_match('/^`(\w+)`\s+(.*?)\s+(.*),?$/', $line, $matches)){
				$name = $matches[1];
				$left = $matches[3];
				list($type, $length, $precision, $opts) = self::__resolveTypes($matches[2], $left);
				$attr->name = $name;
				$attr->type = $type;
				$attr->length = $length;
				$attr->precision = $precision;
				$attr->options = $opts ?: [];
			}
			if(self::_resolveDirective($line, 'CHARACTER SET', $charset)){
				$attr->charset = $charset;
			}
			if(self::_resolveDirective($line, 'COLLATE', $collate)){
				$attr->collate = $collate;
			}
			if(self::_resolveDirective($line, 'DEFAULT', $default)){
				$default = trim($default, "'");
				if(stripos($default, 'current_timestamp') !== false){
					$attr->default = Attribute::DEFAULT_CURRENT_TIMESTAMP; //这里会涉及到属性打印,因此不能试用当前时间
				}elseif($default === 'NULL'){
					$attr->default = Attribute::DEFAULT_NULL;
				}elseif($attr->type === Attribute::TYPE_INT){
					$attr->default = intval($default);
				}elseif(in_array($attr->type, [
					Attribute::TYPE_DECIMAL,
					Attribute::TYPE_FLOAT,
					Attribute::TYPE_DOUBLE,
				])){
					$attr->default = floatval(trim($default, "'"));
				}else{
					$attr->default = $default;
				}
			}
			if(self::_resolveDirective($line, 'NOT NULL')){
				$attr->is_null_allow = false;
			}
			if(self::_resolveDirective($line, 'ON UPDATE', $on_update)){
				//这里可能包含其他函数参数，暂不支持指定参数形式
				if(stripos($on_update, 'current_timestamp') !== false){
					$attr->ext_attr = Attribute::ON_UPDATE_CURRENT_TIMESTAMP;
				}
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
			if(strpos($line, ' AUTO_INCREMENT') !== false || self::_resolveDirective($line, 'ON UPDATE CURRENT_TIMESTAMP')){
				$attr->is_readonly = true;
			}

			if(preg_match('/^PRIMARY\sKEY\s\(`(.*)`\)/', $line, $matches)){
				array_walk($attrs, function($at) use ($matches){
					/** @var Attribute $at */
					if($at->name === $matches[1]){
						$at->is_primary_key = true;
					}
				});
			}
			//当前仅支持单字段唯一
			if(preg_match('/^UNIQUE\sKEY.*\(`([^`]+)`\)/i', $line, $matches)){
				array_walk($attrs, function($at) use ($matches){
					/** @var Attribute $at */
					if($at->name === $matches[1]){
						$at->is_unique = true;
					}
				});
			}
			if($attr->name){
				$attrs[] = $attr;
			}
		}
		return [$table_name, $table_description, $attrs];
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
		if(preg_match('/(\w+)\(([^)]+)\)/', $type_def, $matches) || preg_match('/(\w+)\s*$/', $type_def, $matches)){
			$type_str = $matches[1];
			$val = $matches[2];
			if(isset(self::DB_FIELD_TYPE_MAP[$type_str])){
				list($type, $is_scalar, $def_len) = self::DB_FIELD_TYPE_MAP[$type_str];
				//scalar
				if($is_scalar){
					$precision = null;
					if(strpos($val, ',') !== false){
						list($val, $precision) = explode(',', $val);
					}
					return [$type, (int)$val ?: $def_len, $precision];
				}
				//enum、set
				if($type == Attribute::TYPE_ENUM || $type == Attribute::TYPE_SET){
					$keys = explode_by(',', str_replace("'", '', $val));

					//必须匹配: {NAME}(MARK1, MARK2) 格式，才能解析出里面的选项名称
					if(preg_match('/\sCOMMENT\s\'(.*?)\(([^)]+)\)\'/', $tail_sql, $ms)){
						$remarks = explode_by(',', $ms[2]);
						if(count($remarks) == count($keys)){
							return [Attribute::TYPE_ENUM, null, null, array_combine($keys, $remarks)];
						}
					}
					return [$type, null, null, array_combine($keys, $keys)];
				}
				//time
				return [$type, null, null, []];
			}
		}
		throw new Exception('type resolve fail:'.$type_def);
	}
}
