<?php

/*
************************************************************************************************
*   # this tool exports and generates the full db in xml format and migration code (safeUp/safeDown) for: 
*       - initial full db; 
*       - added/dropped tables, columns, and foreign keys (and fks related indexes);
*       - updated column attributes
*
*    # the following modified column attributes are detected and exported:
*       - type, length, zerofill, allow null, default value
*
*   # please note:
*    - indexes are not automatically exported;
*    - new/dropped foreign keys generate/remove linked indexes automatically in the migration file
*    - columns renamed are considered drop columns and add addd new columns;
*    - column unsigned and comments are not automatically exported;
*    - the foreign key name exported is not a match with the one from the db, but based on the namming convention
*
*   # run instructions
*   - in terminal, in your yii protected folder path, run the command:$ ./yiic updatedb
*   - an initial dump of the bd will be generated to an xml file - $bdFile; 
*   - an initial migration file will be generated with the full bd with suffix: $initialMigrationFileSuffix
*   - the bd config file file will continuously be updated with every subsequent run of this tool
*   - following updates to the bd will generate files in the format mYearMonthDay_Timestamp_$GENERATED_FILE_PREFIX
*   - for each bd update, a migration file will be added to the $migrationsDir
*
*   # final notes
*   - please feel free to send me improvements or comments to this code
************************************************************************************************
*/

class updateDbCommand extends CConsoleCommand
{    
    /*customize your file names and paths here*/    
    public $migrationsDir = "/migrations/"; //your migrations folder
    public $bdFile = '/migrations/bdDefinition.xml'; //bd lattest definition in xml format
    public $initialMigrationFileSuffix = 'initial'; //initial migration with full db
    public $tab = '   '; //used for generated migration file code identation
    public $GENERATED_FILE_PREFIX = "auto_generated"; //prefix for auto-generated migration files
    
    /*
    # don't change below this line!
    */
    public $changelog = "";
    public $database = "";
    public $currentBd = null;
    public $migrationFile = "";
    public $upRows = "";
    public $downRows = "";    
    public $tableStack = array();
    public $colStack = array();
    public $fkStack = array();
    public $indexStack = array();
    public $NEW = "new";
    public $DROPPED = "dropped";    
    public $EXISTING = "existing";
    public $UPDATED = "updated";
    
    public function run($args) {        
        //$schema = $args[0];
        //$tables = Yii::app()->db->schema->getTables($schema);
        preg_match('/dbname=([0-9,a-z,A-Z$_]+);?/',Yii::app()->db->connectionString,$matches);        
        $this->database = $matches[1];
        $tables = Yii::app()->db->schema->getTables($this->database);                        
        
        $databaseArr = array();
        $tablesArr = array();
        $indexesArr = array();
        $this->log("### Timestamp ".date("d/m/y H:i:s")." ###\n\n");
        
        $this->loadCurrentDbFile();
        
        /*--save current bd structure to object $this->currentBd*/
        
        foreach ($tables as $table) {            
            $tableArr = array("name"=>$table->name,"status"=>"");            
            $storedTable = null;
            
            /*++ check if it is a new table and generate script if so*/
            $tableArr = $this->processTable($tableArr,$storedTable);
            /*-- check if it is a new table and generate script if so*/
            
            /*++get cols & keys*/
            $tableArr["cols"] = array();
            foreach ($table->columns as $col) {                
                $colArr = $this->saveColumn($col,$tableArr,$tableArr);   
                /*++ check if it is a new table and generate script if so*/
                $colArr = $this->processColumn($colArr,$storedTable,$tableArr);
                /*++ check if it is a new column and generate script if so*/
                
            }/*--get cols & keys*/
            
            /*++get foreign keys*/
            $tableArr["foreignKeys"] = array();
            $tableArr["indexes"] = array();
            //print_r($table,false);
            foreach ($table->foreignKeys as $col => $fk) {                
                $fkArr = $this->saveFk($tableArr,$colArr,$fk,$tableArr);
                //this only generates the fk indexes as id doesn't read from the table
                $idxArr = $this->saveIndex($tableArr,$colArr,$tableArr);
                
                /*++ check if it is a new foreign key and generate script if so*/
                $this->processFk($fkArr,$storedTable,$tableArr);
                /*-- check if it is a new foreign key and generate script if so*/
                
                /*++ check if it is a new index and generate script if so*/
                //this only generates the fk indexes as id doesn't read from the table
                $this->processIndex($idxArr,$storedTable,$tableArr);
                /*-- check if it is a new index and generate script if so*/
            }            
            array_push($tablesArr,$tableArr);
        }
                
        $databaseArr["tables"]=$tablesArr;        
        $this->saveToXMLFile($databaseArr); //save updated db in xml file                      
        
        //check for deleted items from db to generate migration for dropped items
        $this->processDeletedBdItems($databaseArr);
        
        //if there are changed items in the db, generate migration file and add drop commands
        if(count($this->tableStack) || count($this->colStack) 
           || count($this->fkStack)|| count($this->indexStack)){                        
            /*save to migration file*/
            $this->generateMigrationFile();
        }                
    }
    
