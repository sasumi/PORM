<?php
namespace LFPhp\PORM\ORM;

use ArrayAccess;
use JsonSerializable;
use LFPhp\Logger\LoggerTrait;
use LFPhp\PDODSN\Database\MySQL;
use LFPhp\PORM\DB\DBDriver;
use LFPhp\PORM\DB\DBQuery;
use LFPhp\PORM\Exception\DBException;
use LFPhp\PORM\Exception\Exception;
use LFPhp\PORM\Exception\NotFoundException;
use function LFPhp\Func\array_filter_fields;
use function LFPhp\Func\array_first;
use function LFPhp\Func\array_group;
use function LFPhp\Func\array_index;
use function LFPhp\Func\array_orderby;
use function LFPhp\Func\format_size;
use function LFPhp\Func\is_json;
use function LFPhp\Func\time_range_v;

/**
 * ORM data model
 */
abstract class Model implements JsonSerializable, ArrayAccess {
	const OP_READ = 1;
	const OP_WRITE = 2;

	use LoggerTrait;

	/** @var Attribute[] model define */
	protected $attributes = [];

	/**
	 * @var array uses key-value pairs for storage
	 * Since there are dynamic setters and getters in Attribute, please use the getProperties method to obtain the value
	 */
	protected $properties = [];

	/**
	 * Property value changes
	 * @var array
	 */
	protected $property_changes = [];

	/** @var DBQuery db query object * */
	private $query = null;

	/**
	 * Get the table name
	 * @return string
	 */
	abstract static public function getTableName();

	/**
	 * Get features
	 * @return Attribute[]
	 */
	static public function getAttributes(){
		return [];
	}

	/**
	 * Set property definition
	 * @param array $attributes
	 * @param string $attr_name attribute name
	 * @param string|array $sets Define field name, or [Define field name => Define value] key-value pair
	 * @param mixed $val definition value
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	static public function updateAttribute($attributes, $attr_name, $sets, $val = null){
		$attr = null;
		foreach($attributes as $at){
			if($at->name === $attr_name){
				$attr = $at;
				break;
			}
		}
		if(!$attr){
			throw new Exception('attribute no found:'.$attr_name);
		}
		$pairs = [];
		if(is_string($sets)){
			$pairs[$sets] = $val;
		}else if(is_array($sets)){
			$pairs = $sets;
		}else{
			throw new Exception('updateAttribute parameter invalid');
		}
		foreach($pairs as $field => $define){
			$attr->{$field} = $define;
		}
	}

	/**
	 * DBModel constructor.
	 * @param array $data
	 */
	public function __construct($data = []){
		foreach($data as $k => $val){
			$this->{$k} = $val;
		}
	}

	/**
	 * Get the database table description name
	 * @return string
	 */
	public static function getModelDesc(){
		return static::getTableName();
	}

	/**
	 * Get attributes based on field name
	 * @param string $name attribute name
	 * @return Attribute
	 */
	public static function getAttributeByName($name){
		$attrs = static::getAttributes();
		foreach($attrs as $attr){
			if($attr->name === $name){
				return $attr;
			}
		}
		return null;
	}

	/**
	 * Get the database table primary key
	 * @return string
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public static function getPrimaryKey(){
		$attrs = static::getAttributes();
		foreach($attrs as $attr){
			if($attr->is_primary_key){
				return $attr->name;
			}
		}
		throw new DBException('No primary key found in table defines');
	}

	/**
	 * Get the primary key value
	 * @return mixed
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function getPrimaryKeyValue(){
		$pk = static::getPrimaryKey();
		return $this->$pk;
	}

	/**
	 * Get the db record instance object
	 * @param int $operate_type
	 * @return DBDriver
	 */
	protected static function getDBDriver($operate_type = self::OP_WRITE){
		$dsn = static::getDSN($operate_type);
		return DBDriver::instance($dsn);
	}

	/**
	 * Explain SQL statements
	 * @param string $query
	 * @return array
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public static function explainQuery($query){
		return static::getDBDriver(self::OP_READ)->explain($query);
	}

	/**
	 * Set the query SQL statement
	 * @param string|DBQuery $query
	 * @return static|DBQuery
	 * @throws \Exception
	 */
	public static function setQuery($query){
		if(is_string($query)){
			$query = new DBQuery($query);
		}
		if($query){
			$obj = new static;
			$obj->query = $query;
			return $obj;
		}
		throw new Exception('Query string required');
	}

	/**
	 * Get the current query object
	 * @return \LFPhp\PORM\DB\DBQuery|null
	 */
	public function getQuery(){
		return $this->query;
	}

	/**
	 * Transaction processing
	 * @param callable $handler processing function, if the function returns false or throws Exception, the submission will be stopped and the transaction will be rolled back
	 * @return mixed closure function return value transparent transmission
	 * @throws \Exception
	 */
	public static function transaction($handler){
		$driver = static::getDBDriver(Model::OP_WRITE);
		try{
			$driver->beginTransaction();
			$ret = call_user_func($handler);
			if($ret === false){
				throw new DBException('Database transaction interrupted');
			}
			if(!$driver->commit()){
				throw new DBException('Database commit fail');
			}
			return $ret;
		}catch(Exception $exception){
			$driver->rollback();
			throw $exception;
		}finally{
			$driver->cancelTransactionState();
		}
	}

