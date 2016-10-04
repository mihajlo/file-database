<?php

//Example of use

require_once 'lib/filedb.php';

$db=new filedb('testdatabase');


//if you like to drop database and all tables
//$db->drop_database();

//if you like to drop user table
$db->drop_table('user');


//if you like to create user table
$db->create_table('user');



//if you like to add record in user table if table user doesn't exist automaticaly will be created
$db->insert('user',['name'=>'Mihajlo','surname'=>'Siljanoski','web'=>'http://1mk.org/','username'=>'admin','password'=>md5('admin')]);



//to update record in user table
$db->update(
        'user',
        ['surname'=>'Siljanoski updated','web'=>false,'address'=>'Test address'],//address will be added and web will be deleted
        ['_id'=>1,'name%'=>'mihajlo'] // where ID =1 AND name LIKE '%mihajlo%'
);


//to delete record with _id=5
$db->delete(
        'user',
        ['_id'=>5]
);


//fetch records from database something like SELECT * FROM users WHERE name='Mihajlo' AND surname LIKE '%ski%'
$results=$db->get('user',
        [
            'name'=>'Mihajlo',
            'surname%'=>'ski',
        ]
    );
print_r($results);