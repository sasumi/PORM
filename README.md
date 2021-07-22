# PORM
> PORM基于PHP5.6以上环境开发测试

PHP 数据库ORM抽象库，用于PHP程式独立使用数据库时，方便以ORM方式进行编码。PORM当前仅支持MySQL，且以MySQLi或PDO方式进行链接（建议使用PDO），请用户确保PHP中正确安装相应扩展。

## 1. 快速安装

```shell
composer require lfphp/porm
```

## 2. 方法调用

PORM 支持通过定义ORM Model类方式进行调用，也支持直接链接数据库方式进行调用。

### 2.1 ORM方式

```php
<?php
use LFPhp\PORM\ORM\DBModel;

class User extends DBModel {

static public function getTableName(){
 // TODO: Implement getTableName() method.
}

static public function getAttributes(){
 // TODO: Implement getAttributes() method.
}

static protected function getDBConfig($operate_type = self::OP_READ){
 // TODO: Implement getDBConfig() method.
}}

//查询对象 
$user = UserTable::findOneByPK(1);
var_dump($user);

//更改对象
$user->name = 'Jack';
$user->save();

//新增对象
$user = new UserTable();
$user->name = 'Michel';
$user->save();
echo $user->id;
```

### 2.2 数据库操作

```php
<?php

use LFPhp\PORM\Driver\DBConfig;

//创建数据库配置
$cfg = DBConfig::createMySQLConfig('localhost', 'root', '123456', 'blog');

//创建数据库链接实例
$ins = DBAbstract::instance($config);

//创建查询对象(查询语句)
$query = (new Query())->select()->field('id', 'title')->from('blog_article');

//获取结果
$ret = $ins->getPage($query);

//获取计数
$count = $ins->getCount($query);
```

## 3. 其他
### 3.1 注解规则
> 通过在Model中引入使用`AttributeAnnotation`类，可自动根据当前类注释，生成`DBAttribute`注解。

此时，需保证类注释规则包含以下类型注解：

1. @property string $name 名称(名称备注)