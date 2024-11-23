<?php
namespace LFPhp\PORM\ORM;

use LFPhp\PORM\Exception\Exception;

/**
 * Database metadata abstract class
 */
class Attribute {
	const TYPE_INT = 'int';
	const TYPE_FLOAT = 'float';
	const TYPE_DECIMAL = 'decimal';
	const TYPE_DOUBLE = 'double';
	const TYPE_STRING = 'string';
	const TYPE_JSON = 'json';
	const TYPE_ENUM = 'enum';
	const TYPE_SET = 'set';
	const TYPE_BOOL = 'bool';
	const TYPE_DATE = 'date';
	const TYPE_TIME = 'time';
	const TYPE_DATETIME = 'datetime';
	const TYPE_TIMESTAMP = 'timestamp';
	const TYPE_YEAR = 'year';

	const DEFAULT_NULL = "__ATTRIBUTE_DEFAULT_NULL__";
	const DEFAULT_CURRENT_TIMESTAMP = "__ATTRIBUTE_DEFAULT_CURRENT_TIMESTAMP__";
	const ON_UPDATE_CURRENT_TIMESTAMP = '__ATTRIBUTE_ON_UPDATE_CURRENT_TIMESTAMP__';

	const ALL_TYPES = [
		self::TYPE_INT,
		self::TYPE_FLOAT,
		self::TYPE_DECIMAL,
		self::TYPE_DOUBLE,
		self::TYPE_STRING,
		self::TYPE_ENUM,
		self::TYPE_SET,
		self::TYPE_BOOL,
		self::TYPE_DATE,
		self::TYPE_TIME,
		self::TYPE_DATETIME,
		self::TYPE_TIMESTAMP,
		self::TYPE_YEAR,
	];

	const IS_TYPE_NUM = [
		self::TYPE_INT,
		self::TYPE_DECIMAL,
		self::TYPE_FLOAT,
		self::TYPE_DOUBLE,
	];
	public $name; //name
	public $type; //type
	public $alias = ''; //alias (Chinese name)
	public $description = ''; //description
	public $default = null; //default value
	public $ext_attr = null; //extra attributes
	public $options = []; //options (ENUM type valid)
	public $length = null; //length
	public $precision = null; //precision
	public $is_readonly = false; //read-only
	public $is_primary_key = false; //primary key
	public $is_null_allow = false; //null allowed
	public $is_unique = false; //unique
	public $is_virtual = true; //virtual or not, by default, all newly created attributes are virtual attributes, not attributes that exist in the actual table
	public $collate = ''; //character set
	public $charset = ''; //encoding
	public $setter;
	public $getter;

	/** @var callable $on_display on display field handler */
	public $on_display;

	/**
	 * Convert variable types to strict types
	 * @param string|mixed $val Value (usually derived from database query results)
	 * @param string $type Property Type
	 * @return bool|float|int|string|string[]
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public static function strictTypeConvert($val, $type){
		switch($type){
			case self::TYPE_TIMESTAMP:
			case self::TYPE_YEAR:
			case self::TYPE_INT:
				return intval($val);

			case self::TYPE_BOOL:
				return !!$val;

			case self::TYPE_FLOAT:
			case self::TYPE_DECIMAL:
			case self::TYPE_DOUBLE:
				return floatval($val);

			case self::TYPE_STRING:
			case self::TYPE_ENUM:
			case self::TYPE_DATE:
			case self::TYPE_TIME:
			case self::TYPE_DATETIME:
			case self::TYPE_JSON:
				return $val.'';

			case self::TYPE_SET:
				return explode(",", $val);
			default:
				throw new Exception("Type no support:".$type);
		}
	}

	/**
	 * Whether the attribute has a user-defined default value
	 * @return bool
	 */
	public function hasUserDefinedDefaultValue(){
		return isset($this->default) && $this->default !== self::DEFAULT_NULL && $this->default !== self::DEFAULT_CURRENT_TIMESTAMP;
	}

	/**
	 * Does the property have a default update value?
	 * @return bool
	 */
	public function hasUpdateDefault(){
		return $this->ext_attr === self::ON_UPDATE_CURRENT_TIMESTAMP;
	}

	/**
	 * Get the attribute value display string
	 * @param $value
	 * @return string
	 */
	public function display($value){
		if($this->on_display){
			return strval(call_user_func($this->on_display, $value));
		}
		switch($this->type){
			case self::TYPE_SET:
				$tmp = explode(',', $value);
				$ret = [];
				foreach($tmp as $item){
					$ret[] = $this->options[$item];
				}
				return join(", ", $ret);
			case self::TYPE_ENUM:
				return strval($this->options[$value]);
			case self::TYPE_TIMESTAMP:
				return date('Y-m-d H:i:s', $value);
			case self::TYPE_INT:
				if(!$this->is_primary_key){
					return number_format($value);
				}
				break;
			case self::TYPE_BOOL:
				return $value ? 'ture' : 'false';
		}
		return strval($value);
	}

	/**
	 * Is there a system defined default value?
	 * @return bool
	 */
	public function hasSysDefinedDefaultValue(){
		return $this->default === self::DEFAULT_NULL || $this->default === self::DEFAULT_CURRENT_TIMESTAMP;
	}

	/**
	 * @param array $attr_info
	 */
	public function __construct(array $attr_info = []){
		foreach($attr_info as $k => $v){
			$this->$k = $v;
		}
	}
}
