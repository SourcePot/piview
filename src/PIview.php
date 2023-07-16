<?php
/*
* This file is part of the Datapool CMS package.
* @package Datapool
* @author Carsten Wallenhauer <admin@datapool.info>
* @copyright 2023 to today Carsten Wallenhauer
* @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-v3
*/
declare(strict_types=1);

namespace SourcePot\PIview;

/**
 * A Raspberry Pi view.
 * @author Carsten Wallenhauer <admin@datapool.info>
 * @implements implements \SourcePot\Datapool\Interfaces\App
 */
class PIview implements \SourcePot\Datapool\Interfaces\App{
	
	private $oc;
	
	private $entryTable;
	private $entryTemplate=array();
    
    private $distinctGroupsAndFolders=array();

	public function __construct($oc){
		$this->oc=$oc;
		$table=str_replace(__NAMESPACE__,'',__CLASS__);
		$this->entryTable=strtolower(trim($table,'\\'));
	}

	public function init(array $oc){
		$this->oc=$oc;
		$this->entryTemplate=$oc['SourcePot\Datapool\Foundation\Database']->getEntryTemplateCreateTable($this->entryTable,$this->entryTemplate);
        $this->getDistinctGroupsAndFolders();
	}

	public function getEntryTable(){
		return $this->entryTable;
	}
	
	public function getEntryTemplate(){
		return $this->entryTemplate;
	}

	public function run(array|bool $arr=TRUE):array{
		if ($arr===TRUE){
			return array('Category'=>'Apps','Emoji'=>'&Pi;','Label'=>'PI view','Read'=>'ALL_MEMBER_R','Class'=>__CLASS__);
		} else {
            $html=$this->groupSelectorHtml($arr);
            $html.=$this->getSectionsHtml($arr);
			$arr['toReplace']['{{content}}']=$html;
			return $arr;
		}
	}
    
    /**
     * Takes the client data, e.g. from a Raspberry Pi ($arr argument) and creates a database entry.
     */
    public function piRequest($arr,$isDebugging=FALSE){
        $answer=array();
        $debugArr=array('arr'=>$arr,'_FILES'=>$_FILES);
        $piEntry=$this->oc['SourcePot\Datapool\Tools\MiscTools']->flat2arr($arr,'||');
        $piEntry['Source']=$this->entryTable;
        $piEntry['Expires']=(isset($arr['Expires']))?$arr['Expires']:$this->oc['SourcePot\Datapool\Tools\MiscTools']->getDateTime('now','PT10M');
        
        $fileArr=current($_FILES);
        if ($fileArr){
            // has attached file
            $debugArr['fileArr']=$fileArr;
            $debugArr['entry_updated']=$this->oc['SourcePot\Datapool\Foundation\Filespace']->file2entries($fileArr,$piEntry);
        } else {
            // no attached file
            $piEntry=$this->oc['SourcePot\Datapool\Foundation\Database']->unifyEntry($piEntry);
			$debugArr['entry_updated']=$this->oc['SourcePot\Datapool\Foundation\Database']->updateEntry($piEntry);
        }
        $answer=$this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2flat($piEntry,'||');
        if ($isDebugging){
            $debugArr['answer']=$answer;
            $this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2file($debugArr);
        }
        return $answer;
    }
    
    private function getDistinctGroupsAndFolders(){
        $this->distinctGroupsAndFolders=array();
        $selector=array('Source'=>$this->entryTable,'Type'=>'%piStatus%');
        foreach($this->oc['SourcePot\Datapool\Foundation\Database']->getDistinct($selector,'Group',FALSE,'Read','Group',TRUE,FALSE,FALSE,FALSE) as $group){
            $groupSelector=$selector;
            $groupSelector['Group']=$group['Group'];
            foreach($this->oc['SourcePot\Datapool\Foundation\Database']->getDistinct($groupSelector,'Folder',FALSE,'Read','Folder',TRUE,FALSE,FALSE,FALSE) as $folder){
                $this->distinctGroupsAndFolders[$groupSelector['Group']][$folder['Folder']]=array('Source'=>$this->entryTable,'Group'=>$groupSelector['Group'],'Folder'=>$folder['Folder']);
            }
        }
        return $this->distinctGroupsAndFolders;
    }
    