	/**
	 * Execute the current query
	 * @return \PDOStatement
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function execute(){
		$type = DBQuery::isWriteOperation($this->query) ? self::OP_WRITE : self::OP_READ;
		return static::getDBDriver($type)->query($this->query);
	}

	/**
	 * Search
	 * @param string $statement conditional expression
	 * @param string $var,... Conditional expression expansion
	 * @return static|DBQuery
	 */
	public static function find($statement = '', $var = null){
		$obj = new static;
		$query = new DBQuery();
		$args = func_get_args();
		$statement = self::parseConditionStatement($args, static::class);
		$query->select()->from(static::getTableName())->where($statement);
		$obj->query = $query;
		return $obj;
	}

	/**
	 * Add more query conditions
	 * @param array $args query conditions
	 * @return static|DBQuery
	 */
	public function where(...$args){
		$statement = self::parseConditionStatement($args, static::class);
		$this->query->where($statement);
		return $this;
	}

	/**
	 * Quickly query the information requested by the user. The query will be performed only when the second parameter is not empty. The query will still be performed if the array is empty.
	 * @param string $st
	 * @param string|int|array|null $val
	 * @return static|DBQuery
	 */
	public function whereOnSet($st, $val){
		$args = func_get_args();
		foreach($args as $k => $arg){
			if(is_string($arg)){
				$args[$k] = trim($arg);
			}
		}
		if(is_array($val) || strlen($val)){
			$statement = self::parseConditionStatement($args, static::class);
			$this->query->where($statement);
		}
		return $this;
	}

	/**
	 * query with exclude specified fields
	 * @param string ...$exclude_fields
	 * @return mixed
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public function allFieldsExclude(...$exclude_fields){
		$attrs = static::getAttributes();
		$all_fields = array_keys($attrs);
		if(!$all_fields){
			throw new Exception('no fields found in define');
		}
		$all_fields = array_filter($all_fields, function($val) use ($exclude_fields){
			return !in_array($val, $exclude_fields);
		});
		return $this->fields($all_fields);
	}

	/**
	 * Automatically detect variable types and set matching records for variable values
	 * The current operation only performs "equal to" and "contains" comparisons, and does not perform other comparisons
	 * @param string[] $fields
	 * @param string[]|number[] $param
	 * @return $this
	 */
	public function whereEqualOnSetViaFields(array $fields, array $param = []){
		foreach($fields as $field){
			$val = $param[$field];
			if(is_array($val) || strlen($val)){
				$comparison = is_array($val) ? 'IN' : '=';
				$this->whereOnSet("$field $comparison ?", $val);
			}
		}
		return $this;
	}

	/**
	 * Quickly LIKE to query the information requested by the user. When the LIKE content is empty, the query is not executed, such as %%.
	 * @param string $st
	 * @param string|number $val
	 * @return static|DBQuery
	 */
	public function whereLikeOnSet($st, $val){
		$args = func_get_args();
		if(strlen(trim(str_replace(DBDriver::LIKE_RESERVED_CHARS, '', $val)))){
			return call_user_func_array([$this, 'whereOnSet'], $args);
		}
		return $this;
	}

	/**
	 * Batch LIKE query (whereLikeOnSet method shortcut usage)
	 * @param array $fields
	 * @param $val
	 * @return static|DBQuery
	 */
	public function whereLikeOnSetBatch(array $fields, $val){
		$st = join(' LIKE ? OR ', $fields).' LIKE ?';
		$values = array_fill(0, count($fields), $val);
		array_unshift($values, $st);
		return call_user_func_array([$this, 'whereLikeOnSet'], $values);
	}

	/**
	 * Check if the field is within the specified range
	 * @param string $field
	 * @param number|null $min minimum end
	 * @param number|null $max maximum end
	 * @param bool $equal_cmp whether it contains equals
	 * @return static|DBQuery
	 */
	public function between($field, $min = null, $max = null, $equal_cmp = true){
		$cmp = $equal_cmp ? '=' : '';
		$hit = false;
		if(strlen($min)){
			$min = addslashes($min);
			$this->query->where($field, ">$cmp", $min);
			$hit = true;
		}
		if(strlen($max)){
			$max = addslashes($max);
			$this->query->where($field, "<$cmp", $max);
			$hit = true;
		}
		if($hit){
			$this->query->where("`$field` IS NOT NULL");
		}
		return $this;
	}

