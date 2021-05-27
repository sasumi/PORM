<?php
namespace LFPhp\PORM;

use LFPhp\PORM\Exception\Exception;

class Query {
	const SELECT = 'SELECT';
	const UPDATE = 'UPDATE';
	const DELETE = 'DELETE';
	const INSERT = 'INSERT';
	const REPLACE = 'REPLACE';

	const OP_OR = 1;
	const OP_AND = 2;

	const LEFT_JOIN = 1;
	const RIGHT_JOIN = 2;
	const INNER_JOIN = 3;

	public $sql = '';
	public $table_prefix = '';
	public $operation = self::SELECT;
	public $fields = array('*');
	public $tables = array();
	public $joins = array();
	public $where = array();
	public $order = '';
	public $group = '';
	public $limit;
	public $data;

	/**
	 * 构造方法，初始化SQL语句
	 * @param string $sql
	 */
	public function __construct($sql=''){
		$this->sql = $sql;
	}

	/**
	 * 产生字段多重LIKE
	 * @param array $fields
	 * @param array $likes
	 * @return string
	 */
	public static function generateLikes(array $fields, array $likes){
		$query = [];
		foreach($fields as $field){
			foreach($likes as $like){
				$like = addslashes($like);
				$query[] = "$field LIKE '$like'";
			}
		}
		return join(' OR ', $query);
	}

	/**
	 * 设置查询语句
	 * @param $sql
	 * @return $this
	 */
	public function setSql($sql){
		$this->sql = $sql;
		return $this;
	}

	/**
	 * 设置表前缀
	 * @param string $table_prefix
	 * @return $this
	 */
	public function setTablePrefix($table_prefix=''){
		$this->table_prefix = $table_prefix;
		return $this;
	}

	/**
	 * 当前查询是否为全行查询
	 */
	public function isFRQuery(){
		return !$this->sql && $this->fields == array('*');
	}
	
	/**
	 * @param array $fields
	 * @return $this
	 */
	public function select(...$fields){
		$this->operation = self::SELECT;
		$fields = $fields ? $fields : ['*'];
		call_user_func_array([$this, 'fields'], $fields);
		return $this;
	}

	/**
	 * join table on condition
	 * @param $table
	 * @param null $on condition
	 * @param mixed $type join type
	 * @return $this
	 */
	public function join($table, $on, $type){
		$this->joins[] = array($table, $on, $type);
		return $this;
	}

	/**
	 * left join
	 * @param $table
	 * @param null $on
	 * @return static
	 */
	public function leftJoin($table, $on = null){
		return $this->join($table, $on, self::LEFT_JOIN);
	}

	/**
	 * right join
	 * @param $table
	 * @param null $on
	 * @return static
	 */
	public function rightJoin($table, $on = null){
		return $this->join($table, $on, self::RIGHT_JOIN);
	}

	/**
	 * inner join
	 * @param $table
	 * @param null $on
	 * @return static
	 */
	public function innerJoin($table, $on = null){
		return $this->join($table, $on, self::INNER_JOIN);
	}

	/**
	 * update query
	 * @return \LFPhp\PORM\Query $this
	**/
	public function update(){
		$this->operation = self::UPDATE;
		return $this;
	}

	public function replace(){
		$this->operation = self::REPLACE;
		return $this;
	}

	/**
	 * 插入
	 * @return \LFPhp\PORM\Query $this
	 */
	public function insert(){
		$this->operation = self::INSERT;
		return $this;
	}

	/**
	 * 删除
	 * @return \LFPhp\PORM\Query $this
	 */
	public function delete(){
		$this->operation = self::DELETE;
		return $this;
	}

	/**
	 * 设置数据（仅对update, replace, insert有效)
	 * @param array $data
	 * @return \LFPhp\PORM\Query $this
	 */
	public function setData(array $data){
		$this->data = $data;
		return $this;
	}

	/**
	 * 字段
	 * @param array $fields 字符串，或者只使用第一个数组参数
	 * @return \LFPhp\PORM\Model|\LFPhp\PORM\Query
	 */
	public function fields(...$fields){
		if(is_array($fields[0])){
			$fields = $fields[0];
		}
		if(join(',', $fields) == '*'){
			return $this;
		}
		$this->fields = $fields;
		return $this;
	}

	/**
	 * 表
	 * @param string $str
	 * @return \LFPhp\PORM\Query $this
	**/
	public function from($str){
		$tables = explode(',', $str);
		foreach($tables as $key=>$table){
			$tables[$key] = self::escapeKey($this->table_prefix.$table);
		}
		$this->tables = $tables;
		return $this;
	}