    /*compare saved bd config file against newly read db to look for deleted items*/
    public function processDeletedBdItems($databaseArr){        
        if(isset($this->currentBd)){
            //print_r($this->currentBd->tables->children());            
            foreach($this->currentBd->tables->children() as $storedTable){
                $deletedTable = true;
                
                foreach($databaseArr["tables"] as $dbTable){                    
                    if($storedTable->name==$dbTable["name"]) {
                        $deletedTable = false;                        
                        break;
                    }                    
                }
                if($deletedTable) { //drop all items from table
                    $table = array("name"=>$storedTable->name,"status"=>$this->DROPPED);              
                    $this->addTable($table);                    
                    foreach($storedTable->cols->children() as $col){                        
                        $colArr = $this->xmlObj2array($col);
                        $colArr["status"] = $this->DROPPED;
                        $this->addColumn($colArr);                        
                    }
                    foreach($storedTable->foreignKeys->children() as $fk){
                        $fkArr = $this->xmlObj2array($fk);
                        $fkArr["status"] = $this->DROPPED;
                        $this->addFk($fkArr); 
                    }
                    foreach($storedTable->indexes->children() as $idx){
                        //$idxArr = $this->xmlObj2array($idx);
                        //$idxArr["status"] = $this->DROPPED;
                        //$this->addIndex($idxArr);
                    }
                }
                /*if table hasn't been deleted, look for dropped table elements: indexes, fks, cols*/
                $this->processDeletedCols($storedTable,$dbTable);
                $this->processDeletedFks($storedTable,$dbTable);
                //$this->processDeletedIndexes($storedTable,$dbTable);
                
            }   
        }
    }
    
    /*add dropped fk to stack for post processing*/
    public function processDeletedFks($storedTable,$dbTable){
        //if cols dropped
        $deletedFk = true;
        $storedFks = 0;
        $dbFks = 0;
        foreach($storedTable->foreignKeys->children() as $fk){
            $storedFks++;
            foreach($dbTable["foreignKeys"] as $dbFk){
                if($fk->name==$dbFk["name"]) {                            
                    $dbFks++;
                    break;
                }
            }
            if($storedFks!=$dbFks){ 
                $fkArr = $this->xmlObj2array($fk);
                $fkArr["status"] = $this->DROPPED;                        
                $this->addFk($fkArr);                        
            }
        }   
    }
    
    /*add dropped cols to stack for post processing*/
    public function processDeletedCols($storedTable,$dbTable){
        //if cols dropped
        $deletedCol = true;
        $storedCols = 0;
        $dbCols = 0;
        foreach($storedTable->cols->children() as $col){
            $storedCols++;
            foreach($dbTable["cols"] as $dbCol){
                //echo "table: " .$storedTable->name ." " .$col->name . " ". $dbCol["name"]."\n";
                if($col->name==$dbCol["name"]) {                            
                    $dbCols++;
                    break;
                }
            }
            if($storedCols!=$dbCols){ 
                echo "delete col".$col->name;
                $colArr = $this->xmlObj2array($col);
                $colArr["status"] = $this->DROPPED;                        
                $this->addColumn($colArr);                        
            }
        }   
    }
    
