<?php
set_time_limit(60*60);
require_once 'lib/filedb.php';

$db=new filedb('mihajlo');



//$db->drop_database();

//$db->drop_table('user');

//$db->create_table('user');



//something like: SELECT * FROM korisnici WHERE _id='1' AND name LIKE '%Mihajlo%' AND surname LIKE '%oski_%'
/*
$results=$db->get('korisnici',array('name%'=>'Mihajlo','surname%'=>'oski_','_id'=>2));
print_r($results);
*/


/*
for($i=1;$i<=5;$i++){
    $db->insert('korisnici',array('name'=>'Mihajlo_'.$i,'surname'=>'Siljanoski_'.$i));
}
 */


