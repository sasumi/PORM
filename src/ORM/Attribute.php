<?php
namespace LFPhp\PORM\ORM;

use LFPhp\PORM\Exception\Exception;

/**
 * 数据库元数据抽象类
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

	public $name; //名称
	public $type; //类型
	public $alias = ''; //别名(中文名)
	public $description = ''; //描述
	public $default = null; //默认值
	public $ext_attr = null; //额外属性
	public $options = []; //选项(ENUM类型有效)
	public $length = null; //长度
	public $precision = null; //精度
	public $is_readonly = false; //是否只读
	public $is_primary_key = false; //是否主键
	public $is_null_allow = false; //是否允许为空
	public $is_unique = false; //是否唯一
	public $is_virtual = true; //是否虚拟，默认用户新建属性都为虚拟属性，非实际表中存在的属性
	public $collate = ''; //字符集
	public $charset = ''; //编码
	public $setter;
	public $getter;

	/** @var callable $on_display 显示处理绑定函数 */
	public $on_display;

	/**
	 * 变量类型转换成严格类型
	 * @param string|mixed $val 值（一般来源于数据库查询结果）
	 * @param string $type 属性类型
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
	 * 属性是否具备用户设定默认值
	 * @return bool
	 */
	public function hasUserDefinedDefaultValue(){
		return isset($this->default) && $this->default !== self::DEFAULT_NULL && $this->default !== self::DEFAULT_CURRENT_TIMESTAMP;
	}

	/**
	 * 属性是否有默认更新值
	 * @return bool
	 */
	public function hasUpdateDefault(){
		return $this->ext_attr === self::ON_UPDATE_CURRENT_TIMESTAMP;
	}

	/**
	 * 获取属性值显示字符串
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
	 * 是否有系统定义默认值
	 * @return bool
	 */
	public function hasSysDefinedDefaultValue(){
		return $this->default === self::DEFAULT_NULL || $this->default === self::DEFAULT_CURRENT_TIMESTAMP;
	}

	/**
	 * 属性初始化
	 * @param array $attr_info
	 */
	public function __construct(array $attr_info = []){
		foreach($attr_info as $k => $v){
			$this->$k = $v;
		}
	}
}
