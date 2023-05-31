<?php
use LFPhp\PORM\ORM\Attribute;
use LFPhp\PORM\ORM\Model;

/**
 * 表日志
 * @property-read int $id  
 * @property string $tbl 操作的主表名 
 * @property int $ref_id å¯¹åº”çš„è®°å½•ID,å¤šè¡Œè®°å½•æ—¶ä¸ºç©º 
 * @property int $uid 当时操作的用户ID,PHP脚本为1,Java脚本为2 
 * @property string $uname 用户名 
 * @property array $type 操作类型 新增,修改,删除,导出
 * @property string $title 描述 
 * @property string $old 原数据 
 * @property string $new 修改后数据 
 * @property string $create_time 创建时间 
 * @property string $ip IP 
 */
class TableLog extends Model {
	/**
	 * 获取属性列表
	 * @return Attribute[]
	 */
	static public function getAttributes(){
		return [
			new Attribute(['name'=>'id','type'=>Attribute::TYPE_INT,'length'=>5,'is_readonly'=>true,'is_primary_key'=>true]),
			new Attribute(['name'=>'tbl','type'=>Attribute::TYPE_STRING,'alias'=>'操作的主表名','length'=>50]),
			new Attribute(['name'=>'ref_id','type'=>Attribute::TYPE_INT,'alias'=>'å¯¹åº”çš„è®°å½•ID,å¤šè¡Œè®°å½•æ—¶ä¸ºç©º','length'=>30]),
			new Attribute(['name'=>'uid','type'=>Attribute::TYPE_INT,'alias'=>'当时操作的用户ID,PHP脚本为1,Java脚本为2','default'=>0,'length'=>30]),
			new Attribute(['name'=>'uname','type'=>Attribute::TYPE_STRING,'alias'=>'用户名','length'=>30]),
			new Attribute(['name'=>'type','type'=>Attribute::TYPE_ENUM,'alias'=>'操作类型','description'=>'新增,修改,删除,导出','options'=>array('Add'=>'新增','Update'=>'修改','Delete'=>'删除','Export'=>'导出')]),
			new Attribute(['name'=>'title','type'=>Attribute::TYPE_STRING,'alias'=>'描述','length'=>100]),
			new Attribute(['name'=>'old','type'=>Attribute::TYPE_JSON,'alias'=>'原数据','default'=>Attribute::DEFAULT_NULL]),
			new Attribute(['name'=>'new','type'=>Attribute::TYPE_JSON,'alias'=>'修改后数据','default'=>Attribute::DEFAULT_NULL]),
			new Attribute(['name'=>'create_time','type'=>Attribute::TYPE_DATETIME,'alias'=>'创建时间','default'=>Attribute::DEFAULT_CURRENT_TIMESTAMP]),
			new Attribute(['name'=>'ip','type'=>Attribute::TYPE_STRING,'alias'=>'IP','default'=>'','length'=>15]),
		];
	}

	static public function getTableName(){
		return 'table_log';
	}

	/**
	 * get DSN
	 * @param int $operate_type
	 * @return \LFPhp\PDODSN\DSN
	 * @throws \Exception
	*/
	static public function getDbDsn($operate_type = self::OP_READ){
		//@todo
	}
}