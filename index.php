<?php
set_time_limit(60*60);
require_once 'lib/filedb.php';

$db=new filedb('mihajlo');



//$db->drop_database();

//$db->drop_table('user');

//$db->create_table('user');


for($i=1;$i<=5;$i++){
    $db->insert('user'.$i,array('name'=>'Mihajlo_'.$i,'surname'=>'Siljanoski_'.$i));
}


