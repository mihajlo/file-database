<?php

//example of using as free storage

use FileDatabase\FileDB;

require_once 'lib/filedb.php';

$storage = new FileDB('exampledb');


$storage->save('users','mihajlo.siljanoski',[
    'gender'=>'male',
    'username'=>'mihajlo.siljanoski',
    'city'=>'Skopje',
    'country'=>'Macedonia'
]);

$storage->save('users','john.smith',[
    'gender'=>'male',
    'username'=>'john.smith',
    'city'=>'New York',
    'country'=>'USA'
]);

$storage->remove('users','mkdelta');

$users=$storage->read('users');

foreach($users as $user){
    
    $userData=$storage->read('users',$user);
    
    print_r($userData);
    
}
