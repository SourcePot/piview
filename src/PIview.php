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
    private $piSettings=array();
    
    private $pageSettings=array();

	public function __construct($oc){
		$this->oc=$oc;
		$table=str_replace(__NAMESPACE__,'',__CLASS__);
		$this->entryTable=strtolower(trim($table,'\\'));
	}

	public function init(array $oc){
		$this->oc=$oc;
        $this->pageSettings=$this->oc['SourcePot\Datapool\Foundation\Backbone']->getSettings();
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
            $html=$this->groupSelectorAndStatusHtml($arr);
            $html.=$this->getSectionsHtml($arr);
            $arr['toReplace']['{{content}}']=$html;
			return $arr;
		}
	}
    
    /**
     * Takes the client data, e.g. from a Raspberry Pi ($arr argument) and creates a database entry
     */
    public function piRequest($arr,$isDebugging=FALSE){
        $answer=array();
        $debugArr=array('arr'=>$arr,'_FILES'=>$_FILES);
        $piEntry=$this->oc['SourcePot\Datapool\Tools\MiscTools']->flat2arr($arr,'||');
        $piEntry['Source']=$this->entryTable;
        $piEntry['Expires']=(isset($arr['Expires']))?$arr['Expires']:$this->oc['SourcePot\Datapool\Tools\MiscTools']->getDateTime('now','P10D');
        if (isset($piEntry['Content']['timestamp'])){
            $pageSettings=$this->oc['SourcePot\Datapool\Foundation\Backbone']->getSettings();
            $piEntryDateTime=new \DateTime('@'.$piEntry['Content']['timestamp']);
            $serverTimezone=new \DateTimeZone($pageSettings['pageTimeZone']);
            $piEntryDateTime->setTimezone($serverTimezone);
            $piEntry['Date']=$piEntryDateTime->format('Y-m-d H:i:s');
        }
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
        // return pi setting
        $piSetting=$this->getPiSetting($piEntry);
        $answer=$this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2flat($piSetting,'||');
        if ($isDebugging){
            $debugArr['answer']=$answer;
            $this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2file($debugArr);
        }
        return $answer;
    }
    
    /**
     * Load client structure, Groups and Folders into class property distinctGroupsAndFolders
     */
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
     * Status overview re all clients and Group selection
     */
    private function groupSelectorAndStatusHtml($arr,$isDebugging=FALSE){
        $debugArr=array();
        $html='';
        // get group selector
        foreach($this->distinctGroupsAndFolders as $group=>$folderArr){
            $selector=current($folderArr);
            unset($selector['Folder']);
            $statusHtml=$this->oc['SourcePot\Datapool\Foundation\Container']->container('PI status '.$group,'generic',$selector,array('method'=>'getPiStatusHtml','classWithNamespace'=>__CLASS__),array('style'=>array('border'=>'none')));
            $statusHtml.=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->element(array('tag'=>'button','element-content'=>'Select '.$selector['Group'],'keep-element-content'=>TRUE,'key'=>array('select',$selector['Group']),'callingClass'=>__CLASS__,'callingFunction'=>__FUNCTION__));
		    $html.=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->element(array('tag'=>'div','element-content'=>$statusHtml,'keep-element-content'=>TRUE,'style'=>array('clear'=>'none','margin'=>'10px')));
        }
        $html=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->element(array('tag'=>'article','element-content'=>$html,'keep-element-content'=>TRUE));    
        $selector=$this->oc['SourcePot\Datapool\Tools\NetworkTools']->getPageState(__CLASS__);
        $selector['Source']=$this->entryTable;
        $formData=$this->oc['SourcePot\Datapool\Foundation\Element']->formProcessing(__CLASS__,__FUNCTION__);
		if (isset($formData['cmd']['select'])){
            $selector['Group']=key($formData['cmd']['select']);
            $this->oc['SourcePot\Datapool\Tools\NetworkTools']->setPageState(__CLASS__,$selector);
        }
        if ($isDebugging){
            $debugArr['formData']=$formData;
            $debugArr['groupOptions']=$groupOptions;
            $debugArr['selector']=$selector;
            $this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2file($debugArr);
        }
        return $html;
    }
    
    /**
     * Pi status container plugin
     */
    public function getPiStatusHtml($arr,$isDebugging=FALSE){
        $debugArr=array('arr in'=>$arr);
        $definition=array('Date'=>array('@tag'=>'p','@default'=>'','@excontainer'=>TRUE),
                          'Content'=>array('cpuTemperature'=>array('@tag'=>'p','@default'=>'','@excontainer'=>TRUE),
                                           'mode'=>array('@tag'=>'p','@default'=>'','@excontainer'=>TRUE),
                                           'light'=>array('@tag'=>'p','@default'=>'','@excontainer'=>TRUE),
                                           'alarm'=>array('@tag'=>'p','@default'=>'','@excontainer'=>TRUE),
                                           'activity'=>array('@tag'=>'p','@default'=>'','@excontainer'=>TRUE),
                                          ),
                         );
        $flatDefinition=$this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2flat($definition);
        $arr['html']='';
        $matrix=array();
        foreach($this->distinctGroupsAndFolders[$arr['selector']['Group']] as $folder=>$selector){
            $selector['Type']='%piStatus%';
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
            }
        } // loop through folder
        $arr['html'].=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->table(array('matrix'=>$matrix,'hideHeader'=>FALSE,'hideKeys'=>FALSE,'caption'=>$arr['selector']['Group'],'keep-element-content'=>TRUE));
        if ($isDebugging){
            $debugArr['distinctGroupsAndFolders']=$this->distinctGroupsAndFolders;
            $this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2file($debugArr);
        }
        return $arr;
    }    
   
    /**
     * PI client control and view
     */
    private function getSectionsHtml($arr,$isDebugging=FALSE){
        $html='';
        $selected=$this->oc['SourcePot\Datapool\Tools\NetworkTools']->getPageState(__CLASS__);
        if (empty($selected['Group'])){
            // no valid group selected
        } else {
            // Group selected
            $imgShuffle=array('wrapperSetting'=>array('style'=>array('clear'=>'left','padding'=>'10px','border'=>'none','margin'=>'10px auto','border'=>'1px dotted #999;','width'=>'fit-content')),
                              'setting'=>array('hideReloadBtn'=>TRUE,'orderBy'=>'Date','isAsc'=>FALSE,'limit'=>20,'style'=>array('width'=>500,'height'=>285),'autoShuffle'=>FALSE,'getImageShuffle'=>'PIview'),
                              'selector'=>array(),
                              );
            $html.=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->element(array('tag'=>'h2','element-content'=>'Client Group '.$selected['Group'],'keep-element-content'=>TRUE,'style'=>array('float'=>'left','clear'=>'left')));
            foreach($this->distinctGroupsAndFolders[$selected['Group']] as $folder=>$entrySelector){
                $folderHtml=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->element(array('tag'=>'h3','element-content'=>$folder,'keep-element-content'=>TRUE,'style'=>array('float'=>'left','clear'=>'both')));
                $imgShuffle['selector']=$entrySelector;
                $imgShuffle['selector']['Type']='%piMedia%';
                $folderHtml.=$this->oc['SourcePot\Datapool\Foundation\Container']->container('CCTV '.$imgShuffle['selector']['Folder'],'getImageShuffle',$imgShuffle['selector'],$imgShuffle['setting'],$imgShuffle['wrapperSetting']);
                $folderHtml.=$this->oc['SourcePot\Datapool\Foundation\Container']->container('PI settings '.$group.'|'.$folder,'generic',$imgShuffle['selector'],array('method'=>'getPiSettingsHtml','classWithNamespace'=>__CLASS__),array('style'=>array('float'=>'left','clear'=>'right','width'=>'fit-content','border'=>'none','margin'=>'0')));
                $html.=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->element(array('tag'=>'article','element-content'=>$folderHtml,'keep-element-content'=>TRUE));
            } // loop through folders
        }
        return $html;
    }
    
    private function getActivityChartHtml($arr){
        $selector=$selected=$this->oc['SourcePot\Datapool\Tools\NetworkTools']->getPageState(__CLASS__);
        $html=$this->oc['SourcePot\Datapool\Foundation\Container']->container('PI activity','generic',$selector,array('classWithNamespace'=>__CLASS__,'method'=>'piActivityChart'),array());	
		$html=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->element(array('tag'=>'article','element-content'=>$html,'keep-element-content'=>TRUE));    
        return $html;
    }
    
    public function piActivityChart($arr){
        $arr['html']='';
        $traces=array();
        foreach($this->oc['SourcePot\Datapool\Foundation\Database']->entryIterator($arr['selector'],FALSE,'Read','Date',TRUE) as $piEntry){
            
            $timeZone=new \DateTimeZone($this->pageSettings['pageTimeZone']);
            $dateTime=new \DateTime($piEntry['Date'],$timeZone);
		
            $traces[$piEntry['Group']]['id']=$piEntry['Folder'];
            $traces[$piEntry['Group']]['name']=$piEntry['Folder'];
            $traces[$piEntry['Group']]['label']=array();
            $traces[$piEntry['Group']]['x']['scale']['tickCount']=1;
            $traces[$piEntry['Group']]['x']['dataType']='dateTime';
            $traces[$piEntry['Group']]['x']['data'][]=$dateTime->getTimestamp();
            $traces[$piEntry['Group']]['x']['timeStampMin']=time()-3600;
            $traces[$piEntry['Group']]['x']['timeStampMax']=time();
            $traces[$piEntry['Group']]['y']['scale']['tickCount']=3;
            $traces[$piEntry['Group']]['y']['dataType']='float';
            $traces[$piEntry['Group']]['y']['data'][]=intval($piEntry['Content']['activity']);
        	$traces[$piEntry['Group']]['label'][]=intval($piEntry['Content']['activity']);
        }
        $arr['chart']=array('width'=>400,'height'=>200,'gaps'=>array(10,20,20,20));
        foreach($traces as $name=>$trace){
            $trace['stroke']='rgb(100,0,0)';
            $trace['show']=TRUE;
            $trace['bar']['show']=TRUE;
            $trace['point']['show']=TRUE;
		    $arr=$this->oc['SourcePot\Datapool\Foundation\LinearChart']->addTrace($arr,$trace);
        }
        $arr['html']=$this->oc['SourcePot\Datapool\Foundation\LinearChart']->chartSvg($arr);
        return $arr;
	}
    
    /**
     * PI settings
     */
    private function getPiSettingSelector($selector){
        $template=array('Source'=>$this->entryTable,'Type'=>'piSetting','Name'=>'Pi entry');
        return array_merge($selector,$template);
    }
    
    private function getPiSetting($selector){
        $piEntry=$this->getPiSettingSelector($selector);
        $piEntry=$this->oc['SourcePot\Datapool\Tools\MiscTools']->addEntryId($piEntry,array('Group','Folder','Name','Type'),0);
		$piEntry['Content']=array('mode'=>'capturing','captureTime'=>3600,'light'=>0,'alarm'=>0);
        return $this->oc['SourcePot\Datapool\Foundation\Database']->entryByIdCreateIfMissing($piEntry,TRUE);
    }
    
    /**
     * PI settings container plugin
     */
    public function getPiSettingsHtml($arr){
        $arr['html']='';
        $optionsArr=array('mode'=>array('idle'=>'Idle','capturing'=>'Capturing','sms'=>'SMS','alarm'=>'Alarm'),
                          'captureTime'=>array('20'=>'every 20 sec','600'=>'every 10 min','3600'=>'every 1 hour','28800'=>'every 8 hours'),
                          'light'=>array('0'=>'Off','1'=>'On'),
                          'alarm'=>array('0'=>'Off','1'=>'On'),
                          'activityThreshold'=>array('5'=>'>4','10'=>'>9','20'=>'>19'),
                          'Recipient mode'=>array('Email'=>'Email','Mobile'=>'SMS'),
                          'Recipient'=>array(),
                          );
        $arr['selector']=$this->getPiSetting($arr['selector']);
        $formData=$this->oc['SourcePot\Datapool\Foundation\Element']->formProcessing($arr['callingClass'],$arr['callingFunction']);
		if (!empty($formData['val'])){
            $arr['selector']=array_replace_recursive($arr['selector'],$formData['val']);
            $arr['selector']=$this->oc['SourcePot\Datapool\Foundation\Database']->updateEntry($arr['selector']);
        }
        $matrix=array();
        $recipientMode=(isset($arr['selector']['Content']['Recipient mode']))?$arr['selector']['Content']['Recipient mode']:'Mobile';
        $optionsArr['Recipient']=$this->oc['SourcePot\Datapool\Foundation\User']->getUserOptions(array(),$recipientMode);
        foreach($optionsArr as $contentKey=>$options){
            $selected=(isset($arr['selector']['Content'][$contentKey]))?$arr['selector']['Content'][$contentKey]:'';
            $matrix[$contentKey]['Value']=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->select(array('options'=>$options,'selected'=>$selected,'keep-element-content'=>TRUE,'key'=>array('Content',$contentKey),'style'=>array(),'callingClass'=>$arr['callingClass'],'callingFunction'=>$arr['callingFunction']));
        }
        $matrix['Activity']['Value']=$this->getActivityChartHtml($arr);
        $arr['html'].=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->table(array('matrix'=>$matrix,'hideHeader'=>TRUE,'hideKeys'=>FALSE,'caption'=>$arr['selector']['Folder'],'keep-element-content'=>TRUE));
        return $arr;
    }
}
?>