	/**
	 * Create a new object
	 * @param $data
	 * @return bool|static
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public static function create($data){
		$obj = new static();
		foreach($data as $k => $v){
			$obj->{$k} = $v;
		}
		return $obj->save() ? $obj : false;
	}

	/**
	 * Query a record by primary key
	 * @param string $val
	 * @param bool $as_array
	 * @return array|static
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public static function findOneByPk($val, $as_array = false){
		$pk = static::getPrimaryKey();
		return static::find($pk.'=?', $val)->one($as_array);
	}

	/**
	 * @param $val
	 * @param bool $as_array
	 * @return static|DBQuery
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public static function findOneByPkOrFail($val, $as_array = false){
		$data = static::findOneByPk($val, $as_array);
		if(!$data){
			$table_desc = static::getModelDesc() ?: static::getTableName();
			$pk_field_name = static::getPrimaryKey() ?: 'PK';
			throw new NotFoundException("Cannot find relevant data in {$table_desc} ({$pk_field_name}: {$val})");
		}
		return $data;
	}

	/**
	 * Query multiple records with primary key list
	 * If the single primary key list is empty, this method will return an empty array result
	 * @param array $pk_values
	 * @param bool $as_array
	 * @return static[]
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public static function findByPks(array $pk_values, $as_array = false){
		if(!$pk_values){
			return [];
		}
		$pk = static::getPrimaryKey();
		return static::find("`$pk` IN ?", $pk_values)->all($as_array);
	}

	/**
	 * Delete a record based on the primary key value
	 * @param string $val
	 * @return int
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public static function delByPk($val){
		$pk = static::getPrimaryKey();
		static::deleteWhere(0, "`$pk`=?", $val);
		return (new static)->getAffectNum();
	}

	/**
	 * Delete records based on primary key
	 * @param $val
	 * @return int
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 * @throws \LFPhp\PORM\Exception\NotFoundException
	 */
	public static function delByPkOrFail($val){
		static::delByPk($val);
		$count = (new static)->getAffectNum();
		if(!$count){
			throw new NotFoundException('The record has been deleted');
		}
		return $count;
	}

	/**
	 * Update records based on primary key value
	 * @param string $val primary key value
	 * @param array $data
	 * @return bool
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public static function updateByPk($val, array $data){
		$pk = static::getPrimaryKey();
		return static::updateWhere($data, 1, "`$pk` = ?", $val);
	}

	/**
	 * Update records based on primary key value
	 * @param string[]|number[] $pks
	 * @param array $data
	 * @return bool
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public static function updateByPks($pks, array $data){
		$pk = static::getPrimaryKey();
		return static::updateWhere($data, count($pks), "`$pk` IN ?", $pks);
	}

	/**
	 * Update data according to conditions
	 * @param array $data
	 * @param int $limit For safety reasons, the caller must pass in a specific value. If you do not limit the number of updates, you can set it to 0
	 * @param string $statement For security reasons, the caller must pass in specific conditions. If there is no restriction, it can be set to an empty string
	 * @return bool;
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public static function updateWhere(array $data, $limit, $statement){
		if(self::onBeforeChangedGlobal() === false){
			return false;
		}

		$args = func_get_args();
		$args = array_slice($args, 2);
		$statement = self::parseConditionStatement($args, static::class);
		$table = static::getTableName();
		return static::getDBDriver()->update($table, $data, $statement, $limit);
	}

	/**
	 * Delete records from the table based on conditions
	 * @param int $limit For safety reasons, the caller must pass in a specific value. If you do not limit the number of deletions, you can set it to 0
	 * @param string $statement For security reasons, the caller must pass in specific conditions. If there is no restriction, it can be set to an empty string
	 * @return bool
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public static function deleteWhere($limit, $statement){
		$args = func_get_args();
		$args = array_slice($args, 1);

		$statement = self::parseConditionStatement($args, static::class);
		return static::getDBDriver()->delete(static::getTableName(), $statement, $limit);
	}

	/**
	 * Clear data
	 * @return bool
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public static function truncate(){
		$table = static::getTableName();
		return static::getDBDriver()->delete($table, '', 0);
	}

	/**
	 * Get all records
	 * @param bool $as_array return as array
	 * @param string $unique_key is used to form the unique key of the returned array
	 * @return static[]
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function all($as_array = false, $unique_key = ''){
		$list = static::getDBDriver(self::OP_READ)->getAll($this->query);
		if(!$list){
			return [];
		}
		return $this->handleListResult($list, $as_array, $unique_key);
	}

	/**
	 * Pagination query records
	 * @param string $page
	 * @param bool $as_array whether to return as an array, the default is a Model object array
	 * @param string $unique_key is used to form the unique key of the returned array
	 * @return static[]
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function paginate($page = null, $as_array = false, $unique_key = ''){
		$list = static::getDBDriver(self::OP_READ)->getPage($this->query, $page);
		return $this->handleListResult($list, $as_array, $unique_key);
	}

	/**
	 * Format data list and prefetch data
	 * @param static[]|array[] $list
	 * @param bool $as_array whether to return as a two-dimensional array, the default is an object array
	 * @param string $unique_key array index key
	 * @return array
	 */
	private function handleListResult(array $list, $as_array = false, $unique_key = ''){
		if($as_array){
			if($unique_key){
				$list = array_group($list, $unique_key, true);
			}
			return $list;
		}
		$result = [];
		foreach($list as $item){
			$tmp = new static($item);
			$tmp->property_changes = [];
			if($unique_key){
				$result[$item[$unique_key]] = $tmp;
			}else{
				$result[] = $tmp;
			}
		}
		return $result;
	}

	/**
	 * Get a record
	 * @param bool $as_array whether to return as an array, the default is the Model object
	 * @return static|array|null
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function one($as_array = false){
		$data = static::getDBDriver(self::OP_READ)->getOne($this->query);
		if($as_array){
			return $data;
		}
		if(!empty($data)){
			$this->__construct($data);
			$this->property_changes = [];
			return $this;
		}
		return null;
	}

	/**
	 * Get a record, throw an exception if it is empty
	 * @param bool $as_array whether to return as an array, the default is the Model object
	 * @return static|DBQuery
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 * @throws \LFPhp\PORM\Exception\NotFoundException
	 */
	public function oneOrFail($as_array = false){
		$data = $this->one($as_array);
		if(!$data){
			throw new NotFoundException('No relevant data found.'.$this->query, null, null, $this->query);
		}
		return $data;
	}

