<?php
namespace LFPhp\PORM\DB;

use LFPhp\Logger\LoggerTrait;
use LFPhp\PORM\Exception\DBException;
use LFPhp\PORM\Exception\Exception;

class DBQuery {
	use LoggerTrait;

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
	public $operation = self::SELECT;
	public $fields = [];
	public $tables = [];
	public $joins = [];
	public $where = [];
	public $order = '';
	public $group = '';
	public $limit;
	public $data;

	/**
	 * Constructor, initialize SQL statement
	 * @param string $sql
	 */
	public function __construct($sql = ''){
		$this->sql = $sql;
		$this->operation = self::operationResolve($sql);
	}

	/**
	 * Generate multiple LIKE fields
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
	 * Parse sql type
	 * @param string $sql
	 * @return string|null
	 */
	private static function operationResolve($sql){
		$sql = trim($sql);
		if(preg_match('/^(\w+)\s/', $sql, $matches)){
			$key_mapping = [
				'SELECT' => self::SELECT,
				'UPDATE' => self::UPDATE,
				'DELETE' => self::DELETE,
				'INSERT' => self::INSERT,
				'REPLACE' => self::REPLACE,
			];
			$ms = strtoupper($matches[1]);
			if(isset($key_mapping[$ms])){
				return $key_mapping[$ms];
			}
		}
		return null;
	}

	/**
	 * Set the query statement
	 * @param $sql
	 */
	public function setSql($sql){
		$this->__construct($sql);
	}

	/**
	 * Whether the current query is a full-row query
	 */
	public function isFRQuery(){
		return !$this->sql && (!$this->fields || $this->fields == array('*'));
	}

