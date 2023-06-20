<?php
namespace LFPhp\PORM\ORM;

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
