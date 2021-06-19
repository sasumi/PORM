<?php
namespace LFPhp\PORM\Misc;

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

	public $is_primary_key;
	public $name;
	public $alias = '';
	public $description = '';
	public $type;
	public $default;
	public $length;
	public $is_readonly;
	public $is_null_allow = false;
	public $is_unique = false;
	public $is_virtual = false;

	public $options = [];

	public $setter;
	public $getter;
}
