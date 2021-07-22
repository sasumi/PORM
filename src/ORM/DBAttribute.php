<?php
namespace LFPhp\PORM\ORM;

/**
 * 数据库元数据抽象类
 */
class DBAttribute {
	const TYPE_INT = 'int';
	const TYPE_FLOAT = 'float';
	const TYPE_DECIMAL = 'decimal';
	const TYPE_DOUBLE = 'double';
	const TYPE_STRING = 'string';
	const TYPE_ENUM = 'enum';
	const TYPE_SET = 'set';
	const TYPE_BOOL = 'bool';
	const TYPE_DATE = 'date';
	const TYPE_TIME = 'time';
	const TYPE_DATETIME = 'datetime';
	const TYPE_TIMESTAMP = 'timestamp';
	const TYPE_YEAR = 'year';

	const TYPE_MAPS = [
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

	public $name;
	public $type;
	public $alias = '';
	public $description = '';
	public $default = null;
	public $options = [];
	public $length = null;
	public $is_readonly = false;
	public $is_primary_key = false;
	public $is_null_allow = false;
	public $is_unique = false;
	public $is_virtual = false;
	public $setter;
	public $getter;

	public function __construct(array $data = []){
		foreach($data as $k=>$v){
			$this->$k = $v;
		}
	}
}
