<?php
namespace LFPhp\PORM\Misc;

use InvalidArgumentException;
use JsonSerializable;
use function LFPhp\Func\assert_via_exception;

/**
 * Paginate UI
 */
class Paginate implements PaginateInterface, JsonSerializable {
	public static $TEXT_TRANSLATION = [
		'total-info'    => '共 {item_total} 条/{page_total}页',
		'page-previous' => '上一页',
		'page-next'     => '下一页',
		'page-index'    => '第{page}页',
		'page-sizer'    => '每页 {changer} 条',
	];

	private static $default_page_size = 10;
	private static $default_page_size_option = [];

	/** @var callable url handler, for some framework has self-build router function */
	private static $url_handler;

	private $item_total;
	private $page;
	private $page_total;

	private $number_offset = 4;
	private $page_size = null;
	private $item_limit = 0;

	//page size option, if empty no allowed changing page size
	public $page_size_option = [/** 10, 20, 50, 100, 200, 500 */];
	public $page_size_key = 'page_size';

	//page set from $_REQUEST
	public $page_key = 'page';

	private function __construct($config){
		$this->page_size = $config['page_size'] ?: self::$default_page_size;
		$this->page_key = $config['page_key'] ?: $this->page_key;
		$this->page_size_key = $config['page_size_key'] ?: $this->page_size_key;
		$this->page_size_option = $config['page_size_option'] ?? self::$default_page_size_option;
		$this->number_offset = $config['number_offset'] ?: $this->number_offset;
		$this->item_limit = $config['item_limit'] ?: $this->item_limit;

		$this->page = isset($_REQUEST[$this->page_key]) ? intval($_REQUEST[$this->page_key]) : 1;

		//reset page size from $_REQUEST while page size option supplied
		if($this->page_size_option && $_REQUEST[$this->page_size_key] && in_array($_REQUEST[$this->page_size_key], $this->page_size_option)){
			$this->page_size = intval($_REQUEST[$this->page_size_key]);
		}

		assert_via_exception($this->page_size > 1, 'page size value error('.$this->page_size.')', InvalidArgumentException::class);
		assert_via_exception($this->page > 0, 'page number error('.$this->page.')', InvalidArgumentException::class);
	}

	/**
	 * set global default page size
	 * @param int $page_size
	 */
	public static function setDefaultPageSize($page_size){
		assert_via_exception($page_size > 0, 'page size value error('.$page_size.')', InvalidArgumentException::class);
		self::$default_page_size = $page_size;
	}

	/**
	 * set global page size option
	 * @param int[] $page_size_option
	 * @return void
	 */
	public static function setDefaultPageSizeOption(array $page_size_option){
		self::$default_page_size_option = $page_size_option;
	}

	/**
	 * set global url router
	 * @param callable $url_handler arguments(array paginate_param[key=>val])
	 * @return void
	 */
	public static function setUrlHandler($url_handler){
		self::$url_handler = $url_handler;
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
	 * internationalized text translate
	 * @param string $key
	 * @param string[] $param
	 * @return string
	 */
	private static function trans($key, $param = []){
		$tmp = self::$TEXT_TRANSLATION[$key];
		if(!$param){
			return $tmp;
		}
		$searches = [];
		$replaces = [];
		foreach($param as $k => $val){
			$searches[] = '{'.$k.'}';
			$replaces[] = $val;
		}
		return str_replace($searches, $replaces, $tmp);
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
		for($p = 2; $p <= $total_page; $p++){
			$this->page = $p;
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

	public function toHtml(){
		return $this->__toString();
	}

	public function __toString(){
		$html = '<span class="paginate-total-info">'.self::trans('total-info', [
				'item_total' => $this->item_total,
				'page_total' => $this->page_total,
			]).'</span>';
		if($this->page > 1){
			$html .= '<a class="paginate-prev" title="'.self::trans('page-previous').'" href="'.$this->url($this->page - 1).'"></a>';
		}else{
			$html .= '<span class="paginate-prev"></span>';
		}

		//前置部分
		if($this->page - $this->number_offset > 1){
			$html .= '<span class="paginate-dot"></span>';
		}
		for($i = min($this->number_offset, $this->page - 1); $i > 0; $i--){
			$p = $this->page - $i;
			$html .= '<a class="paginate-num" title="'.self::trans('page-index', ['page' => $p]).'" href="'.$this->url($p).'">'.$p.'</a>';
		}
		$html .= '<span class="paginate-num paginate-current">'.$this->page.'</span>';

		//后置部分
		for($i = $this->page + 1; $i <= min($this->page_total, $this->page + $this->number_offset); $i++){
			$html .= '<a class="paginate-num" title="'.self::trans('page-index', ['page' => $i]).'" href="'.$this->url($i).'">'.$i.'</a>';
		}
		if($this->page + $this->number_offset < $this->page_total){
			$html .= '<span class="paginate-dot"></span>';
		}

		if($this->page < $this->page_total){
			$html .= '<a class="paginate-next" title="'.self::trans('page-next').'" href="'.$this->url($this->page + 1).'"></a>';
		}else{
			$html .= '<span class="paginate-next"></span>';
		}

		//page option selector
		if($this->page_size_option){
			$html .= '<span class="paginate-size-changer">';
			$changer = '<select onchange="location.href=this.options[this.selectedIndex].getAttribute(\'data-url\');">';
			foreach($this->page_size_option as $size){
				$changer .= '<option data-url="'.$this->url(1, $size).'"'.($size == $this->page_size ? ' selected' : '').'>'.$size.'</option>';
			}
			$changer .= '</select>';
			$html .= self::trans('page-sizer', ['changer' => $changer]).'</span>';
		}

		return "<div class=\"paginate paginate-total-{$this->page_total}\">$html</div>";
	}

	/**
	 * build paginate url string
	 * @param int $page
	 * @param int $page_size
	 * @return string
	 */
	private function url($page, $page_size = null){
		$param = [];
		$param[$this->page_key] = $page;
		if($page_size){
			$param[$this->page_size_key] = $page_size;
		}
		if(self::$url_handler){
			return call_user_func(self::$url_handler, $param);
		}
		return '?'.http_build_query(array_merge($_GET, $param));
	}

	public function getItemTotal(){
		return $this->item_total;
	}

	public function getItemLimit(){
		return $this->item_limit;
	}

	public function getPageSize(){
		return $this->page_size;
	}

	/**
	 * get current page number
	 * @return int
	 */
	public function getPage(){
		return $this->page;
	}

	/**
	 * get total page count
	 * @return int
	 */
	public function getPageCount(){
		return $this->page_total;
	}

	/**
	 * set item total
	 * @param int $total
	 */
	public function setItemTotal($total){
		$total = ($this->item_limit && $total > $this->item_limit) ? $this->item_limit : $total;
		$this->item_total = $total;
		$this->page_total = $total ? ceil($total/$this->page_size) : 0;
	}

	/**
	 * get limit info
	 * @return array [start_offset, size]
	 */
	public function getLimit(){
		//limitation for item_limit
		if($this->item_limit && ($this->page - 1)*$this->page_size >= $this->item_limit){
			throw new InvalidArgumentException('page value error:'.$this->page);
		}
		return [
			($this->page - 1)*$this->page_size,
			$this->page_size,
		];
	}

	public function jsonSerialize(){
		return [
			'item_total' => $this->item_total,
			'page_total' => $this->page_total,
			'page'       => $this->page,
			'page_size'  => $this->page_size,
		];
	}
}
