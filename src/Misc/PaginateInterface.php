<?php
namespace LFPhp\PORM\Misc;

/**
 * Paginate Interface
 */
interface PaginateInterface {
	/**
	 * set records total
	 * @param int $total
	 * @return bool
	 */
	public function setItemTotal($total);

	/**
	 * get limit infos [$start, $offset]
	 * @return mixed
	 */
	public function getLimit();
}
