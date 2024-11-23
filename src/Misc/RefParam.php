<?php
namespace LFPhp\PORM\Misc;
use ArrayAccess;
use Iterator;

/**
 * Reference parameter auxiliary class, used to support the problem that non-direct calls above 5.3 cannot reference parameters
 */
class RefParam implements Iterator, ArrayAccess{
	private $data;

	public function __construct(array $data=array()){
		$this->data = $data;
	}

	public function __set($key, $val){
		$this->data[$key] = $val;
	}

	public function __get($key){
		return $this->data[$key];
	}

	public function set($key, $val){
		$this->data[$key] = $val;
	}

	public function get($key){
		return $this->data[$key];
	}

	public function __unset($key){
		unset($this->data[$key]);
	}

	final public function rewind() {
		reset($this->data);
	}

	final public function current() {
		return current($this->data);
	}

	final public function key() {
		return key($this->data);
	}

	final public function next() {
		return next($this->data);
	}

	final public function valid() {
		return $this->current() !== false;
	}

	final public function offsetSet($offset, $value) {
		if (is_null($offset)) {
			$this->data[] = $value;
		} else {
			$this->data[$offset] = $value;
		}
	}

	final public function offsetExists($offset) {
		return isset($this->data[$offset]);
	}

	final public function offsetUnset($offset) {
		unset($this->data[$offset]);
	}

	final public function offsetGet($offset) {
		return isset($this->data[$offset]) ? $this->data[$offset] : null;
	}
}