    /*generate code for safeUp method for Indexes*/
    public function generateUpIndexes($status){
        foreach($this->indexStack as $idx){
            if($status==$idx["status"]) $this->upIndex($idx);
        }
    }

    /*generate code for safeUp method for fks*/
    public function generateUpFks($status){
        foreach($this->fkStack as $fk){
            if($status==$fk["status"]) $this->upFk($fk);
        }
    }

    /*generate code for safeUp method for cols*/
    public function generateUpCols($status){
        foreach($this->colStack as $col){
            if($status==$col["status"]) $this->upColumn($col);
        }
    }

    /*generate code for safeUp method for tables*/
    public function generateUpTables($status){
        foreach($this->tableStack as $table){
            if($status==$table["status"]) $this->upTable($table);
        }
    }
    
    /*generate code for safeDown method for Indexes*/
    public function generateDownIndexes($status){
        foreach($this->indexStack as $idx){
            if($status==$idx["status"]) $this->downIndex($idx);
        }
    }
    
    /*generate code for safeDown method for fks*/
    public function generateDownFks($status){
        foreach($this->fkStack as $fk){
            if($status==$fk["status"]) $this->downFk($fk);
        }
    }
    
    /*generate code for safeDown method for cols*/
    public function generateDownCols($status){
        foreach($this->colStack as $col){
            if($status==$col["status"]) $this->downColumn($col);
        }
    }
    
    /*generate code for safeDown method for tables*/
    public function generateDownTables($status){
        foreach($this->tableStack as $table){
            if($status==$table["status"]) $this->downTable($table);
        }
    }
    
    /*generate migration code & file*/
    public function generateMigrationFile(){
        /*++don't change this order so that the db changes are applied without errors*/
        //generate safeUp/Down code for changed columns
        $this->generateUpCols($this->UPDATED); //alter cols
        $this->generateDownCols($this->UPDATED); //restore cols
        
        //++generate safeUp code for new items
        $this->generateUpTables($this->NEW); //create table
        $this->generateUpCols($this->NEW); //create cols
        $this->generateUpFks($this->NEW); //create fks
        $this->generateUpIndexes($this->NEW); //create idxs
        //--generate safeUp code for new items
        
        //++generate safeDown code for dropped items
        $this->generateDownTables($this->DROPPED); //create table
        $this->generateDownCols($this->DROPPED); //create cols
        $this->generateDownFks($this->DROPPED); //create fk
        $this->generateDownIndexes($this->DROPPED); //createIdxs 
        //--generate safeDown code for dropped items
        
        //++generate safeUp code for dropped items
        $this->generateUpIndexes($this->DROPPED); //drop Idxs
        $this->generateUpFks($this->DROPPED); //drop Fks
        $this->generateUpCols($this->DROPPED); //drop cols
        $this->generateUpTables($this->DROPPED); //drop tables        
        //--generate safeUp for dropped items
        
        //++generate safeDown code for new items
        $this->generateDownIndexes($this->NEW); //drop idxs
        $this->generateDownFks($this->NEW); //drop fks
        $this->generateDownCols($this->NEW); //drop cols
        $this->generateDownTables($this->NEW); //drop tables
        //--generate code for new items
        /*--don't change this order so that the db changes are applied without errors*/
        
        $this->createMigrationFile();
        $space = $this->tab;
        $migrationFile = str_replace(".php","",$this->migrationFile);
        $string = "<?php\n\n/**".$this->changelog."\n**/";
        $string .= "\n\n"."class ".$migrationFile." extends CDbMigration {\n\n";
        $string .= $space."public function safeUp(){\n";
        $string .= $this->upRows;
        $string .= "\n".$space."}\n\n\n";
        $string .= $space."public function safeDown(){\n";
        $string .= $this->downRows;
        $string .= "\n".$space."}\n";
        $string .= "}\n?>";	
        //print_r($string);
        file_put_contents(Yii::app()->basePath.$this->migrationsDir.$this->migrationFile,$string);
        
        //mark this migration as performed in local db       
        $this->markMigration($migrationFile);
    }
    