	/**
	 * 添加查询条件 <p>
	 * 调用范例：$query->addWhere(null, 'name', 'like', '%john%');
	 * $query->addWhere($conditions);
	 * </p>
	 * @param mixed $arg1 type为数组表示提交多个查询，如果为函数，则表示嵌套查询
	 * @param $field
	 * @param null $operator
	 * @param null $compare
	 */
	public function addWhere($arg1, $field, $operator = null, $compare = null){
		$arg1 = $arg1 ?: self::OP_AND;
		//嵌套子语句模式
		if(is_callable($field)){
			$ws = call_user_func($field);
			$this->where[] = array(
				'type' => $arg1,
				'field' => $this->getWhereStr($ws),
			);
		}

		//二维数组，循环添加
		else if(is_array($arg1) && count($arg1, COUNT_RECURSIVE) != count($arg1)){
			$this->where = array_merge($this->where, $arg1);
		}

		//普通数组模式
		else if(is_array($arg1)){
			$this->where = array_merge($this->where, $arg1);
		}

		//普通模式
		else if($field){
			$this->where[] = array(
				'type' => $arg1,
				'field' => $field,
				'operator' => $operator,
				'compare' => $compare
			);
		}
	}

	/**
	 * alias for and where
	 * @param string $field
	 * @param null $operator
	 * @param null $compare
	 * @return $this
	 */
	public function where($field, $operator=null, $compare=null){
		return $this->andWhere($field, $operator, $compare);
	}

	/**
	 * 设置AND查询条件 <p>
	 * 调用范例：$query->where('age', '>', 18)->where('gender', '=', 'male')->where('name', 'like', '%moon%');
	 * </p>
	 * @param $field
	 * @param null $operator
	 * @param null $compare
	 * @return $this
	 */
	public function andWhere($field, $operator=null, $compare=null){
		$this->addWhere(self::OP_AND, $field, $operator, $compare);
		return $this;
	}

	/**
	 * 设置OR查询条件
	 * @param $field
	 * @param null $operator
	 * @param null $compare
	 */
	public function orWhere($field, $operator=null, $compare=null){
		$this->addWhere(self::OP_OR, $field, $operator, $compare);
	}

	/**
	 * get join query string
	 * @param array $joins
	 * @return mixed
	 */
	private function getJoinStr($joins=array()){
		$str = array();
		foreach($joins ?: $this->joins as $j){
			list($table, $on, $type) = $j;
			switch($type){
				case self::LEFT_JOIN:
					$str[] = 'LEFT JOIN';
					break;
				case self::RIGHT_JOIN:
					$str[] = 'RIGHT JOIN';
					break;

				case self::INNER_JOIN:
				default:
					$str[] = 'INNER JOIN';
			}

			$str[] = self::escapeKey($table);
			if($on){
				$str[] = "ON $on";
			}
		}
		return join("", $str);
	}

	/**
	 * get where string
	 * @param array $wheres
	 * @return string
	 */
	private function getWhereStr(array $wheres=array()){
		$str = '';
		foreach($wheres?:$this->where as $w){
			$f = $w['field'];
			$k = $w['type'] == self::OP_AND ? 'AND' : 'OR';
			if(!empty($w['operator']) && isset($w['compare'])){
				//数组，拼接数组
				if(is_array($w['compare'])){
					if(!empty($w['compare'])){
						foreach($w['compare'] as $_=>$item){
							$w['compare'][$_] = addslashes($item);
						}
						$str .= ($str ? " $k ":'').self::escapeKey($f).' '.$w['operator'].' (\''.join("','",$w['compare']).'\')';
					} else {
						$str .= ($str ? " $k ":'').' FALSE';
					}
				} else {
					$str .= ($str ? " $k ":'').self::escapeKey($f).' '.$w['operator'].' \''.addslashes($w['compare']).'\'';
				}
			} else {
				$str .= ($str ? " $k (":'(').$f.')';
			}
		}
		return $str ? ' WHERE '.$str : '';
	}

	/**
	 * 排序
	 * @param string|array $str
	 * @return static|Query|Model
	 **/
	public function order($str){
		if(is_array($str)){
			$str = join(' ', $str);
		}
		$this->order .= ($this->order ? ',' : '').$str;
		return $this;
	}