	/**
	 * Get a record field
	 * @param string|null $key If the field is empty, take the first result
	 * @return mixed|null
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function ceil($key = ''){
		$attr_names = static::getEntityAttributeNames();
		if($key && in_array($key, $attr_names)){
			$this->query->field($key);
		}
		$data = static::getDBDriver(self::OP_READ)->getOne($this->query);
		if(!$data){
			return null;
		}
		return $key ? $data[$key] : array_pop($data);
	}

	/**
	 * Get a list of entity attribute names
	 * @return array
	 */
	protected static function getEntityAttributeNames(){
		$names = [];
		$attrs = static::getEntityAttributes();
		foreach($attrs as $attribute){
			$names[] = $attribute->name;
		}
		return $names;
	}

	/**
	 * Get entity attribute list
	 * @return Attribute[]
	 */
	protected static function getEntityAttributes(){
		$attrs = static::getAttributes();
		$ret = [];
		foreach($attrs as $attribute){
			if(!$attribute->is_virtual){
				$ret[] = $attribute;
			}
		}
		return $ret;
	}

	/**
	 * Convert the model object list to an array
	 * @param static[] $model_list
	 * @return array
	 */
	public static function convertModelListToArray($model_list){
		$tmp = [];
		foreach($model_list as $m){
			$tmp[] = $m->getData();
		}
		return $tmp;
	}

	/**
	 * Calculate the sum of field values
	 * @param string|array $fields The field names to be calculated (list)
	 * @param array $group_by uses the specified field (list) as the merge dimension
	 * @return number|array The sum of the results, or the sum of the results with the specified field list as the subscript
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 * @example
	 * <pre>
	 * $report->sum('order_price', 'original_price');
	 * $report->group('platform')->sum('order_price');
	 * sum('price'); //10.00
	 * sum(['price','count']); //[10.00, 14]
	 * sum(['price', 'count'], ['platform','order_type']); //
	 * [
	 * ['platform,order_type'=>'amazon', 'price'=>10.00, 'count'=>14],
	 * ['platform'=>'ebay', 'price'=>10.00, 'count'=>14],...
	 * ]
	 * sum(['price', 'count'], ['platform', 'order_type']);
	 * </pre>
	 */
	public function sum($fields, $group_by = []){
		$fields = is_array($fields) ? $fields : [$fields];
		$str = [];
		foreach($fields as $field){
			$str[] = "SUM(`$field`) as $field";
		}

		if($group_by){
			$str = array_merge($str, $group_by);
			$this->query->group(implode(',', $group_by));
		}
		$this->query->fields($str);

		$data = static::getDBDriver(self::OP_READ)->getAll($this->query);
		if($group_by){
			return $data;
		}
		if(count($fields) == 1){
			return array_first(array_first($data));
		}else{
			return array_values(array_first($data));
		}
	}