    /*save fk to read db array*/
    public function saveFk($table,$col,$fkArr,&$tableArr){
        $fk = array();
        // Foreign key naming convention: fk_table_foreignTable_col (max 64 characters)
        $fk["name"] = substr('fk_' . $table["name"] . '_' . $fkArr[0] . '_' . $col["name"], 0 , 64);
        $fk["table"] = $table["name"];
        $fk["col"] = $col["name"];
        $fk["fkTable"] = $fkArr[0];
        $fk["fkcol"] = $fkArr[1];
        $fk["onUpdate"] = "NO ACTION";
        $fk["onDelete"] = "NO ACTION";         
        array_push($tableArr["foreignKeys"],$fk); 
        //$fk["status"] = $this->NEW; //not saved to db structure but used for internal control
        return $fk;
    }
    /*-- save to dbDefinition file*/
    
    /*save column to read db array*/
    public function saveColumn($col,&$tableArr,$table){
        $tableCol = array();
        $tableCol["table"] = $table["name"];
        $tableCol["name"] = $col->name;
        $tableCol["type"] = $this->getColType($col);
        $tableCol["defaultValue"] = isset($col->defaultValue)?$col->defaultValue:"NULL";
        $tableCol["size"] = $col->size;
        //$tableCol["precision"] = $col->precision;
        $tableCol["scale"] = $col->scale;
        $tableCol["isPrimaryKey"] = true==$col->isPrimaryKey?"true":"false";
        $tableCol["isForeignKey"] = (true==$col->isForeignKey)?"true":"false";
        $tableCol["autoIncrement"] = (true==$col->autoIncrement)?"true":"false";
        $tableCol["allowNull"] = (true==$col->allowNull)?"true":"false";
        $tableCol["comment"] = $col->comment;                
        $tableCol["dbType"] = $col->dbType;         
        array_push($tableArr["cols"],$tableCol);
        //$tableCol["status"] = $this->NEW; //not saved to db structure but used for internal control
        return $tableCol;
    }
    
    /*save index to read db array*/
    public function saveIndex($table,$col,&$tableArr){                 
        $idx = $this->getIndex($table,$col);
        array_push($tableArr["indexes"],$idx); 
        //$idx["status"] = $this->NEW; //not saved to db structure but used for internal control
        return $idx;
    }
    
    /*generate an index baxed in the table and column names, linked with the fk*/
    public function getIndex($table,$col){
        $idx = array();
        // index key naming convention: idx_col
        $idx["table"] = $table["name"];
        $idx["name"] = 'idx_' . $col["name"];
        $idx["col"] = $col["name"];  
        return $idx;
    }
   
    /*load existing bd definition from xml file if exists for comparison against the local db*/
    public function loadCurrentDbFile(){
        /*++save current bd structure to object $this->currentBd*/
        $migrationFileName = $this->GENERATED_FILE_PREFIX;
        if(is_file(Yii::app()->basePath.$this->bdFile)) {
            $get = file_get_contents(Yii::app()->basePath.$this->bdFile);
            $this->currentBd = simplexml_load_string($get);                   
        } else {
            $this->log("# Existing db config not found, assuming initial generation.\n");   
            $migrationFileName = $this->initialMigrationFileSuffix;
        }
        $this->migrationFile = $migrationFileName;
    }
    
    /*create file via yii migration. It's contents will be overriden by the generateMigrationFile() function*/
    public function createMigrationFile(){
        $existingFiles = scandir(Yii::app()->basePath.$this->migrationsDir);
        $this->createMigration($this->migrationFile);
        $newFiles = scandir(Yii::app()->basePath.$this->migrationsDir);
        $migrationFile = array_diff($newFiles,$existingFiles);        
        $this->migrationFile = array_pop($migrationFile);
    }
    
