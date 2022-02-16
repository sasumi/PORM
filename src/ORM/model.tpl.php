<?php
/** @var string $table */

use LFPhp\PORM\ORM\Attribute;
use LFPhp\PORM\ORM\DSLHelper;
use function LFPhp\Func\underscores_to_pascalcase;

/** @var string $table_description */
/** @var Attribute[] $attributes */
echo '<?php', PHP_EOL;
?>
use LFPhp\PORM\ORM\Attribute;
use LFPhp\PORM\ORM\Model;

/**
 * <?php echo $table_description, PHP_EOL;?>
<?php echo DSLHelper::convertAttrsToDoctrine($attributes);?>
 */
class <?php echo underscores_to_pascalcase($table, true);?> extends Model {
	/**
	 * 获取属性列表
	 * @return Attribute[]
	 */
	static public function getAttributes(){
		return [
<?php
foreach($attributes as $attr){
	echo "\t\t\t".DSLHelper::convertAttrToCode($attr, false).",".PHP_EOL;
}
?>
		];
	}

	static public function getTableName(){
		return '<?php echo $table;?>';
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