	/**
	 * Object reordering
	 * The list is sorted from large to small by value, and all sorting will be recalculated each time it is called
	 * Usage: <pre>
	 * $category->render(true, 'sort', 'status=?', $enabled);
	 * </pre>
	 * @param bool $move_up whether to move up
	 * @param string $sort_key sort field name, default is sort
	 * @param string $statement sort range filter expression, default is all data
	 * @return bool
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function reorder($move_up, $sort_key = 'sort', $statement = ''){
		$pk = static::getPrimaryKey();
		$pk_v = $this->{$pk};
		$query = static::find()->field($pk, $sort_key);

		//query statement
		if($statement){
			$statement_list = func_get_args();
			$statement_list = array_slice($statement_list, 3);
			if($statement_list){
				call_user_func_array([$query, 'where'], $statement_list);
			}
		}

		$sort_list = $query->all(true);
		$count = count($sort_list);
		$sort_list = array_orderby($sort_list, $sort_key, SORT_DESC);
		$current_idx = array_index($sort_list, function($item) use ($pk, $pk_v){
			return $item[$pk] == $pk_v;
		});
		if($current_idx === false){
			return false;
		}

		//Already pinned to the top or bottom
		if($move_up && $current_idx == 0 || (!$move_up && $current_idx == $count - 1)){
			return true;
		}

		if($move_up){
			$tmp = $sort_list[$current_idx - 1];
			$sort_list[$current_idx - 1] = $sort_list[$current_idx];
			$sort_list[$current_idx] = $tmp;
		}else{
			$tmp = $sort_list[$current_idx + 1];
			$sort_list[$current_idx + 1] = $sort_list[$current_idx];
			$sort_list[$current_idx] = $tmp;
		}

		//force reordering
		foreach($sort_list as $k => $v){
			static::updateWhere([$sort_key => $count - $k - 1], 1, "`$pk` = ?", $v[$pk]);
		}
		return true;
	}

	/**
	 * Get the specified column and return it as a one-dimensional array
	 * @param $key
	 * @return array
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function column($key){
		$this->query->field($key);
		$data = static::getDBDriver(self::OP_READ)->getAll($this->query);
		return $data ? array_column($data, $key) : [];
	}

	/**
	 * Return as a mapped array
	 * No field filtering is performed here. The query may have set custom fields through field(), and the $key here may be invalid.
	 * <pre>
	 * $query->map('id', 'name'); //Return: [[id_val=>name_val],...] format data
	 * $query->map('id', ['name']); //Return: [[id_val=>[name=>name_val],...] format data
	 * $query->map('id', ['name', 'gender']); //Return: [[id_val=>[name=>name_val, gender=>gender_val],...] format data
	 * </pre>
	 * @param $key
	 * @param $val
	 * @return array
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function map($key, $val){
		if(is_string($val)){
			$tmp = static::getDBDriver(self::OP_READ)->getAll($this->query);
			return array_combine(array_column($tmp, $key), array_column($tmp, $val));
		}else if(is_array($val)){
			$tmp[] = $key;
			$tmp = static::getDBDriver(self::OP_READ)->getAll($this->query);
			$ret = [];
			foreach($tmp as $item){
				$ret[$item[$key]] = [];
				foreach($val as $field){
					$ret[$item[$key]][$field] = $item[$field];
				}
			}
			return $ret;
		}
		throw new DBException("Mapping parameter error: [$key, $val]", null, null, static::getDSN(self::OP_READ));
	}

	/**
	 * Process data according to segments, commonly used to save WebServer memory operations
	 * @param int $size chunk size
	 * @param callable $handler callback function
	 * @param bool $as_array Query result as array format callback
	 * @return bool whether the block action is executed
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function chunk($size, $handler, $as_array = false){
		$total = $this->count();
		$start = 0;
		if(!$total){
			return false;
		}

		$page_index = 0;
		$page_total = ceil($total/$size);
		while($start < $total){
			$list = $this->paginate([$start, $size], $as_array);
			if(call_user_func($handler, $list, $page_index++, $page_total, $total) === false){
				break;
			}
			$start += $size;
		}
		return true;
	}

	/**
	 * Data record monitoring
	 * @param callable $handler processing function, if it returns false, the terminal monitors
	 * @param int $chunk_size Chunk size when getting data
	 * @param int $sleep_interval_sec Sleep duration when there is no data (seconds)
	 * @param bool|callable|null $debugger data information debugger
	 * @return bool Whether the execution is normal
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function watch(callable $handler, $chunk_size = 50, $sleep_interval_sec = 3, $debugger = true){
		if($debugger === true){
			$debugger = function(...$args){
				echo date('Ymd H:i:s')."\t".join("\t", func_get_args()), PHP_EOL;
			};
		}else if(!$debugger || !is_callable($debugger)){
			$debugger = function(){
			};
		}

		$cache_on = DBDriver::getQueryCacheState();
		DBDriver::setQueryCacheOff();
		while(true){
			$obj = clone($this);
			$break = false;
			$start = microtime(true);
			$exists = $obj->chunk($chunk_size, function($data_list, $page_index, $page_total, $item_total) use ($handler, $chunk_size, $debugger, $start, &$break){
				/** @var Model $item */
				foreach($data_list as $k => $item){
					$cur = $page_index*$chunk_size + $k + 1;
					$now = microtime(true);
					$left = ($now - $start)*($item_total - $cur)/$cur;
					$left_time = time_range_v($left);
					$debugger('Handling item: ['.$cur.'/'.$item_total." - $left_time]", substr(json_encode($item), 0, 200));
					$ret = call_user_func($handler, $item, $page_index, $page_total, $item_total);
					if($ret === false){
						$debugger('Handler Break!');
						$break = true;
						return false;
					}
				}
				return true;
			});
			unset($obj);
			if($break){
				$debugger('Handler Break!');
				break;
			}
			if(!$exists){
				$debugger('no data found, sleep for '.$sleep_interval_sec.' sec, mem:'.format_size(memory_get_usage(true)));
				sleep($sleep_interval_sec);
			}
		}
		if($cache_on){
			DBDriver::setQueryCacheOn();
		}
		return true;
	}

	/**
	 * Get the current query count
	 * @return int
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function count(){
		$driver = static::getDBDriver(self::OP_READ);
		return $driver->getCount($this->query);
	}

	/**
	 * Update the current object
	 * @param bool $validate_all whether to refresh all data, including readonly data
	 * @return bool|number
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function update($validate_all = false){
		if($this->onBeforeUpdate() === false || $this->onBeforeChanged() === false){
			return false;
		}

		$data = $this->getProperties();
		$pk = static::getPrimaryKey();

		// Update only changed values
		$data = array_filter_fields($data, $this->property_changes);
		$data = $this->validate($data, DBQuery::UPDATE, $validate_all);
		static::getDBDriver()->update(static::getTableName(), $data, static::getPrimaryKey().'='.$this->$pk);
		$this->onAfterUpdate();
		$this->property_changes = [];
		return $this->{static::getPrimaryKey()};
	}

	/**
	 * Insert the current object
	 * @param bool $validate_all whether to validate all data, including readonly data
	 * @return string|bool Returns the inserted id, or fails (false)
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function insert($validate_all = false){
		if($this->onBeforeInsert() === false || $this->onBeforeChanged() === false){
			return false;
		}
		$data = $this->getProperties();
		$data = $this->validate($data, DBQuery::INSERT, $validate_all);
		$result = static::getDBDriver()->insert(static::getTableName(), $data);
		if($result){
			$pk_val = static::getDBDriver()->getLastInsertId();
			$pk = static::getPrimaryKey();
			$this->{$pk} = $pk_val;
			$this->onAfterInsert();
			return $pk_val;
		}
		return false;
	}

	/**
	 * Replace data
	 * @param array $data
	 * @param int $limit
	 * @param array ...$args query conditions
	 * @return int
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public static function replace(array $data, $limit = 0, ...$args){
		$statement = self::parseConditionStatement($args, static::class);
		$table = static::getTableName();
		return static::getDBDriver()->replace($table, $data, $statement, $limit);
	}

	/**
	 * Increase or decrease the count
	 * @param string $field The field used for counting
	 * @param int $offset count offset, such as 1, -1
	 * @param int $limit The number of entries is limited. The default value is 0, which means there is no limit on the number of entries to be updated.
	 * @param array ...$args query conditions
	 * @return int
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public static function increase($field, $offset, $limit = 0, ...$args){
		$statement = self::parseConditionStatement($args, static::class);
		$table = static::getTableName();
		return static::getDBDriver()->increase($table, $field, $offset, $statement, $limit);
	}

	/**
	 * Data verification
	 * @param array $src_data metadata
	 * @param string $query_type database operation type
	 * @param bool $validate_all whether to validate all data, including readonly data
	 * @return array $data
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	private function validate($src_data = [], $query_type = DBQuery::INSERT, $validate_all = false){
		$attrs = self::getEntityAttributes();
		$attr_maps = [];
		foreach($attrs as $attr){
			$attr_maps[$attr->name] = $attr;
		}
		$pk = static::getPrimaryKey();

		//Convert set data
		foreach($src_data as $k => $d){
			if($attr_maps[$k]->type == Attribute::TYPE_SET && is_array($d)){
				$src_data[$k] = join(',', $d);
			}
		}

		//Remove vector values
		$data = array_filter($src_data, function($item){
			return is_scalar($item) || is_null($item);
		});

		//unique check
		foreach($src_data as $field => $_){
			$attr = $attr_maps[$field];
			if($attr->is_unique){
				if($query_type == DBQuery::INSERT){
					$count = $this::find("`$field`=?", $data[$field])->count();
				}else{
					$count = $this::find("`$field`=? AND `$pk` <> ?", $data[$field], $this->$pk)->count();
				}
				if($count){
					throw new DBException("{$attr->alias}: \"{$data[$field]}\" already exists, please do not add it again.");
				}
			}
		}

		//Remove the readonly attribute
		if(!$validate_all){
			$attr_maps = array_filter($attr_maps, function($attr){
				return !$attr->is_readonly;
			});
		}

		//Clean up useless data
		$data = array_filter_fields($data, array_keys($attr_maps));

		//Fill in the default value when inserting
		array_walk($attr_maps, function($attr, $k) use (&$data, $query_type){
			/**
			 * No data is set, and the attribute definition has no default value. You need to fill in the default value
			 * @var Attribute $attr
			 */
			if(!isset($data[$k]) && $attr->hasUserDefinedDefaultValue()){
				if($query_type == DBQuery::INSERT){
					if(!isset($data[$k])){ //Allow submission of empty string
						$data[$k] = $attr->default;
					}
				}else if(isset($data[$k]) && !strlen($data[$k])){
					$data[$k] = $attr->default;
				}
			}
		});

		//When updating, you only need to process the properties of the updated data
		if($query_type == DBQuery::UPDATE || $query_type == DBQuery::REPLACE){
			foreach($attr_maps as $k => $attr){
				if(!isset($data[$k])){
					unset($attr_maps[$k]);
				}
			}
		}

		//Attribute verification
		foreach($attr_maps as $k => $attr){
			if(!$attr->is_readonly || $validate_all){
				if($msg = $this->validateField($attr, $data[$k])){
					throw new DBException($msg, null, null, ['field' => $k, 'value' => $data[$k], 'row' => $data]);
				}
			}
		}
		return $data;
	}

	/**
	 * Field validation
	 * @param \LFPhp\PORM\ORM\Attribute $attr
	 * @param $value
	 * @return string
	 * @throws \Exception
	 */
	private function validateField($attr, $value){
		$err = '';
		$name = $attr->alias ?: $attr->name;
		$options = $attr->options;
		if(is_callable($options)){
			$options = call_user_func($options, $this);
		}

		$required = !$attr->is_null_allow && !$attr->hasSysDefinedDefaultValue();

		//Data type check
		switch($attr->type){
			case Attribute::TYPE_INT:
				if(strlen($value) && !is_numeric($value)){
					$err = $name.'Incorrect format';
				}
				break;

			case Attribute::TYPE_FLOAT:
			case Attribute::TYPE_DOUBLE:
			case Attribute::TYPE_DECIMAL:
				if(!(!$required && !strlen($value.'')) && isset($value) && !is_numeric($value)){
					$err = $name.'Incorrect format';
				}
				break;

			case Attribute::TYPE_ENUM:
				$err = !(!$required && !strlen($value.'')) && !isset($options[$value]) ? 'Please select'.$name : '';
				break;

			case Attribute::TYPE_JSON:
				if(strlen($value) && !is_json($value)){
					$err = $name.'Must be in JSON format';
				}
				break;

			// string is not checked yet
			case Attribute::TYPE_STRING:
				break;
		}

		//Required item check
		if(!$err && $required && !isset($value)){
			$err = "Please enter {$name}";
		}

		//Data length check
		if(!$err && $attr->length && $attr->type && !in_array($attr->type, [
				Attribute::TYPE_DATETIME,
				Attribute::TYPE_DATE,
				Attribute::TYPE_TIME,
				Attribute::TYPE_TIMESTAMP,
			])){
			if($attr->precision){
				$int_len = strlen(substr($value, 0, strpos($value, '.')));
				$precision_len = strpos($value, '.') !== false ? strlen(substr($value, strpos($value, '.') + 1)) : 0;
				if($int_len > $attr->length || $precision_len > $attr->precision){
					$err = "{$name} length exceeds: $value";
				}
			}else{
				//MySQL character calculation uses mb_strlen to calculate the number of characters
				$dsn = static::getDBDriver()->dsn;
				if($attr->type === 'string' && get_class($dsn) == MySQL::class){
					$str_len = mb_strlen($value, 'utf-8');
				}else{
					$str_len = strlen($value);
				}
				$err = $str_len > $attr->length ? "{$name} length exceeds: $value {$str_len} > {$attr->length}" : '';
			}
		}
		return $err;
	}

	/**
	 * Batch insert data
	 * Since the insertion here involves data checking, the final effect is still to insert one by one
	 * @param $data_list
	 * @param bool $break_on_fail
	 * @return array
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 * @throws \Exception
	 */
	public static function insertMany($data_list, $break_on_fail = true){
		if(count($data_list, COUNT_RECURSIVE) == count($data_list)){
			throw new DBException('Two dimension array needed');
		}
		$return_list = [];
		foreach($data_list as $data){
			try{
				$tmp = new static($data);
				$result = $tmp->insert();
				if($result){
					$pk_val = $tmp->getDBDriver()->getLastInsertId();
					$return_list[] = $pk_val;
				}
			}catch(Exception $e){
				if($break_on_fail){
					throw $e;
				}
			}
		}
		return $return_list;
	}

	/**
	 * Fast batch insert of data without ORM check
	 * @param $data_list
	 * @return false|\PDOStatement
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public static function insertManyQuick($data_list){
		if(self::onBeforeChangedGlobal() === false){
			return false;
		}
		return static::getDBDriver()->insert(static::getTableName(), $data_list);
	}

	/**
	 * Delete the record corresponding to the current object from the database
	 * @return bool
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function delete(){
		if($this->onBeforeDelete() === false){
			return false;
		}
		$pk_val = $this[static::getPrimaryKey()];
		$result = static::delByPk($pk_val);
		$this->onAfterDelete();
		return $result;
	}

	/**
	 * Parse conditional expressions in SQL queries
	 * @param array $args The parameter format can be [""], but not ["", "aa"]
	 * @param Model|string $model_class
	 * @return string
	 */
	private static function parseConditionStatement($args, $model_class){
		$statement = $args[0] ?? null;
		$args = array_slice($args, 1);
		if(!empty($args) && $statement){
			$arr = explode('?', $statement);
			$rst = '';
			foreach($args as $key => $val){
				if(is_array($val)){
					array_walk($val, function(&$item) use ($model_class){
						$item = $model_class::getDBDriver(self::OP_READ)->quote($item);
					});

					if(!empty($val)){
						$rst .= $arr[$key].'('.join(',', $val).')';
					}else{
						$rst .= $arr[$key].'(NULL)'; //This will never match, since nothing is equal to null (not even null itself.)
					}
				}else{
					$rst .= $arr[$key].$model_class::getDBDriver(self::OP_READ)->quote($val);
				}
			}
			$rst .= array_pop($arr);
			$statement = $rst;
		}
		return $statement;
	}

	/**
	 * Adjust data length and automatically intercept character strings
	 */
	public function fixedData(){
		$data = $this->getProperties();
		$attributes = static::getAttributes();
		foreach($attributes as $attr){
			if($attr->type === Attribute::TYPE_STRING && isset($data[$attr->name]) && $attr->length){
				$val = mb_substr($data[$attr->name], 0, $attr->length);
				$this->setProperty($attr->name, $val);
			}
		}
	}

	/**
	 * Save the value of the current object after it is changed
	 * @param bool $validate_all whether to validate all data, including readonly data
	 * @return bool
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function save($validate_all = false){
		if($this->onBeforeSave() === false){
			return false;
		}

		if(!$this->property_changes){
			self::getLogger()->warning('no property changes');
			return false;
		}

		$data = $this->getProperties();
		$has_pk = !empty($data[static::getPrimaryKey()]);
		if($has_pk){
			return $this->update($validate_all);
		}else if(!empty($data)){
			return $this->insert($validate_all);
		}
		return false;
	}

	/**
	 * Get the number of impact items
	 * @return int
	 */
	public function getAffectNum(){
		$type = DBQuery::isWriteOperation($this->query) ? self::OP_WRITE : self::OP_READ;
		return static::getDBDriver($type)->getAffectNum();
	}

	/**
	 * Call other methods of the query object
	 * @param string $method_name
	 * @param array $params
	 * @return static|DBQuery
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	final public function __call($method_name, $params){
		if(method_exists($this->query, $method_name)){
			call_user_func_array([$this->query, $method_name], $params);
			return $this;
		}
		throw new DBException("Method no exist:".$method_name);
	}

	/**
	 * Set properties
	 * @param $key
	 * @param $val
	 * @throws \Exception
	 */
	public function __set($key, $val){
		//The value has not changed
		if($this->properties[$key] === $val){
			return;
		}
		$attr = static::getAttributeByName($key);
		if($attr && $attr->setter){
			if(call_user_func($attr->setter, $val, $this) === false){
				return;
			}
		}
		$this->properties[$key] = $val;
		$this->property_changes[] = $key;
	}

	/**
	 * Configuration getter
	 * <p>
	 * Support: 'name' => array(
	 * 'getter' => function($k){}
	 * )
	 * Support: 'name' => array(
	 * 'setter' => function($k, $v){}
	 * )
	 * </p>
	 * @param $key
	 * @return mixed
	 * @throws \Exception
	 */
	public function __get($key){
		$attr = static::getAttributeByName($key);
		if($attr && $attr->getter){
			return call_user_func($attr->getter, $this);
		}
		return $this->properties[$key];
	}

	/**
	 * alias for setProperties
	 * @param $data
	 * @throws \Exception
	 */
	public function setData($data){
		$this->setProperties($data);
	}

	/**
	 * alias for getProperties
	 * @param bool $strict_format Returns whether the data uses strict data types
	 * @return array
	 * @throws \Exception
	 */
	public function getData($strict_format = false){
		return $this->getProperties($strict_format);
	}

	/**
	 * Get attributes
	 * @param bool $strict_format Returns whether the data uses strict data types
	 * @return array
	 * @throws \Exception
	 */
	public function getProperties($strict_format = false){
		$ps = [];
		$attr_map = [];
		if($strict_format){
			$tmp = static::getAttributes();
			foreach($tmp as $at){
				$attr_map[$at->name] = $at;
			}
		}
		foreach($this->properties as $name => $p){
			$val = $this->__get($name);
			if($strict_format && isset($attr_map[$name])){
				$val = Attribute::strictTypeConvert($val, $attr_map[$name]->type);
			}
			$ps[$name] = $val;
		}
		return $ps;
	}

	/**
	 * @param array $properties [key=>value]
	 * @throws \Exception
	 */
	public function setProperties(array $properties){
		foreach($properties as $k => $val){
			$this->__set($k, $val);
		}
	}

	/**
	 * Set properties
	 * @param string $key
	 * @param mixed $value
	 * @throws \Exception
	 */
	public function setProperty($key, $value){
		$this->__set($key, $value);
	}

	/**
	 * Display field values (for fields that cannot be directly displayed)
	 * @param string $attr_name attribute name
	 * @return string The string to be displayed
	 * @throws \Exception
	 */
	public function display($attr_name){
		$attr = self::getAttributeByName($attr_name);
		if(!$attr){
			throw new Exception('No attribute '.$attr_name.' found.');
		}
		$value = $this->{$attr_name};
		return $attr->display($value);
	}

	/**
	 * Clone all results
	 * @param array $override_data overwrite data
	 * @return static[]
	 * @throws \LFPhp\PORM\Exception\DBException
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public function cloneAll($override_data = []){
		$list = $this->all();
		foreach($list as $k => $item){
			$list[$k] = $item->clone($override_data);
		}
		return $list;
	}

	/**
	 * Clone the current object and save it
	 * @param array $override_data overwrite data
	 * @return $this
	 * @throws \LFPhp\PORM\Exception\DBException
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public function clone($override_data = []){
		$pk = static::getPrimaryKey();
		if($pk){
			$this->{$pk} = null;
		}
		foreach($override_data as $k => $v){
			$this->{$k} = $v;
		}
		$this->save();
		return $this;
	}

	/**
	 * Get a list of property changes
	 * @return array [key=>new_data, ...]
	 */
	public function getPropertyChanges(){
		if(!$this->property_changes){
			return [];
		}
		$changes = [];
		foreach($this->property_changes as $attr_key){
			$changes[$attr_key] = $this->properties[$attr_key];
		}
		return $changes;
	}

	public function onBeforeUpdate(){
		return true;
	}

	public function onAfterUpdate(){
	}

	public function onBeforeInsert(){
		return true;
	}

	public function onAfterInsert(){
	}

	public function onBeforeDelete(){
		return true;
	}

	public function onAfterDelete(){
	}

	public function onBeforeSave(){
		return true;
	}

	protected function onBeforeChanged(){
		return true;
	}

	protected static function onBeforeChangedGlobal(){
		return true;
	}

	public function jsonSerialize(){
		return $this->getProperties();
	}

	public function offsetExists($offset){
		return isset($this->properties[$offset]);
	}

	public function offsetGet($offset){
		return $this->properties[$offset];
	}

	public function offsetSet($offset, $value){
		$this->properties[$offset] = $value;
	}

	public function offsetUnset($offset){
		unset($this->properties[$offset]);
	}

	/**
	 * Convert the current query object to a string
	 * @return string
	 */
	public function __toString(){
		return $this->query.'';
	}

	/**
	 * Print Model debugging information
	 * @return array
	 * @throws \Exception
	 */
	public function __debugInfo(){
		$dsn = static::getDSN();
		return [
			'data'              => $this->getProperties(),
			'data_changed_keys' => $this->property_changes,
			'query'             => $this->getQuery().'',
			'database'          => json_encode($dsn->toStringSafe()),
		];
	}

	/**
	 * Get database configuration
	 * This method can be overridden
	 * @param int $operate_type
	 * @return \LFPhp\PDODSN\DSN
	 */
	abstract static public function getDSN($operate_type = self::OP_READ);
}