    /**
     * Takes the client data, e.g. from a Raspberry Pi ($arr argument) and creates a database entry.
     */
    private function groupSelectorHtml($arr,$isDebugging=FALSE){
        $debugArr=array();
        $html='';
        // get group selector
        $groupOptions=array(''=>'&larrhk;');
        foreach($this->distinctGroupsAndFolders as $group=>$folderArr){
            $groupOptions[$group]=$group;
        }
        $selector=$this->oc['SourcePot\Datapool\Tools\NetworkTools']->getPageState(__CLASS__);
        $selector['Source']=$this->entryTable;
        $formData=$this->oc['SourcePot\Datapool\Foundation\Element']->formProcessing(__CLASS__,__FUNCTION__);
		if (isset($formData['cmd']['select'])){
            $selector=array_merge($selector,$formData['val']);
            $this->oc['SourcePot\Datapool\Tools\NetworkTools']->setPageState(__CLASS__,$selector);
        }
        $html.=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->select(array('label'=>'Group selector','options'=>$groupOptions,'hasSelectBtn'=>TRUE,'key'=>array('Group'),'value'=>$selector['Group'],'keep-element-content'=>TRUE,'callingClass'=>__CLASS__,'callingFunction'=>__FUNCTION__,'class'=>'explorer'));
		$html.=$this->oc['SourcePot\Datapool\Foundation\Container']->container('All PI status','generic',$selector,array('method'=>'getAllPiStatusHtml','classWithNamespace'=>__CLASS__),array());
		if ($isDebugging){
            $debugArr['formData']=$formData;
            $debugArr['groupOptions']=$groupOptions;
            $debugArr['selector']=$selector;
            $this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2file($debugArr);
        }
        return $html;
    }
    
    /**
     * Takes the client data, e.g. from a Raspberry Pi ($arr argument) and creates a database entry.
     */
    private function getSectionsHtml($arr,$isDebugging=FALSE){
        $html='';
        $selected=$this->oc['SourcePot\Datapool\Tools\NetworkTools']->getPageState(__CLASS__);
        if (isset($this->distinctGroupsAndFolders[$selected['Group']])){
            // Group selected
            $imgShuffle=array('wrapperSetting'=>array('style'=>array('float'=>'none','padding'=>'10px','border'=>'none','margin'=>'10px auto','border'=>'1px dotted #999;')),
                              'setting'=>array('hideReloadBtn'=>TRUE,'orderBy'=>'Date','isAsc'=>FALSE,'limit'=>20,'style'=>array('width'=>500,'height'=>400),'autoShuffle'=>FALSE,'getImageShuffle'=>'PIview'),
                              'selector'=>array(),
                              );
            $html.=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->element(array('tag'=>'h2','element-content'=>'Client Group'.$selected['Group'],'keep-element-content'=>TRUE));
            foreach($this->distinctGroupsAndFolders[$selected['Group']] as $folder=>$entrySelector){
                $html.=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->element(array('tag'=>'h3','element-content'=>$folder,'keep-element-content'=>TRUE));
                $imgShuffle['selector']=$entrySelector;
                $imgShuffle['selector']['Type']='%piMedia%';
                $html.=$this->oc['SourcePot\Datapool\Foundation\Container']->container('CCTV '.$imgShuffle['selector']['Folder'],'getImageShuffle',$imgShuffle['selector'],$imgShuffle['setting'],$imgShuffle['wrapperSetting']);
            } // loop through folders
        } else {
            // no valid group selected
        }
        return $html;
    }
    
    /**
     * Takes the client data, e.g. from a Raspberry Pi ($arr argument) and creates a database entry.
     */
    public function getAllPiStatusHtml($arr,$isDebugging=FALSE){
        $debugArr=array('arr in'=>$arr);
        $definition=array('Date'=>array('@tag'=>'p','@default'=>'','@excontainer'=>TRUE),
                          'Content'=>array('cpuTemperature'=>array('@tag'=>'p','@default'=>'','@excontainer'=>TRUE),
                                           'activity'=>array('@tag'=>'p','@default'=>'','@excontainer'=>TRUE),
                                          ),
                         );
        $flatDefinition=$this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2flat($definition);
        $arr['html']='';
        foreach($this->distinctGroupsAndFolders as $group=>$folderArr){
            $matrix=array();
            foreach($folderArr as $folder=>$selector){
                foreach($this->oc['SourcePot\Datapool\Foundation\Database']->entryIterator($selector,FALSE,'Read','Date',FALSE,1,0,array(),TRUE,FALSE) as $piEntry){
                    $debugArr['most current entries'][]=$piEntry;
                    $flatPiEntry=$this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2flat($piEntry);
                    foreach($flatPiEntry as $flatKey=>$value){
                        $sepPos=intval(strrpos($flatKey,$this->oc['SourcePot\Datapool\Tools\MiscTools']->getSeparator()));
                        $column=trim(substr($flatKey,$sepPos),'|[]');
                        $element=$this->oc['SourcePot\Datapool\Foundation\Definitions']->selectorKey2element($piEntry,$flatKey,$value,__CLASS__,__FUNCTION__,TRUE,array('Content'=>$definition));    
                        if (!empty($element)){
                            $matrix[$folder][$column]=$element;
                        }
                    }
                } // loop through folder
                $arr['html'].=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->table(array('matrix'=>$matrix,'hideHeader'=>FALSE,'hideKeys'=>FALSE,'caption'=>$group,'keep-element-content'=>TRUE));
            } // loop through groups
        }
        if ($isDebugging){
            $debugArr['distinctGroupsAndFolders']=$this->distinctGroupsAndFolders;
            $this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2file($debugArr);
        }
        return $arr;
    }    
}
?>