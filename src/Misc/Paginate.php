<?php
namespace LFPhp\PORM\Misc;

use JsonSerializable;

/**
 * Paginate UI
 */
class Paginate implements PaginateInterface, JsonSerializable {
	private static $default_page_size = 10;

	public $item_total;
	public $current_page;
	public $page_size;
	public $page_size_key = 'page_size';
	public $page_key = 'page';

	private function __construct($config){
		$this->page_size = $config['page_size'] ?: self::$default_page_size;
		$this->page_key = $config['page_key'] ?: $this->page_key;
		$this->page_size_key = $config['page_size_key'] ?: $this->page_size_key;
		$this->current_page = max(isset($_REQUEST[$this->page_key]) ? intval($_REQUEST[$this->page_key]) : 1, 1);
		$this->page_size = $_REQUEST[$this->page_size_key] ? intval($_REQUEST[$this->page_size_key]) : $this->page_size;
	}

	public static function instance($config = []){
		static $instance_list = [];
		$k = serialize($config);
		if(!isset($instance_list[$k])){
			$instance_list[$k] = new self($config);
		}
		return $instance_list[$k];
	}

	/**
	 * fetch each page then call payload
	 * @param callable $fetcher $fetcher($paginate), return [$list, $total]
	 * @param callable $payload $payload($list, $paginate), return FALSE will breakdown
	 * @return bool
	 */
	public function fetchEachPage($fetcher, $payload){
		[$list, $total] = $fetcher($this);
		if($payload($list, $this) === false){
			return false;
		}
		$total_page = $this->getPageCount();
		for($p = 2; $p<=$total_page; $p++){
			$this->current_page = $p;
			[$list, $total] = $fetcher($this);
			if($payload($list, $this) === false){
				return false;
			}
		}
		return true;
	}

	/**
	 * fetch all data by payload
	 * @param callable $fetcher $fetcher($paginate), return [$list, $total]
	 * @return array all data list
	 */
	public function fetchAll($fetcher){
		$all = [];
		$this->fetchEachPage($fetcher, function($list) use (&$all){
			$all = array_merge($all, $list);
		});
		return $all;
	}

	public static function setDefaultPageSize($page_size){
		self::$default_page_size = $page_size;
	}

	public function __toString(){
		$html = '';
		$total_page_count = ceil($this->item_total/$this->page_size);
		$html .= '<span class="paginate-total-info">共'.$this->item_total.'条/'.$total_page_count.'页</span>';
		if($this->current_page > 1){
			$html .= '<a class="paginate-prev" title="上一页" href="'.$this->url($this->current_page - 1).'"></a>';
		}else{
			$html .= '<span class="paginate-prev"></span>';
		}
		$num_offset = 4;

		//前置部分
		if($this->current_page - $num_offset > 1){
			$html .= '<span class="paginate-dot"></span>';
		}
		for($i=min($num_offset, $this->current_page-1); $i>0; $i--){
			$p = $this->current_page-$i;
			$html .= '<a class="paginate-num" title="第'.$p.'页" href="'.$this->url($p).'">'.$p.'</a>';
		}
		$html .= '<span class="paginate-num paginate-current">'.$this->current_page.'</span>';

		//后置部分
		for($i = $this->current_page+1; $i<=min($total_page_count, $this->current_page+$num_offset); $i++){
			$html .= '<a class="paginate-num" title="第'.$i.'页" href="'.$this->url($i).'">'.$i.'</a>';
		}
		if($this->current_page + $num_offset <$total_page_count){
			$html .= '<span class="paginate-dot"></span>';
		}

		if($this->current_page < $total_page_count){
			$html .= '<a class="paginate-next" title="下一页" href="'.$this->url($this->current_page + 1).'"></a>';
		}else{
			$html .= '<span class="paginate-next"></span>';
		}
		$options = [10, 20, 50, 100, 200, 500, 1000, 2000];
		$html .= '<span class="paginate-size-changer">每页 <select onchange="location.href=this.options[this.selectedIndex].getAttribute(\'data-url\');">';
		foreach($options as $size){
			$html .= '<option data-url="'.$this->url(1, $size).'"'.($size == $this->page_size ? ' selected':'').'>'.$size.'</option>';
		}
		$html .= '</select> 条</span>';
		return "<div class=\"paginate paginate-total-$total_page_count\">$html</div>";
	}

	private function url($page, $page_size = null){
		$_GET[$this->page_key] = $page;
		$_GET[$this->page_size_key] = $page_size ?: $this->page_size;
		return '?'.http_build_query($_GET);
	}

	public function setItemTotal($total){
		$this->item_total = $total;
	}

	public function getPage(){
		return $this->current_page;
	}

	public function getPageCount(){
		return ceil($this->item_total/$this->page_size);
	}

	public function getLimit(){
		return [
			($this->current_page - 1)*$this->page_size,
			$this->page_size,
		];
	}

	public function jsonSerialize(){
		return [
			'item_total'   => $this->item_total,
			'page_total'   => $this->item_total ? ceil($this->item_total/$this->page_size) : 0,
			'current_page' => $this->current_page,
			'page_size'    => $this->page_size,
		];
	}
}
