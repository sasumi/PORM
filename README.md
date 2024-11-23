#PORM
> PORM is developed and tested based on PHP5.6 and above

PHP database ORM abstraction library, used for PHP programs to use databases independently, convenient for coding in ORM mode. PORM currently only supports MySQL, and is linked in MySQLi or PDO mode (PDO is recommended). Please ensure that the corresponding extension is correctly installed in PHP.

## 1. Quick Installation

```shell
composer require lfphp/porm
```

## 2. Usage

PORM supports calling by defining the ORM Model class, and also supports calling by directly linking to the database.

### 2.1 ORM approach

```php
<?php
use LFPhp\PORM\ORM\Model;

class User extends Model {

static public function getTableName(){
// TODO: Implement getTableName() method.
}

static public function getAttributes(){
// TODO: Implement getAttributes() method.
}

static protected function getDBConfig($operate_type = self::OP_READ){
// TODO: Implement getDBConfig() method.
}}

//Query object
$user = UserTable::findOneByPK(1);
var_dump($user);

//Change the object
$user->name = 'Jack';
$user->save();

//Add new object
$user = new UserTable();
$user->name = 'Michel';
$user->save();
echo $user->id;
```

### 2.2 Database Operations

```php
<?php

//Create database configuration
$cfg = new MySQL([
			'host'=>'localhost',
			'user'=>'root',
			'password'=>'123456',
			'database'=>'database'
		]);

//Create a database link instance
$ins = DBAbstract::instance($config);

//Create a query object (query statement)
$query = (new Query())->select()->field('id', 'title')->from('blog_article');

//Get the results
$ret = $ins->getPage($query);

//Get the count
$count = $ins->getCount($query);
```

## 3. Others
### 3.1 Annotation Rules
> By introducing the `AttributeAnnotation` class in the Model, the `DBAttribute` annotation can be automatically generated based on the current class annotation.

At this point, you need to ensure that the class annotation rules include the following type annotations:

1. @property string $name name (name note)
### 3.2 Database reconnection support
Add the `max_reconnect_count` item in the database configuration to set the database to automatically reconnect to the database when the connection is lost (not the first connection).
```php
<?php
//Create database configuration
$cfg = new MySQL([
			'host'=>'localhost',
			'user'=>'root',
			'password'=>'123456',
			'database'=>'database',
'max_reconnect_count' => 10, //Set the maximum number of reconnections
		]);
$ins = DBAbstract::instance($config);
//...
```
