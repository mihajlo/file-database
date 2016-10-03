<?php
/**
 * Description of filedb
 *
 * @author mihajlo
 */
class filedb {
    //put your code here
    public function __construct($database,$path='databases') {
        $this->db=$database;
        $this->path=$path;
        if(!file_exists($path)){
            mkdir($path);
            file_put_contents($this->path.'/.htaccess','Deny from all');
        }
        if(!file_exists($path.'/'.$database)){
            mkdir($path.'/'.$database);
        }
    }
    
    public function create_table($tablename=false){
        if(!$tablename){
            return false;
        }
        if(!file_exists($this->path.'/'.$this->db.'/'.$tablename)){
            mkdir($this->path.'/'.$this->db.'/'.$tablename);
            file_put_contents($this->path.'/'.$this->db.'/'.$tablename.'.scheme','1');
            return true;
        }
        return false;
    }
    
    public function insert($table=false,$data=array()){
        
        $this->create_table($table);
        
        if(!$table || !$data || count($data)<1){
            return false;
        }
        if(!file_exists($this->path.'/'.$this->db.'/'.$table)){
            return array();
        }
        
        $id=(int)file_get_contents($this->path.'/'.$this->db.'/'.$table.'.scheme');
        if(@file_put_contents($this->path.'/'.$this->db.'/'.$table.'/'.$id.'', json_encode(array_merge(array('_id'=>$id),$data)))){
            file_put_contents($this->path.'/'.$this->db.'/'.$table.'.scheme', $id+1);
            return array_merge(array('_id'=>$id),$data);
        }
        return false;
        
    }
    
    
    public function drop_table($table=false) {
        @$this->rrmdir($this->path.'/'.$this->db.'/'.$table);
        @unlink($this->path.'/'.$this->db.'/'.$table.'.scheme');
        return true;
    }
    
    public function drop_database() {
        @$this->rrmdir($this->path.'/'.$this->db);
        return true;
    }
    
    public function get($table=false,$where=false){
        if(!$table){
            return array();
        }
        
        $returnArr=array();
        $scanDir=  scandir($this->path.'/'.$this->db.'/'.$table);
        unset($scanDir[0]);
        unset($scanDir[1]);
        foreach(array_values($scanDir) as $key=>$record){
            if (is_array($where)) {
                $tmpData = json_decode(@file_get_contents($this->path . '/' . $this->db . '/' . $table . '/' . $record), true);
                $acceptRow = true;
                foreach ($where as $k => $v) {
                    if (substr($k, -1) == '%') {
                        if (strstr(@$tmpData[substr($k,0,strlen($k)-1)],$v)) {
                            $acceptRow = true;
                        } else {
                            $acceptRow = false;
                            break;
                        }
                    } 
                    else {
                        if (@$tmpData[$k] == $v) {
                            $acceptRow = true;
                        } else {
                            $acceptRow = false;
                            break;
                        }
                    }
                }
                if ($acceptRow) {
                    $returnArr[] = $tmpData;
                }
            } else{
                $returnArr[]=json_decode(@file_get_contents($this->path.'/'.$this->db.'/'.$table.'/'.$record),true);
                unset($scanDir[$key]);
            }
        }
        
        return $returnArr;
        
    }
    
    
    
    
    
    
    
    private function rrmdir($dir) {
        foreach (glob($dir . '/*') as $file) {
            if (is_dir($file))
                $this->rrmdir($file);
            else
                unlink($file);
        } rmdir($dir);
    }

}
