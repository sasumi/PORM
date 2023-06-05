<?php
namespace LFPhp\PORM\ORM;

use ArrayAccess;
use Exception;
use JsonSerializable;
use LFPhp\Logger\Logger;
use LFPhp\PDODSN\Database\MySQL;
use LFPhp\PORM\DB\DBDriver;
use LFPhp\PORM\DB\DBQuery;
use LFPhp\PORM\Exception\DBException;
use LFPhp\PORM\Exception\NotFoundException;
use function LFPhp\Func\array_clear_fields;
use function LFPhp\Func\array_first;
use function LFPhp\Func\array_group;
use function LFPhp\Func\array_index;
use function LFPhp\Func\array_orderby;
use function LFPhp\Func\is_json;
use function LFPhp\Func\time_range_v;

abstract class Model implements JsonSerializable, ArrayAccess {
	const OP_READ = 1;
	const OP_WRITE = 2;

	/** @var \LFPhp\PDODSN\DSN */
	private $dsn;

	/** @var Attribute[] model define */
	protected $attributes = [];

	/** @var array model property key-value set */
	protected $properties = [];

	protected $property_changes = [];

	/** @var DBQuery db query object * */
	private $query = null;

	public function onBeforeUpdate(){return true;}
	public function onAfterUpdate(){}
	public function onBeforeInsert(){return true;}
	public function onAfterInsert(){}
	public function onBeforeDelete(){return true;}
	public function onAfterDelete(){}
	public function onBeforeSave(){return true;}
	protected function onBeforeChanged(){return true;}
	protected static function onBeforeChangedGlobal(){return true;}

	abstract static public function getTableName();

	/**
	 * @return Attribute[]
	 */
	static public function getAttributes(){
		return [];
	}

	/**
	 * DBModel constructor.
	 * @param array $data
	 */
	public function __construct($data = []){
		foreach($data as $k=>$val){
			$this->{$k} = $val;
		}
	}

	/**
	 * 获取数据库表描述名称
	 * @return string
	 */
	public static function getModelDesc(){
		return static::getTableName();
	}

	/**
	 * 获取数据库表名（附带数据库名）
	 * @param int $op_type
	 * @return string
	 * @throws \Exception
	 */
	public static function getTableFullNameWithDbName($op_type = self::OP_READ){
		$dsn = static::getDbDsn($op_type);
		if(!isset($dsn['database'])){
			throw new Exception('DSN no support database');
		}
		$db = $dsn['database'];
		$table = static::getTableName();
		return "`$db`.`$table`";
	}

	/**
	 * 根据字段名获取属性
	 * @param $name
	 * @return Attribute
	 * @throws \Exception
	 */
	public static function getAttributeByName($name){
		$attrs = static::getAttributes();
		if(isset($attrs[$name])){
			return $attrs[$name];
		}
		throw new Exception('No attribute '.$name.' found.');
	}

	/**
	 * 获取数据库表主键
	 * @return string
	 * @throws DBException
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
	 * 获取主键值
	 * @return mixed
	 * @throws DBException
	 */
	public function getPrimaryKeyValue(){
		$pk = static::getPrimaryKey();
		return $this->$pk;
	}

	/**
	 * 获取db记录实例对象
	 * @param int $operate_type
	 * @return DBDriver
	 */
	protected static function getDbDriver($operate_type = self::OP_WRITE){
		$dsn = static::getDbDsn($operate_type);
		return DBDriver::instance($dsn);
	}

	/**
	 * 解释SQL语句
	 * @param string $query
	 * @return array
	 * @throws DBException
	 */
	public static function explainQuery($query){
		return static::getDbDriver(self::OP_READ)->explain($query);
	}

	/**
	 * 获取数据库配置
	 * 该方法可以被覆盖重写
	 * @param int $operate_type
	 * @return \LFPhp\PDODSN\DSN
	 */
	abstract static public function getDbDsn($operate_type = self::OP_READ);

	/**
	 * 设置查询SQL语句
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
	 * 获取当前查询对象
	 * @return \LFPhp\PORM\DB\DBQuery|null
	 */
	public function getQuery(){
		return $this->query;
	}

