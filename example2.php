<?php
require_once 'lib/filedb.php';
$fdb=new filedb('anotherdatabase');




//example for adding in two tables (relation) at once posts and users

for($i=1;$i<=50;$i++){
    
    $fdb->insert('posts',[
        'title'=>'Example title '.$i,
        'description'=>'Example description will appear here '.$i,
        'author_id'=>$fdb->insert('users',['name'=>'Mihajlo_'.$i,'surname'=>'Siljanoski_'.$i],true)->_id
    ]);
    
}



//example of fetching results from 2 tables with relations at once (structured resultset)

$result=$fdb->get('posts',
        ['_id'=>15],
        ['author_id'=>['users','_id']]
        );

        print_r($result);





