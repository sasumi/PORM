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
use LFPhp\PORM\DBModel;

class User extends DBModel {}

//查询对象 
$user = User::findOneByPK(1);

var_dump($user);

```

### 2.2 数据库操作

```php
<?php
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