	/**
	 * 分组
	 * @param string $str
	 * @return Query|Model
	**/
	public function group($str){
		$this->group = $str;
		return $this;
	}

	/**
	 * 设置查询限制，如果提供的参数为0，则表示不进行限制
	 * @return $this
	 */
	public function limit(/**$p1,$p2**/){
		$tmp = func_get_args();
		$p1 = isset($tmp[0]) ? $tmp[0] : 0;
		$p2 = isset($tmp[1]) ? $tmp[1] : 0;
		if($p2){
			$this->limit = array($p1,$p2);
		} else if(is_array($p1)){
			$this->limit = $p1;
		} else if (is_scalar($p1) && $p1 != 0) {
			$this->limit = array(0, $p1);
		}

		if($this->sql && $this->limit){
			if(preg_match('/\slimit\s/i', $this->sql)){
				$this->sql = preg_replace('/\slimit\s.*$/i', '', $this->sql); //移除原有limit限制信息
			}
			$this->sql = $this->sql . ' LIMIT ' . $this->limit[0] . ',' . $this->limit[1];
		}
		return $this;
	}

	/**
	 * 判断当前操作语句是否为写入语句
	 * @param string|Query $query
	 * @return int
	 */
	public static function isWriteOperation($query = ''){
		return !preg_match('/^select\s/i', trim($query.''));
	}

	/**
	 * 给字段名称添加保护（注意，该保护仅为保护SQL关键字，而非SQL注入保护）
	 * 自动忽略存在空格、其他查询语句的情况
	 * @param $field
	 * @return string|array
	 */
	public static function escapeKey($field){
		if(is_array($field)){
			$ret = array();
			foreach($field as $val){
				$ret[] = (strpos($val, '`') === false && strpos($val, '.') === false && strpos($val, ' ') === false && $val != '*') ? "`$val`" : $val;
			}
			return $ret;
		} else {
			return (strpos($field, '`') === false && strpos($field, '.') === false && strpos($field, ' ') === false && $field != '*') ? "`$field`" : $field;
		}
	}

	/**
	 * 输出SQL查询语句
	 * @return string
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public function __toString(){
		if($this->sql){
			return $this->sql;
		}

		switch($this->operation){
			case self::SELECT:
				$sql = 'SELECT '.implode(',', self::escapeKey($this->fields)).
					' FROM '.implode(',', $this->tables).
					$this->getJoinStr().
					' '.
					$this->getWhereStr().
					($this->group ? ' GROUP BY '.$this->group : '').
					($this->order ? ' ORDER BY '.$this->order : '');
				break;

			case self::DELETE:
				$sql = "DELETE FROM ".implode(',', $this->tables).$this->getWhereStr();
				break;

			case self::INSERT:
				if(!$this->data){
					throw new Exception("No data in database insert operation");
				}
				$data_list = count($this->data) == count($this->data, 1) ? array($this->data) : $this->data;
				$key_str = implode(",", self::escapeKey(array_keys($data_list[0])));
				$sql = "INSERT INTO ".implode(',', $this->tables)."($key_str) VALUES ";
				$comma = '';
				foreach($data_list as $row){
					$str = array();
					foreach($row as $val){
						$str[] = $val !== null ? "'".addslashes($val)."'" : 'null';
					}
					$value_str = implode(",", $str);
					$sql .= $comma."($value_str)";
					$comma = ',';
				}
				break;

			case self::REPLACE:
			case self::UPDATE:
				if(!$this->data){
					throw new Exception("No data in database update operation");
				}
				$data_list = count($this->data) == count($this->data, 1) ? array($this->data) : $this->data;
				$sets = array();
				foreach($data_list as $row){
					$sets = array();
					foreach($row as $field_name => $value){
						$field_name = self::escapeKey($field_name);
						if($value === null){
							$sets[] = "$field_name = NULL";
						} else {
							$sets[] = "$field_name = "."'".addslashes($value)."'";
						}
					}
				}
				$op_key = $this->operation == self::REPLACE ? 'REPLACE INTO' : 'UPDATE';
				$sql = "$op_key ".implode(',', $this->tables).' SET '.implode(',', $sets).$this->getWhereStr();
				break;

			default:
				throw new Exception("No database operation type set");
		}
		if($this->limit && stripos(' LIMIT ', $sql) === false){
			if(!$this->limit[0]){
				$sql .= " LIMIT ".$this->limit[1];
			} else {
				$sql .= " LIMIT ".$this->limit[0].','.$this->limit[1];
			}
		}
		return $sql;
	}
}