    /*convert read db array to an xml string*/
    public function saveToXMLFile($databaseArr){        
        $xml_bd = new SimpleXMLElement("<?xml version=\"1.0\"?><$this->database></$this->database>");                
        /*convert array to xml file*/
        $this->array_to_xml($databaseArr,$xml_bd);                
        $dom = new DOMDocument("1.0");
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml_bd->asXML());
        $dom->save(Yii::app()->basePath.$this->bdFile);
    }
    
    /*++add items to stack for post processing and generation of migration file*/        
    public function addTable($table){
        array_push($this->tableStack,$table); //for adding latter dropping tables
    }
    
    public function addColumn($col){  
        array_push($this->colStack,$col);        
    }
    
    public function addFk($fk){
        array_push($this->fkStack,$fk);
    }    
    /*--add items to stack for post processing and generation of migration file*/    
    
    /*++generation of up and down methods for migration file*/
    public function upTable($table){        
        $space  = $this->tab.$this->tab.$this->tab;
        if($table["status"]==$this->NEW){
            $this->log("* Added new table: '".$table["name"]."'");
            $this->addDbTable($table,$this->upRows);
        }else if ($table["status"]==$this->DROPPED){
            $this->log("* Dropped table: '".$table["name"]."'");
            $this->dropDbTable($table,$this->upRows);
        }        
    }
    
    public function downTable($table){
        if($table["status"]==$this->NEW){
            $this->dropDbTable($table,$this->downRows);            
        }else if ($table["status"]==$this->DROPPED){
            $this->addDbTable($table,$this->downRows);            
        }
    }
    
    public function upColumn($col){    
        
        if("true"==$col["isPrimaryKey"]) 
            $type = $col["dbType"]. " primary KEY AUTO_INCREMENT";
        else $type = $col["type"];
        
        if($col["status"]==$this->NEW){            
            $this->log("* Table '".$col["table"]."' -> New column: '"
                       .$col["name"]."' | type: '".$type."'");
            //don't duplicate if added with table
            if(isset($col["processed"]) && true==$col["processed"]);
            else $this->addDbCol($col,$this->upRows);
        }
        else if($col["status"]==$this->DROPPED) {
            $this->log("* Table '".$col["table"]."' -> Dropped column: '"
                       .$col["name"]."' | type: '".$col["type"]."'");
            $this->dropDbCol($col,$this->upRows);
        }
        else if($col["status"]==$this->UPDATED){
            $this->log("* Table '".$col["table"]."' -> Updated column: '"
                       .$col["name"]."' | type: '".$col["type"]."'");
            $this->updateDbCol($col,$this->upRows);
        }            
    }
    
    public function downColumn($col){        
        if($col["status"]==$this->NEW){            
            $this->dropDbCol($col,$this->downRows);
        }
        else if($col["status"]==$this->DROPPED) {        
            //don't duplicate if added with table
            if(isset($col["processed"]) &&  true==$col["processed"]);
            else $this->addDbCol($col,$this->downRows);
        }
        else if($col["status"]==$this->UPDATED){            
            $this->updateDbCol($col["previousState"],$this->downRows);
        }            
    }
    
    public function upFk($fk){
        if($fk["status"]==$this->NEW){  
            $this->log("* Table '".$fk["table"]."' -> New Fk: '".$fk["name"]."'");
            $this->addDbFk($fk,$this->upRows);
        }
        else if($fk["status"]==$this->DROPPED) { 
            $this->log("* Table '".$fk["table"]."' -> Dropped Fk: '".$fk["name"]."'");
            $this->dropDbFk($fk,$this->upRows);
        }
    }
    
    public function downfk($fk){
        if($fk["status"]==$this->NEW){            
            $this->dropDbFk($fk,$this->downRows);
        }
        else if($fk["status"]==$this->DROPPED) {        
            $this->addDbFk($fk,$this->downRows);
        }         
    }    

    public function upIndex($idx){
        if($idx["status"]==$this->NEW){  
            $this->log("* Table '".$idx["table"]."' -> New Index: '".$idx["name"]."'");
            $this->addDbIndex($idx,$this->upRows);
        }
        else if($idx["status"]==$this->DROPPED) { 
            $this->log("* Table '".$fk["table"]."' -> Dropped Fk: '".$fk["name"]."'");
            $this->dropDbIndex($idx,$this->upRows);
        }

    }
    
    public function downIndex($idx){
        if($idx["status"]==$this->NEW){            
            $this->dropDbIndex($idx,$this->downRows);
        }
        else if($fk["status"]==$this->DROPPED) {        
            $this->addDbIndex($idx,$this->downRows);
        }
    }       
    
    /*--generation of up and down methods for migration file*/
    
    /*++ migration snippets for db updates*/
    public function updateDbCol($col,&$migrationCode){               
        $space  = $this->tab.$this->tab.$this->tab;
        //generate alter column
        $type = $col["type"];
        //$type = isset($col["defaultValue"])? ($col["dbType"]." DEFAULT ".$col["defaultValue"]) : $col["type"];
        $migrationCode .= "\n".$space.'$this->alterColumn(\'' . $col["table"] . '\',\''
            .$col["name"].'\',\''.$type.'\');';
    }
    
    public function addDbCol($col,&$migrationCode){       
        $space  = $this->tab.$this->tab.$this->tab;
        $type = "";
        
        if("true"==$col["isPrimaryKey"]) 
            $type = $col["dbType"]. " primary KEY AUTO_INCREMENT";
        else $type = isset($col["defaultValue"])? ($col["dbType"]." DEFAULT ".$col["defaultValue"]) : $col["type"];
        
        //generate add column
        $migrationCode .= "\n".$space.'$this->addColumn(\'' . $col["table"] . '\',\''
            .$col["name"].'\',\''.$type.'\');';
    }
    
    public function dropDbCol($col,&$migrationCode){
        foreach($this->tableStack as $table){
            //don't drop columns if table will also be dropped, to avoid db errors
            if($table["name"]==$col["table"] 
               && ($table["status"]==$this->NEW
                   || $table["status"]==$this->DROPPED)
              ) return;   
        }
        $space  = $this->tab.$this->tab.$this->tab;                  
        //generate drop column
        $migrationCode .= "\n".$space.'$this->dropColumn(\'' . $col["table"] . '\',\''
            .$col["name"].'\');';
    }
    
    public function addDbTable($table,&$migrationCode){
        $space  = $this->tab.$this->tab.$this->tab;
        $pk = "";        
        foreach($this->colStack as $key => $col) {
            if($col["table"]==$table["name"] && "true"==$col["isPrimaryKey"]) {
                $type = $col["dbType"]. " primary KEY AUTO_INCREMENT";
                $pk = "'".$col["name"]."'=>'".$type."'";
                $this->colStack[$key]["processed"]=true; //remove pk from stack
                break;
            }
        }
        $migrationCode .= "\n".$space.'$this->createTable(\'' . $table["name"] . '\',array('.$pk.'));'; 
    }

    public function dropDbTable($table,&$migrationCode){
        $space  = $this->tab.$this->tab.$this->tab;
        $migrationCode .= "\n".$space.'$this->dropTable(\'' . $table["name"] . '\');'; 
    } 
    
    public function addDbFk($fk,&$migrationCode){
        $space  = $this->tab.$this->tab.$this->tab;        
        //generate code for add foreign key
        $migrationCode .= "\n".$space.'$this->addForeignKey(\'' . $fk["name"] . '\',\''
            .$fk["table"].'\','."\n".$space.$space.'\''.$fk["col"].'\',\''.$fk["fkTable"].'\',\''
            .$fk["fkcol"].'\',\''.$fk["onDelete"].'\',\''.$fk["onUpdate"].'\');';
    }
    
    public function dropDbFk($fk,&$migrationCode){
        $space  = $this->tab.$this->tab.$this->tab;
        $migrationCode .= "\n".$space.'$this->dropForeignKey(\'' . $fk["name"] . '\',\''
            .$fk["table"].'\');'; 
    }
    
    public function addDbIndex($idx,&$migrationCode){
        $space  = $this->tab.$this->tab.$this->tab;        
        //generate code for add index
        $migrationCode .= "\n".$space.'$this->createIndex(\'' . $idx["name"] . '\',\''
            .$idx["table"].'\',\''.$idx["col"].'\');'; 
    }

    public function dropDbIndex($idx,&$migrationCode){
        $space  = $this->tab.$this->tab.$this->tab;
        //generate drop index
        $migrationCode .= "\n".$space.'$this->dropIndex (\'' . $idx["name"] . '\',\''
            .$idx["table"].'\');';   
    }
    
    /*-- migration snippets for db updates*/                                                            
                                                         
    public function addIndex($idx){
        //added to stack for the drop fk command at the end of the run
        array_push($this->indexStack,$idx);
    }
    
    /*check if new index or existing and add to stack for post processing*/
    public function processIndex($idx,$storedTable,$table){
        $newIdx = true;
        $idx["status"]=$this->NEW;
        if(isset($storedTable)){
            //echo "processing index\n";
            /*++ check if it is a new or modified column*/
            if($this->NEW==$table["status"]){ 
                //new table with new index
                $this->addIndex($idx);
            } else {                
                foreach($storedTable->indexes->children() as $existingIdx){
                    //echo $idx["name"]."\n";
                    if($idx["name"]==$existingIdx->name) {
                        $newIdx = false;
                        break;
                    }
                }
                if($newIdx) {
                    $this->addIndex($idx); 
                } else $idx["status"]=$this->EXISTING;
            }
            /*-- check if it is a new or modified index*/
        } else $this->addIndex($idx); 
        return $newIdx;
    }
    
    /*check if new fk or existing and add to stack for post processing*/
    public function processFk($fk,$storedTable,$table){
        $newfk = true;
        $fk["status"]=$this->NEW;
        if(isset($storedTable)){
            /*++ check if it is a new or modified fk*/
            if($this->NEW==$table["status"]){ 
                //new table with new fk
                $this->addFk($col);
            } else {                
                $selectedFk = "";
                foreach($storedTable->foreignKeys->children() as $existingFk){
                    if($fk["name"]==$existingFk->name) {
                        $newfk = false;
                        break;
                    }
                }
                if($newfk) $this->addFk($fk); 
                else $fk["status"]=$this->EXISTING;
            }
            /*-- check if it is a new or modified fk*/
        } else $this->addFk($fk); 
        return $newfk;
    }
    
    /*check if new table or existing and add to stack for post processing*/
    public function processTable($table,&$storedTable){
        $newtable = true;                
        $table["status"]=$this->NEW;        
        if(isset($this->currentBd)){
            /*++ check if it is a new table*/
            foreach($this->currentBd->tables->children() as $existingTable){                                
                if($table["name"]==$existingTable->name) {
                    $newtable = false;
                    $storedTable = $existingTable;
                    break;
                }
            }
            if($newtable) $this->addTable($table);
            else $table["status"]=$this->EXISTING;
            /*-- check if it is a new table*/
        } else $this->addTable($table);
        return $table;
    }
    
    /*check if new column, updated or existing and add to stack for post processing*/
    public function processColumn($col,$storedTable,$table){
        $newcol = true;
        $col["status"] = $this->NEW;
        $storedCol = null;
        if(isset($storedTable)){
            /*++ check if it is a new or modified column*/
            if($this->NEW==$table["status"]){ 
                //new table with new column
                $this->addColumn($col);
            } else {                
                foreach($storedTable->cols->children() as $existingCol){                    
                    if($col["name"]==$existingCol->name) {
                        $newcol = false;
                        $storedCol = $existingCol;
                        break;
                    }
                }
                if($newcol) $this->addColumn($col);
                else {                    
                    if(isset($storedCol) //if column has been changed
                       && ((strcmp($col["dbType"],$storedCol->dbType)!=0)
                           || (strcmp($col["type"],$storedCol->type)!=0)
                           || (strcmp($col["size"],$storedCol->size)!=0)
                           || (strcmp($col["defaultValue"],$storedCol->defaultValue)!=0)
                           || (strcmp($col["scale"],$storedCol->scale)!=0)
                           || (strcmp($col["isPrimaryKey"],$storedCol->isPrimaryKey)!=0)
                           || (strcmp($col["autoIncrement"],$storedCol->autoIncrement)!=0)
                           || (strcmp($col["allowNull"],$storedCol->allowNull)!=0)
                           || (strcmp($col["comment"],$storedCol->comment)!=0)
                          )
                      ) {
                        $col["status"] = $this->UPDATED;
                        $col["previousState"] = $this->xmlObj2array($storedCol);
                        $this->addColumn($col);
                    }
                    else $col["status"] = $this->EXISTING;
                }
                
            }
            /*-- check if it is a new or modified column*/
        } else $this->addColumn($col); 
        return $col;
    }

    /*function to log changes*/
    public function log($text){
        $this->changelog .= $text."\n";
    }        
    
    /*get info from column (default value, allow null, is primary) and return in db format*/
    public function getColType($col) {
        if ($col->isPrimaryKey && $col->autoIncrement) {
            return "pk";
        }
        $result = $col->dbType;
        if (!$col->allowNull) {
            $result .= ' NOT NULL';
        }
        if ($col->defaultValue != null) {            
            $result .= " DEFAULT '{$col->defaultValue}'";
        } elseif ($col->allowNull) {
            $result .= ' DEFAULT NULL';
        }
        return addslashes($result);
    }
    
    // function definition to convert array to xml
    function array_to_xml($array, &$xml_bd) {        
        foreach($array as $key => $value) {
            if(is_array($value)) {
                if(!is_numeric($key)){
                    $subnode = $xml_bd->addChild("$key");
                    $this->array_to_xml($value, $subnode);
                }
                else{
                    $subnode = $xml_bd->addChild("item$key");
                    $this->array_to_xml($value, $subnode);
                }
            }
            else {
                $xml_bd->addChild("$key",htmlspecialchars("$value"));
            }
        }
    }
    
    /*function to instanciate the yii migrate command*/
    private function createMigration($name) {
        $commandPath = Yii::app()->getBasePath() . DIRECTORY_SEPARATOR . 'commands';        
        $runner = new CConsoleCommandRunner();
        $runner->addCommands($commandPath);
        $commandPath = Yii::getFrameworkPath() 
            . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'commands';
        $runner->addCommands($commandPath);
        $args = array('yiic', 'migrate','create',$name, '--interactive=0');
        ob_start();
        $runner->run($args);
        echo htmlentities(ob_get_clean(), null, Yii::app()->charset);
    }
    
    /*function to instantiate the yii migrate command and mark a file as migrated*/
    private function markMigration($name) {
        $commandPath = Yii::app()->getBasePath() . DIRECTORY_SEPARATOR . 'commands';        
        $runner = new CConsoleCommandRunner();
        $runner->addCommands($commandPath);
        $commandPath = Yii::getFrameworkPath() 
            . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'commands';
        $runner->addCommands($commandPath);
        $args = array('yiic', 'migrate','mark',$name, '--interactive=0');
        ob_start();
        $runner->run($args);
        echo htmlentities(ob_get_clean(), null, Yii::app()->charset);
    }
    
    //convert an object ($obj->val) to it's array representation ($arr['val'])
    function xmlObj2array($xml){
        $arr = array();
        foreach ($xml->children() as $r){
            $t = array();
            if(count($r->children()) == 0){
                $arr[$r->getName()] = strval($r);
            }
            else{
                $arr[$r->getName()][] = xml2array($r);
            }
        }
        return $arr;
    }
}