	/**
	 * @param string[] $fields
	 * @return $this
	 */
	public function select(...$fields){
		$this->operation = self::SELECT;
		if($fields){
			$this->fields($fields);
		}
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
	 * @return \LFPhp\PORM\DB\DBQuery $this
	 **/
	public function update() {
		$this->operation = self::UPDATE;
		return $this;
	}

	public function replace() {
		$this->operation = self::REPLACE;
		return $this;
	}

	/**
	 * Insert
	 * @return \LFPhp\PORM\DB\DBQuery $this
	 */
	public function insert() {
		$this->operation = self::INSERT;
		return $this;
	}

	/**
	 * delete
	 * @return \LFPhp\PORM\DB\DBQuery $this
	 */
	public function delete() {
		$this->operation = self::DELETE;
		return $this;
	}

	/**
	 * Set data (only valid for update, replace, insert)
	 * @param array $data
	 * @return \LFPhp\PORM\DB\DBQuery $this
	 */
	public function setData(array $data){
		$this->data = $data;
		return $this;
	}

	/**
	 * Add filter fields
	 * @param array $fields string, or just use the first array parameter
	 * @return \LFPhp\PORM\ORM\Model|\LFPhp\PORM\DB\DBQuery
	 */
	public function fields($fields){
		$this->fields = array_merge($this->fields, $fields);
		return $this;
	}

	public function field(...$fields){
		return $this->fields($fields);
	}

	/**
	 * surface
	 * @param string $str
	 * @return \LFPhp\PORM\DB\DBQuery $this
	 **/
	public function from($str){
		$tables = explode(',', $str);
		foreach($tables as $key => $table){
			$tables[$key] = self::escapeKey($table);
		}
		$this->tables = $tables;
		return $this;
	}

	/**
	 * Add query conditions<p>
	 * Calling example: $query->addWhere(null, 'name', 'like', '%john%');
	 * $query->addWhere($conditions);
	 * </p>
	 * @param mixed $arg1 type is an array, which means submitting multiple queries. If it is a function, it means nested queries.
	 * @param $field
	 * @param null $operator
	 * @param null $compare
	 */
	public function addWhere($arg1, $field, $operator = null, $compare = null){
		$arg1 = $arg1 ?: self::OP_AND;
		//Nested sub-statement mode
		if(is_callable($field)){
			$ws = call_user_func($field);
			$this->where[] = array(
				'type' => $arg1,
				'field' => $this->getWhereStr($ws),
			);
		}//Two-dimensional array, loop addition
		else if(is_array($arg1) && count($arg1, COUNT_RECURSIVE) != count($arg1)){
			$this->where = array_merge($this->where, $arg1);
		}//Normal array mode
		else if(is_array($arg1)){
			$this->where = array_merge($this->where, $arg1);
		}//Normal mode
		else if($field){
			$this->where[] = array(
				'type' => $arg1,
				'field' => $field,
				'operator' => $operator,
				'compare' => $compare,
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
	public function where($field, $operator = null, $compare = null){
		return $this->andWhere($field, $operator, $compare);
	}

	/**
	 * Set AND query condition<p>
	 * Calling example: $query->where('age', '>', 18)->where('gender', '=', 'male')->where('name', 'like', '% moon%');
	 * </p>
	 * @param $field
	 * @param null $operator
	 * @param null $compare
	 * @return $this
	 */
	public function andWhere($field, $operator = null, $compare = null){
		$this->addWhere(self::OP_AND, $field, $operator, $compare);
		return $this;
	}

	/**
	 * Set OR query condition
	 * @param $field
	 * @param null $operator
	 * @param null $compare
	 */
	public function orWhere($field, $operator = null, $compare = null){
		$this->addWhere(self::OP_OR, $field, $operator, $compare);
	}

	/**
	 * get join query string
	 * @param array $joins
	 * @return string
	 */
	private function getJoinStr($joins = []){
		$str = [];
		foreach($joins ?: $this->joins as $j){
			[$table, $on, $type] = $j;
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
	private function getWhereStr(array $wheres = []){
		$str = '';
		foreach($wheres ?: $this->where as $w){
			$f = $w['field'];
			$k = $w['type'] == self::OP_AND ? 'AND' : 'OR';
			if(!empty($w['operator']) && isset($w['compare'])){
				//Array, concatenate arrays
				if(is_array($w['compare'])){
					if(!empty($w['compare'])){
						foreach($w['compare'] as $_ => $item){
							$w['compare'][$_] = addslashes($item);
						}
						$str .= ($str ? " $k " : '').self::escapeKey($f).' '.$w['operator'].' (\''.join("','" , $w['compare']).'\')';
					}else{
						$str .= ($str ? " $k " : '').' FALSE';
					}
				}else{
					$str .= ($str ? " $k " : '').self::escapeKey($f).' '.$w['operator'].' \''.addslashes($w['compare' ]).'\'';
				}
			}else{
				$str .= ($str ? " $k (" : '(').$f.')';
			}
		}
		return $str ? ' WHERE '.$str : '';
	}

	/**
	 * Sorting
	 * @param string|array $str
	 * @return DBQuery|static
	 **/
	public function order($str){
		if(is_array($str)){
			$str = join(' ', $str);
		}
		$this->order .= ($this->order ? ',' : '').$str;
		return $this;
	}

	/**
	 * order by values
	 * @param string $field
	 * @param array $values
	 * @return $this|\LFPhp\PORM\DB\DBQuery
	 */
	public function orderByValues($field, array $values){
		if(!$values){
			return $this;
		}
		return $this->order("FIELD(".self::escapeKey($field).", '".join("','", $values)."')");
	}

	/**
	 * Grouping
	 * @param string $str
	 * @return DBQuery
	 **/
	public function group($str){
		$this->group = $str;
		return $this;
	}

	/**
	 * Set query limits. If the provided parameter is 0, no limit is imposed.
	 * @return $this
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public function limit(/**$p1,$p2**/){
		$tmp = func_get_args();
		$p1 = $tmp[0] ?? 0;
		$p2 = $tmp[1] ?? 0;
		if($p2){
			$this->limit = array($p1, $p2);
		}else if(is_array($p1)){
			$this->limit = $p1;
		}else if(is_scalar($p1) && $p1 != 0){
			$this->limit = array(0, $p1);
		}
		if($this->sql && $this->limit){
			$this->sql = self::patchLimitation($this->sql, $this->limit);
		}
		return $this;
	}

	/**
	 * Parse limit information in SQL
	 * Common formats of limit are: limit n, limit m offset n, limit 1,3
	 * @param string $org_sql
	 * @param array $limit_info
	 * @return string
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	public static function patchLimitation($org_sql, $limit_info){
		$sql = trim(rtrim($org_sql, ';'));
		$pattern = '/\s?LIMIT\s(.*?)$/i';
		if(preg_match($pattern, $sql, $matches)){
			$last_limit_seg = $matches[count($matches) - 1];
			$last_limit_seg = preg_replace('/\s+OFFSET\s+/i', ',', $last_limit_seg, null, $offset_hit);
			$ls = explode(',', str_replace(' ', '', $last_limit_seg));
			if(count($ls) == 1){
				$org_limit_info = [0, (int)$ls[0]];
			}else if(count($ls) == 2){
				$org_limit_info = $offset_hit ? [(int)$ls[1], (int)$ls[2]] : [(int)$ls[0], (int)$ls[1]];
			}else{
				throw new Exception('Limitation resolve fail:'.$sql);
			}
			$limit_info = self::calcLimitInfo($org_limit_info, $limit_info);
			$sql = preg_replace($pattern, '', $sql);
		}
		self::getLogger()->info('patchLimitation Result:', $org_sql, $limit_info, "$sql LIMIT {$limit_info[0]}, {$limit_info[1]}");
		return "$sql LIMIT {$limit_info[0]}, {$limit_info[1]}";
	}

	/**
	 * @param array $org_limit_info [start_position, size]
	 * @param array|number $paginate_info [page_start, page_size] or [size] Pagination size. The pagination information is paginated based on the limit query condition.
	 * @return array
	 * @throws \LFPhp\PORM\Exception\Exception
	 */
	private static function calcLimitInfo($org_limit_info, $paginate_info){
		[$start, $size] = $org_limit_info;
		if(is_numeric($paginate_info)){
			return [$start, min($size, $paginate_info)];
		}
		[$page_start, $page_size] = $paginate_info;
		if($page_start > ($start + $size)){
			throw new Exception('paginate setting error,new:'.json_encode($paginate_info).',org:'.json_encode($org_limit_info));
		}
		if($page_start >= $size){
			$fetch_size = 0;
		}
		else {
			$fetch_size = min($size - $page_start, $page_size);
		}
		return [$page_start + $start, $fetch_size];
	}

	/**
	 * Determine whether the current operation statement is a write statement
	 * @param string|DBQuery $query
	 * @return bool
	 */
	public static function isWriteOperation($query = ''){
		return !preg_match('/^select\s/i', trim($query.''));
	}

	/**
	 * Add protection to field names (note that this protection only protects SQL keywords, not SQL injection protection)
	 * Automatically ignore spaces and other query statements
	 * @param $field
	 * @return string|array
	 */
	public static function escapeKey($field){
		if(is_array($field)){
			$ret = [];
			foreach($field as $val){
				$ret[] = (strpos($val, '`') === false && strpos($val, '.') === false && strpos($val, ' ') === false && $val != '*') ? "`$val`" : $val;
			}
			return $ret;
		}else{
			return (strpos($field, '`') === false && strpos($field, '.') === false && strpos($field, ' ') === false && $field != '*' ) ? "`$field`" : $field;
		}
	}

	/**
	 * Get the current query SQL
	 * @return string
	 * @throws \LFPhp\PORM\Exception\DBException
	 */
	public function toSQL(){
		if($this->sql){
			return $this->sql;
		}

		switch($this->operation){
			case self::SELECT:
				$sql = 'SELECT '.(implode(',', self::escapeKey($this->fields)) ?: '*').' FROM '.implode(',', $this->tables).$this->getJoinStr().' '.$this->getWhereStr().($this->group ? ' GROUP BY '.$this->group : '').($this->order ? ' ORDER BY '.$this->order : '');
				break;

			case self::DELETE:
				$sql = "DELETE FROM ".implode(',', $this->tables).$this->getWhereStr();
				break;

			case self::INSERT:
				if(!$this->data){
					throw new DBException("No data in database insert operation");
				}
				$data_list = count($this->data) == count($this->data, 1) ? array($this->data) : $this->data;
				$key_str = implode(",", self::escapeKey(array_keys($data_list[0])));
				$sql = "INSERT INTO ".implode(',', $this->tables)."($key_str) VALUES ";
				$comma = '';
				foreach($data_list as $row){
					$str = [];
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
					throw new DBException("No data in database update operation");
				}
				$data_list = count($this->data) == count($this->data, 1) ? array($this->data) : $this->data;
				$sets = [];
				foreach($data_list as $row){
					$sets = [];
					foreach($row as $field_name => $value){
						$field_name = self::escapeKey($field_name);
						if($value === null){
							$sets[] = "$field_name = NULL";
						}else{
							$sets[] = "$field_name = "."'".addslashes($value)."'";
						}
					}
				}
				$op_key = $this->operation == self::REPLACE ? 'REPLACE INTO' : 'UPDATE';
				$sql = "$op_key ".implode(',', $this->tables).' SET '.implode(',', $sets).$this->getWhereStr();
				break;
			default:
				throw new DBException("No database operation type set");
		}
		if($this->limit && stripos(' LIMIT ', $sql) === false){
			if(!$this->limit[0]){
				$sql .= " LIMIT ".$this->limit[1];
			}else{
				$sql .= " LIMIT ".$this->limit[0].','.$this->limit[1];
			}
		}
		return $sql;
	}

	/**
	 * Output SQL query statement
	 * @return string
	 * @throws \LFPhp\PORM\Exception\DBException
	 */
	public function __toString(){
		return $this->toSQL();
	}

	/**
	 * Output debugging information
	 * @return string[]
	 * @throws \LFPhp\PORM\Exception\DBException
	 */
	public function __debugInfo(){
		return ['SQL' => $this->toSQL()];
	}
}