	/**
	 * 事务处理
	 * @param callable $handler 处理函数，若函数返回false或抛出Exception，将停止提交，执行事务回滚
	 * @return mixed 闭包函数返回值透传
	 * @throws \Exception
	 */
	public static function transaction($handler){
		$driver = static::getDbDriver(Model::OP_WRITE);
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
	 * 执行当前查询
	 * @return \PDOStatement
	 * @throws DBException
	 */
	public function execute(){
		$type = DBQuery::isWriteOperation($this->query) ? self::OP_WRITE : self::OP_READ;
		return static::getDbDriver($type)->query($this->query);
	}

	/**
	 * 查找
	 * @param string $statement 条件表达式
	 * @param string $var,... 条件表达式扩展
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
	 * 添加更多查询条件
	 * @param array $args 查询条件
	 * @return static|DBQuery
	 */
	public function where(...$args){
		$statement = self::parseConditionStatement($args, static::class);
		$this->query->where($statement);
		return $this;
	}

	/**
	 * 快速查询用户请求过来的信息，只有第二个参数为不为空的时候才去查询，空数组还是会去查。
	 * @param string $st
	 * @param string|int|array|null $val
	 * @return static
	 */
	public function whereOnSet($st, $val){
		$args = func_get_args();
		foreach($args as $k=>$arg){
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
	 * 自动检测变量类型、变量值设置匹对记录
	 * 当前操作仅做“等于”，“包含”比对，不做其他比对
	 * @param string[] $fields
	 * @param string[]|number[] $param
	 * @return $this
	 */
	public function whereEqualOnSetViaFields(array $fields, array $param = []){
		foreach($fields as $field){
			$val = $param[$field];
			if(is_array($val) || strlen($val)){
				$comparison =  is_array($val) ? 'IN' : '=';
				$this->whereOnSet("$field $comparison ?", $val);
			}
		}
		return $this;
	}

	/**
	 * 快速LIKE查询用户请求过来的信息，当LIKE内容为空时，不执行查询，如 %%。
	 * @param string $st
	 * @param string|number $val
	 * @return static|DBQuery
	 */
	public function whereLikeOnSet($st, $val){
		$args = func_get_args();
		if(strlen(trim(str_replace(DBDriver::LIKE_RESERVED_CHARS, '', $val)))){
			return call_user_func_array(array($this, 'whereOnSet'), $args);
		}
		return $this;
	}

	/**
	 * 批量LIKE查询（whereLikeOnSet方法快捷用法）
	 * @param array $fields
	 * @param $val
	 * @return static
	 */
	public function whereLikeOnSetBatch(array $fields, $val){
		$st = join(' LIKE ? OR ', $fields).' LIKE ?';
		$values = array_fill(0, count($fields), $val);
		array_unshift($values, $st);
		return call_user_func_array([$this, 'whereLikeOnSet'], $values);
	}

	/**
	 * 检测字段是否处于指定范围之中
	 * @param string $field
	 * @param number|null $min 最小端
	 * @param number|null $max 最大端
	 * @param bool $equal_cmp 是否包含等于
	 * @return DBQuery|static
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
	 * 创建新对象
	 * @param $data
	 * @return bool|static
	 * @throws DBException
	 */
	public static function create($data){
		$obj = new static();
		foreach($data as $k=>$v){
			$obj->{$k} = $v;
		}
		return $obj->save() ? $obj : false;
	}

	/**
	 * 由主键查询一条记录
	 * @param string $val
	 * @param bool $as_array
	 * @return static|DBQuery|array
	 * @throws DBException
	 */
	public static function findOneByPk($val, $as_array = false){
		$pk = static::getPrimaryKey();
		return static::find($pk.'=?', $val)->one($as_array);
	}

	/**
	 * @param $val
	 * @param bool $as_array
	 * @return static
	 * @throws DBException
	 */
	public static function findOneByPkOrFail($val, $as_array = false){
		$data = static::findOneByPk($val, $as_array);
		if(!$data){
			throw new NotFoundException('找不到相关数据(pk:'.$val.')。');
		}
		return $data;
	}

	/**
	 * 有主键列表查询多条记录
	 * 单主键列表为空，该方法会返回空数组结果
	 * @param array $pk_values
	 * @param bool $as_array
	 * @return static[]
	 * @throws DBException
	 */
	public static function findByPks(array $pk_values, $as_array = false){
		if(!$pk_values){
			return [];
		}
		$pk = static::getPrimaryKey();
		return static::find("`$pk` IN ?", $pk_values)->all($as_array);
	}

	/**
	 * 根据主键值删除一条记录
	 * @param string $val
	 * @return int
	 * @throws DBException
	 */
	public static function delByPk($val){
		$pk = static::getPrimaryKey();
		static::deleteWhere(0, "`$pk`=?", $val);
		return (new static)->getAffectNum();
	}

	/**
	 * 根据主键删除记录
	 * @param $val
	 * @return int
	 * @throws DBException
	 * @throws \LFPhp\PORM\Exception\NotFoundException
	 */
	public static function delByPkOrFail($val){
		static::delByPk($val);
		$count = (new static)->getAffectNum();
		if(!$count){
			throw new NotFoundException('记录已被删除');
		}
		return $count;
	}

	/**
	 * 根据主键值更新记录
	 * @param string $val 主键值
	 * @param array $data
	 * @return bool
	 * @throws DBException
	 */
	public static function updateByPk($val, array $data){
		$pk = static::getPrimaryKey();
		return static::updateWhere($data, 1, "`$pk` = ?", $val);
	}

	/**
	 * 根据主键值更新记录
	 * @param string[]|number[] $pks
	 * @param array $data
	 * @return bool
	 * @throws DBException
	 */
	public static function updateByPks($pks, array $data){
		$pk = static::getPrimaryKey();
		return static::updateWhere($data, count($pks), "`$pk` IN ?", $pks);
	}

	/**
	 * 根据条件更新数据
	 * @param array $data
	 * @param int $limit 为了安全，调用方必须传入具体数值，如不限制更新数量，可设置为0
	 * @param string $statement 为了安全，调用方必须传入具体条件，如不限制，可设置为空字符串
	 * @return bool;
	 * @throws DBException
	 */
	public static function updateWhere(array $data, $limit, $statement){
		if(self::onBeforeChangedGlobal() === false){
			return false;
		}

		$args = func_get_args();
		$args = array_slice($args, 2);
		$statement = self::parseConditionStatement($args, static::class);
		$table = static::getTableName();
		return static::getDbDriver(self::OP_WRITE)->update($table, $data, $statement, $limit);
	}

	/**
	 * 根据条件从表中删除记录
	 * @param int $limit 为了安全，调用方必须传入具体数值，如不限制删除数量，可设置为0
	 * @param string $statement 为了安全，调用方必须传入具体条件，如不限制，可设置为空字符串
	 * @return bool
	 * @throws DBException
	 */
	public static function deleteWhere($limit, $statement){
		$args = func_get_args();
		$args = array_slice($args, 1);

		$statement = self::parseConditionStatement($args, static::class);
		return static::getDbDriver(self::OP_WRITE)
			->delete(static::getTableName(), $statement, $limit);
	}

	/**
	 * 清空数据
	 * @return bool
	 * @throws DBException
	 */
	public static function truncate(){
		$table = static::getTableName();
		return static::getDbDriver(self::OP_WRITE)->delete($table, '', 0);
	}

	/**
	 * 获取所有记录
	 * @param bool $as_array return as array
	 * @param string $unique_key 用于组成返回数组的唯一性key
	 * @return static[]
	 * @throws DBException
	 */
	public function all($as_array = false, $unique_key = ''){
		$list = static::getDbDriver(self::OP_READ)->getAll($this->query);
		if(!$list){
			return [];
		}
		return $this->handleListResult($list, $as_array, $unique_key);
	}

	/**
	 * 分页查询记录
	 * @param string $page
	 * @param bool $as_array 是否以数组方式返回，默认为Model对象数组
	 * @param string $unique_key 用于组成返回数组的唯一性key
	 * @return static[]
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function paginate($page = null, $as_array = false, $unique_key = ''){
		$list = static::getDbDriver(self::OP_READ)->getPage($this->query, $page);
		return $this->handleListResult($list, $as_array, $unique_key);
	}

	/**
	 * 格式化数据列表，预取数据
	 * @param static[]|array[] $list
	 * @param bool $as_array 是否作为二维数组返回，默认为对象数组
	 * @param string $unique_key 数组下标key
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
			} else{
				$result[] = $tmp;
			}
		}
		return $result;
	}

	/**
	 * 获取一条记录
	 * @param bool $as_array 是否以数组方式返回，默认为Model对象
	 * @return static|array|null
	 * @throws DBException
	 */
	public function one($as_array = false){
		$data = static::getDbDriver(self::OP_READ)->getOne($this->query);
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
	 * 获取一条记录，为空时抛异常
	 * @param bool $as_array 是否以数组方式返回，默认为Model对象
	 * @return static
	 * @throws DBException
	 * @throws \LFPhp\PORM\Exception\NotFoundException
	 */
	public function oneOrFail($as_array = false){
		$data = $this->one($as_array);
		if(!$data){
			throw new NotFoundException('找不到相关数据。'.$this->query, null, null, $this->query);
		}
		return $data;
	}

	/**
	 * 获取一个记录字段
	 * @param string|null $key 如字段为空，则取第一个结果
	 * @return mixed|null
	 * @throws DBException
	 */
	public function ceil($key = ''){
		$attr_names = static::getEntityAttributeNames();
		if($key && in_array($key, $attr_names)){
			$this->query->field($key);
		}
		$data = static::getDbDriver(self::OP_READ)->getOne($this->query);
		return $data ? array_pop($data) : null;
	}

	/**
	 * 获取实体属性名列表
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
	 * 获取实体属性列表
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
	 * 计算字段值总和
	 * @param string|array $fields 需要计算字段名称（列表）
	 * @param array $group_by 使用指定字段（列表）作为合并维度
	 * @return number|array 结果总和，或以指定字段列表作为下标的结果总和
	 * @throws DBException
	 * @example
	 * <pre>
	 * $report->sum('order_price', 'original_price');
	 * $report->group('platform')->sum('order_price');
	 * sum('price'); //10.00
	 * sum(['price','count']); //[10.00, 14]
	 * sum(['price', 'count'], ['platform','order_type']); //
	 * [
	 *  ['platform,order_type'=>'amazon', 'price'=>10.00, 'count'=>14],
	 *  ['platform'=>'ebay', 'price'=>10.00, 'count'=>14],...
	 * ]
	 * sum(['price', 'count'], ['platform', 'order_type']);
	 * </pre>
	 */
	public function sum($fields, $group_by=[]){
		$fields = is_array($fields)?$fields:[$fields];
		$str = [];
		foreach($fields as $field){
			$str[] = "SUM(`$field`) as $field";
		}

		if($group_by){
			$str = array_merge($str,$group_by);
			$this->query->group(implode(',',$group_by));
		}
		$this->query->fields($str);

		$data = static::getDbDriver(self::OP_READ)->getAll($this->query);
		if($group_by){
			return $data;
		}
		if(count($fields) == 1){
			return array_first(array_first($data));
		} else {
			return array_values(array_first($data));
		}
	}

	/**
	 * 对象重排序
	 * 列表中以数值从大到小进行排序，每次调用将重新计算所有排序
	 * 用法：<pre>
	 * $category->render(true, 'sort', 'status=?', $enabled);
	 * </pre>
	 * @param bool $move_up 是否为向上移动
	 * @param string $sort_key 排序字段名称，默认为sort
	 * @param string $statement 排序范围过滤表达式，默认为所有数据
	 * @return bool
	 * @throws DBException
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

		//已经是置顶或者置底
		if($move_up && $current_idx == 0 || (!$move_up && $current_idx == $count-1)){
			return true;
		}

		if($move_up){
			$tmp = $sort_list[$current_idx-1];
			$sort_list[$current_idx-1] = $sort_list[$current_idx];
			$sort_list[$current_idx] = $tmp;
		} else {
			$tmp = $sort_list[$current_idx+1];
			$sort_list[$current_idx+1] = $sort_list[$current_idx];
			$sort_list[$current_idx] = $tmp;
		}

		//force reordering
		foreach($sort_list as $k => $v){
			static::updateWhere([$sort_key => $count-$k-1], 1, "`$pk` = ?", $v[$pk]);
		}
		return true;
	}

	/**
	 * 获取指定列，作为一维数组返回
	 * @param $key
	 * @return array
	 * @throws DBException
	 */
	public function column($key){
		$this->query->field($key);
		$data = static::getDbDriver(self::OP_READ)->getAll($this->query);
		return $data ? array_column($data, $key) : [];
	}

	/**
	 * 以映射数组方式返回
	 * <pre>
	 * $query->map('id', 'name'); //返回： [[id_val=>name_val],...] 格式数据
	 * $query->map('id', ['name']); //返回： [[id_val=>[name=>name_val],...] 格式数据
	 * $query->map('id', ['name', 'gender']); //返回： [[id_val=>[name=>name_val, gender=>gender_val],...] 格式数据
	 * </pre>
	 * @param $key
	 * @param $val
	 * @return array
	 * @throws DBException
	 */
	public function map($key, $val){
		if(is_string($val)){
			$this->query->field($key, $val);
			$tmp = static::getDbDriver(self::OP_READ)->getAll($this->query);
			return array_combine(array_column($tmp, $key), array_column($tmp, $val));
		} else if(is_array($val)){
			$tmp = $val;
			$tmp[] = $key;
			$this->query->fields($tmp);
			$tmp = static::getDbDriver(self::OP_READ)->getAll($this->query);
			$ret = [];
			foreach($tmp as $item){
				$ret[$item[$key]] = [];
				foreach($val as $field){
					$ret[$item[$key]][$field] = $item[$field];
				}
			}
			return $ret;
		}
		throw new DBException("Mapping parameter error: [$key, $val]", null, null, static::getDbDsn(self::OP_READ));
	}

	/**
	 * 根据分段进行数据处理，常见用于节省WebServer内存操作
	 * @param int $size 分块大小
	 * @param callable $handler 回调函数
	 * @param bool $as_array 查询结果作为数组格式回调
	 * @return bool 是否执行了分块动作
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
		while($start<$total){
			$data = $this->paginate(array($start, $size), $as_array);
			if(call_user_func($handler, $data, $page_index++, $page_total, $total) === false){
				break;
			}
			$start += $size;
		}
		return true;
	}

	/**
	 * 数据记录监听
	 * @param callable $handler 处理函数，若返回false，则终端监听
	 * @param int $chunk_size 获取数据时的分块大小
	 * @param int $sleep_interval_sec 无数据时睡眠时长（秒）
	 * @param bool|callable|null $debugger 数据信息调试器
	 * @return bool 是否正常执行
	 * @throws DBException|\LFPhp\PORM\Exception\Exception
	 */
	public function watch(callable $handler, $chunk_size = 50, $sleep_interval_sec = 3, $debugger = true){
		if($debugger === true) {
			$debugger = function(...$args){
				echo "\n".date('Y-m-d H:i:s')."\t".join("\t", func_get_args());
			};
		} else if(!$debugger || !is_callable($debugger)){
			$debugger = function(){};
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
					$cur = $page_index*$chunk_size+$k+1;
					$now = microtime(true);
					$left = ($now-$start)*($item_total-$cur)/$cur;
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
				$debugger('No data found, sleep for '.$sleep_interval_sec.' seconds.');
				sleep($sleep_interval_sec);
			}
		}
		if($cache_on){
			DBDriver::setQueryCacheOn();
		}
		return true;
	}

	/**
	 * 获取当前查询条数
	 * @return int
	 * @throws DBException
	 */
	public function count(){
		$driver = static::getDbDriver(self::OP_READ);
		return $driver->getCount($this->query);
	}

	/**
	 * 更新当前对象
	 * @param bool $flush_all 是否刷新全部数据，包含readonly数据
	 * @return bool|number
	 * @throws DBException
	 */
	public function update($flush_all = false){
		if($this->onBeforeUpdate() === false || $this->onBeforeChanged() === false){
			return false;
		}

		$data = $this->properties;
		$pk = static::getPrimaryKey();

		//只更新改变的值
		$data = array_clear_fields($this->property_changes, $data);
		$data = $this->validate($data, DBQuery::UPDATE, $flush_all);
		static::getDbDriver(self::OP_WRITE)->update(static::getTableName(), $data, static::getPrimaryKey().'='.$this->$pk);
		$this->onAfterUpdate();
		$this->property_changes = [];
		return $this->{static::getPrimaryKey()};
	}

	/**
	 * 插入当前对象
	 * @param bool $flush_all 是否刷新全部数据，包含readonly数据
	 * @return string|bool 返回插入的id，或者失败(false)
	 * @throws DBException
	 */
	public function insert($flush_all = false){
		if($this->onBeforeInsert() === false || $this->onBeforeChanged() === false){
			return false;
		}
		$data = $this->properties;
		$data = $this->validate($data, DBQuery::INSERT, $flush_all);
		$result = static::getDbDriver(self::OP_WRITE)->insert(static::getTableName(), $data);
		if($result){
			$pk_val = static::getDbDriver(self::OP_WRITE)->getLastInsertId();
			$pk = static::getPrimaryKey();
			$this->{$pk} = $pk_val;
			$this->onAfterInsert();
			return $pk_val;
		}
		return false;
	}

	/**
	 * 替换数据
	 * @param array $data
	 * @param int $limit
	 * @param array ...$args 查询条件
	 * @return mixed
	 * @throws DBException
	 */
	public static function replace(array $data, $limit = 0, ...$args){
		$statement = self::parseConditionStatement($args, static::class);
		$table = static::getTableName();
		return static::getDbDriver(self::OP_WRITE)->replace($table, $data, $statement, $limit);
	}

	/**
	 * 增加或减少计数
	 * @param string $field 计数使用的字段
	 * @param int $offset 计数偏移量，如1，-1
	 * @param int $limit 条数限制，默认为0表示不限制更新条数
	 * @param array ...$args 查询条件
	 * @return int
	 * @throws DBException
	 */
	public static function increase($field, $offset, $limit = 0, ...$args){
		$statement = self::parseConditionStatement($args, static::class);
		$table = static::getTableName();
		return static::getDbDriver(self::OP_WRITE)->increase($table, $field, $offset, $statement, $limit);
	}

	/**
	 * 数据校验
	 * @param array $src_data 元数据
	 * @param string $query_type 数据库操作类型
	 * @param bool $flush_all 是否校验全部数据，包含readonly数据
	 * @return array $data
	 * @throws DBException
	 */
	private function validate($src_data = [], $query_type = DBQuery::INSERT, $flush_all = false){
		$attrs = self::getEntityAttributes();
		$attr_maps = [];
		foreach($attrs as $attr){
			$attr_maps[$attr->name] = $attr;
		}
		$pk = static::getPrimaryKey();

		//转换set数据
		foreach($src_data as $k => $d){
			if($attr_maps[$k]->type == Attribute::TYPE_SET && is_array($d)){
				$src_data[$k] = join(',', $d);
			}
		}

		//移除矢量数值
		$data = array_filter($src_data, function($item){
			return is_scalar($item) || is_null($item);
		});

		//unique校验
		foreach($src_data as $field=>$_){
			$attr = $attr_maps[$field];
			if($attr->is_unique){
				if($query_type == DBQuery::INSERT){
					$count = $this::find("`$field`=?", $data[$field])->count();
				} else{
					$count = $this::find("`$field`=? AND `$pk` <> ?", $data[$field], $this->$pk)->count();
				}
				if($count){
					throw new DBException("{$attr->alias}：{$data->{$field}}已经存在，不能重复添加");
				}
			}
		}

		//移除readonly属性
		if(!$flush_all){
			$attr_maps = array_filter($attr_maps, function($attr){
				return !$attr->is_readonly;
			});
		}

		//清理无用数据
		$data = array_clear_fields(array_keys($attr_maps), $data);

		//插入时填充default值
		array_walk($attr_maps, function($attr, $k) use (&$data, $query_type){
			/**
			 * 没有设置数据，属性定义没有默认值情况，需要填充定义默认值
			 * @var Attribute $attr
			 */
			if(!isset($data[$k]) && $attr->hasUserDefinedDefaultValue()){
				if($query_type == DBQuery::INSERT){
					if(!isset($data[$k])){ //允许提交空字符串
						$data[$k] = $attr->default;
					}
				} else if(isset($data[$k]) && !strlen($data[$k])){
					$data[$k] = $attr->default;
				}
			}
		});

		//更新时，只需要处理更新数据的属性
		if($query_type == DBQuery::UPDATE || $query_type == DBQuery::REPLACE){
			foreach($attr_maps as $k => $attr){
				if(!isset($data[$k])){
					unset($attr_maps[$k]);
				}
			}
		}

		//属性校验
		foreach($attr_maps as $k => $attr){
			if(!$attr->is_readonly || $flush_all){
				if($msg = $this->validateField($attr, $data[$k])){
					throw new DBException($msg, null, null, array('field' => $k, 'value' =>$data[$k], 'row' => $data));
				}
			}
		}
		return $data;
	}

	/**
	 * 字段校验
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

		//数据类型检查
		switch($attr->type){
			case Attribute::TYPE_INT:
				if(strlen($value) && !is_numeric($value)){
					$err = $name.'格式不正确';
				}
				break;

			case Attribute::TYPE_FLOAT:
			case Attribute::TYPE_DOUBLE:
			case Attribute::TYPE_DECIMAL:
				if(!(!$required && !strlen($value.'')) && isset($value) && !is_numeric($value)){
					$err = $name.'格式不正确';
				}
				break;

			case Attribute::TYPE_ENUM:
				$err = !(!$required && !strlen($value.'')) && !isset($options[$value]) ? '请选择'.$name : '';
				break;

			case Attribute::TYPE_JSON:
				if(strlen($value) && !is_json($value)){
					$err = $name.'必须为JSON格式';
				}
				break;

			//string暂不校验
			case Attribute::TYPE_STRING:
				break;
		}

		//必填项检查
		if(!$err && $required && !isset($value)){
			$err = "请输入{$name}";
		}

		//数据长度检查
		if(!$err && $attr->length && $attr->type && !in_array($attr->type, [
				Attribute::TYPE_DATETIME,
				Attribute::TYPE_DATE,
				Attribute::TYPE_TIME,
				Attribute::TYPE_TIMESTAMP
			])){
			if($attr->precision){
				$int_len = strlen(substr($value, 0, strpos($value, '.')));
				$precision_len = strpos($value, '.') !== false ? strlen(substr($value, strpos($value, '.') + 1)) : 0;
				if($int_len > $attr->length || $precision_len > $attr->precision){
					$err = "{$name}长度超出：$value";
				}
			}else{
				//mysql字符计算采用mb_strlen计算字符个数
				$dsn = static::getDbDriver(self::OP_WRITE)->dsn;
				if($attr->type === 'string' && get_class($dsn) == MySQL::class){
					$str_len = mb_strlen($value, 'utf-8');
				}else{
					$str_len = strlen($value);
				}
				$err = $str_len > $attr->length ? "{$name}长度超出：$value {$str_len} > {$attr->length}" : '';
			}
		}
		return $err;
	}

	/**
	 * 批量插入数据
	 * 由于这里插入会涉及到数据检查，最终效果还是一条一条的插入
	 * @param $data_list
	 * @param bool $break_on_fail
	 * @return array
	 * @throws DBException
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
					$pk_val = $tmp->getDbDriver(self::OP_WRITE)->getLastInsertId();
					$return_list[] = $pk_val;
				}
			} catch(Exception $e){
				if($break_on_fail){
					throw $e;
				}
			}
		}
		return $return_list;
	}

	/**
	 * 快速批量插入数据，不进行ORM检查
	 * @param $data_list
	 * @return false|\PDOStatement
	 * @throws DBException
	 */
	public static function insertManyQuick($data_list){
		if(self::onBeforeChangedGlobal() === false){
			return false;
		}
		return static::getDbDriver(self::OP_WRITE)->insert(static::getTableName(), $data_list);
	}

	/**
	 * 从数据库从删除当前对象对应的记录
	 * @return bool
	 * @throws DBException
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
	 * 解析SQL查询中的条件表达式
	 * @param array $args 参数形式可为 [""],但不可为 ["", "aa"] 这种传参
	 * @param Model|string $model_class
	 * @return string
	 */
	private static function parseConditionStatement($args, $model_class){
		$statement = isset($args[0]) ? $args[0] : null;
		$args = array_slice($args, 1);
		if(!empty($args) && $statement){
			$arr = explode('?', $statement);
			$rst = '';
			foreach($args as $key => $val){
				if(is_array($val)){
					array_walk($val, function(&$item) use ($model_class){
						$item = $model_class::getDbDriver(self::OP_READ)->quote($item);
					});

					if(!empty($val)){
						$rst .= $arr[$key].'('.join(',', $val).')';
					} else{
						$rst .= $arr[$key].'(NULL)'; //This will never match, since nothing is equal to null (not even null itself.)
					}
				} else{
					$rst .= $arr[$key].$model_class::getDbDriver(self::OP_READ)->quote($val);
				}
			}
			$rst .= array_pop($arr);
			$statement = $rst;
		}
		return $statement;
	}

	/**
	 * 保存当前对象变更之后的数值
	 * @param bool $flush_all 是否刷新全部数据，包含readonly数据
	 * @return bool
	 * @throws DBException
	 */
	public function save($flush_all = false){
		if($this->onBeforeSave() === false){
			return false;
		}

		if(!$this->property_changes){
			Logger::instance()->warning('no property changes');
			return false;
		}

		$data = $this->properties;
		$has_pk = !empty($data[static::getPrimaryKey()]);
		if($has_pk){
			return $this->update($flush_all);
		} else if(!empty($data)){
			return $this->insert($flush_all);
		}
		return false;
	}

	/**
	 * 获取影响条数
	 * @return int
	 */
	public function getAffectNum(){
		$type = DBQuery::isWriteOperation($this->query) ? self::OP_WRITE : self::OP_READ;
		return static::getDbDriver($type)->getAffectNum();
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
	 * 调用查询对象其他方法
	 * @param string $method_name
	 * @param array $params
	 * @return static
	 * @throws DBException
	 */
	final public function __call($method_name, $params){
		if(method_exists($this->query, $method_name)){
			call_user_func_array(array($this->query, $method_name), $params);
			return $this;
		}
		throw new DBException("Method no exist:".$method_name);
	}

	/**
	 * 设置属性
	 * @param $key
	 * @param $val
	 */
	public function __set($key, $val){
		//数值没有改变
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
	 * 配置getter
	 * <p>
	 * 支持：'name' => array(
	 *     'getter' => function($k){}
	 * )
	 * 支持：'name' => array(
	 *    'setter' => function($k, $v){}
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
	 * 转换当前查询对象为字符串
	 * @return string
	 */
	public function __toString(){
		return $this->query.'';
	}

	/**
	 * 打印Model调试信息
	 * @return array
	 */
	public function __debugInfo(){
		$dsn = $this->getDbDsn();
		return [
			'data'              => $this->properties,
			'data_changed_keys' => $this->property_changes,
			'query'             => $this->getQuery().'',
			'database'          => json_encode($dsn),
		];
	}

	public function jsonSerialize(){
		return $this->properties;
	}
}
