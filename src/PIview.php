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
            // selector html
            $htmlSelector=$this->groupSelectorHtml($arr);
            $selector=$this->oc['SourcePot\Datapool\Tools\NetworkTools']->getPageState(__CLASS__);
            $genSelector=array('Source'=>$selector['Source'],
                               'Group'=>((isset($selector['Group']))?$selector['Group']:FALSE),
                               'Folder'=>((isset($selector['Folder']))?$selector['Folder']:FALSE),
                               );
            // add event chart
            $settings=array('classWithNamespace'=>__CLASS__,'method'=>'getPiViewEventsChart','width'=>600);
            $htmlEventChart=$this->oc['SourcePot\Datapool\Foundation\Container']->container('PI view events','generic',$genSelector,$settings,array());    
            // sections
            $htmlSections=$this->getSectionsHtml($arr);
            // finalize page
            $arr['toReplace']['{{content}}']=$htmlSelector.$htmlSections.$htmlEventChart;
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
        $piEntry=$this->oc['SourcePot\Datapool\Foundation\Access']->addRights($piEntry,'SENTINEL_R','SENTINEL_R');
        if (isset($piEntry['Content']['timestamp'])){
            $piEntry['Date']=$this->oc['SourcePot\Datapool\Tools\MiscTools']->getDateTime('@'.$piEntry['Content']['timestamp'],FALSE,$this->pageSettings['pageTimeZone']);
        }
        $piEntry['Owner']=$_SESSION['currentUser']['EntryId'];
        $fileArr=current($_FILES);
        if ($fileArr){
            // has attached file
            $piEntry['Expires']=(isset($arr['Expires']))?$arr['Expires']:$this->oc['SourcePot\Datapool\Tools\MiscTools']->getDateTime('now','P10D');
            $debugArr['fileArr']=$fileArr;
            $debugArr['entry_updated']=$this->oc['SourcePot\Datapool\Foundation\Filespace']->file2entries($fileArr,$piEntry);
        } else {
            // no attached file
            $piEntry['Date']=$this->oc['SourcePot\Datapool\Tools\MiscTools']->getDateTime();
            $piEntry['Expires']=(isset($arr['Expires']))?$arr['Expires']:$this->oc['SourcePot\Datapool\Tools\MiscTools']->getDateTime('now','P1D');
            $piEntry=$this->oc['SourcePot\Datapool\Foundation\Database']->unifyEntry($piEntry);
			$piEntry=$this->oc['SourcePot\Datapool\Tools\MiscTools']->addEntryId($piEntry,array('Group','Folder','Type'),0);
            $debugArr['entry_to_update']=$piEntry;
            $debugArr['entry_updated']=$this->oc['SourcePot\Datapool\Foundation\Database']->updateEntry($piEntry);
        }
        // return pi setting
        $piSetting=$this->getPiSetting($piEntry);
        $this->sendMessage($piEntry,$piSetting);
        $answer=$this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2flat($piSetting,'||');
        if ($isDebugging){
            $debugArr['answer']=$answer;
            $debugArr['currentUser']=$this->oc['SourcePot\Datapool\Foundation\User']->getCurrentUser();
            $this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2file($debugArr);
        }
        return $answer;
    }
    
    private function sendMessage($piEntry,$piSetting){
        if (strcmp($piEntry['Content']['mode'],'message')===0 || strcmp($piEntry['Content']['mode'],'alarm')===0){
            if ($piEntry['Content']['activity']>=$piSetting['Content']['activityThreshold']){
                // create message entry
                $transmissionEntry=$piEntry;
                $transmissionEntry['Name']='PIview';
                $transmissionEntry['Type']='transmission';
                $transmissionEntry=$this->oc['SourcePot\Datapool\Tools\MiscTools']->addEntryId($transmissionEntry,array('Source','Group','Folder','Type'),'0','',FALSE);
                $lastTransmissionEntry=$this->oc['SourcePot\Datapool\Foundation\Database']->entryById($transmissionEntry,TRUE);
                $transmissionEntry['Expires']=$this->oc['SourcePot\Datapool\Tools\MiscTools']->getDateTime('tomorrow');
                $transmissionEntry['Date']=$this->oc['SourcePot\Datapool\Tools\MiscTools']->getDateTime('now');
                $transmissionEntry['Content']=array('Group'=>$piEntry['Group'],'Folder'=>$piEntry['Folder']);
                if (isset($piEntry['Content']['activity'])){$transmissionEntry['Content']['activity']='activity='.$piEntry['Content']['activity'];}
                if (isset($piEntry['Content']['mode'])){$transmissionEntry['Content']['mode']='mode='.$piEntry['Content']['mode'];}
                if (isset($piEntry['Content']['cpuTemperature'])){$transmissionEntry['Content']['cpuTemp']='cpuTemp='.$piEntry['Content']['cpuTemperature'].' C';}
                // send message if new
                if (empty($lastTransmissionEntry['Date'])){
                    $lastTransmissionEntry['Date']=$this->oc['SourcePot\Datapool\Tools\MiscTools']->getDateTime('yesterday');
                }
                $age=strtotime($transmissionEntry['Date'])-strtotime($lastTransmissionEntry['Date']);
                if ($age>120){
                    if (isset($this->oc[$piSetting['Content']['Transmitter']])){
                        $sentEntriesCount=$this->oc[$piSetting['Content']['Transmitter']]->send($piSetting['Content']['Recipient'],$transmissionEntry);
                        if ($sentEntriesCount){
                            $this->oc['SourcePot\Datapool\Foundation\Database']->updateEntry($transmissionEntry,TRUE);
                            $this->oc['SourcePot\Datapool\Foundation\Logging']->addLog(array('msg'=>'Message sent','priority'=>12,'callingClass'=>__CLASS__,'callingFunction'=>__FUNCTION__));
                        } // if message was sent
                    } else {
                        $this->oc['SourcePot\Datapool\Foundation\Logging']->addLog(array('msg'=>'Failed to send message: transmitter not valid','priority'=>14,'callingClass'=>__CLASS__,'callingFunction'=>__FUNCTION__));
                    } // if valid transmitter
                } else {
                    $this->oc['SourcePot\Datapool\Foundation\Logging']->addLog(array('msg'=>'Failed to send message: message sent already '.$age.'sec ago','priority'=>11,'callingClass'=>__CLASS__,'callingFunction'=>__FUNCTION__));
                } // if new message
            } // if activity greater activity threshold
        } // if message or alarm mode
    }

    public function getPiViewEventsChart($arr){
        if (!isset($arr['html'])){$arr['html']='';}
        // init settings
        $settingOptions=array('timespan'=>array('600'=>'10min','3600'=>'1hr','43200'=>'12hrs','86400'=>'1day'),
                              'width'=>array(300=>'300px',600=>'600px',1200=>'1200px'),
                              'height'=>array(300=>'300px',600=>'600px',1200=>'1200px'),
                              );
        foreach($settingOptions as $settingKey=>$options){
            if (!isset($arr['settings'][$settingKey])){$arr['settings'][$settingKey]=key($options);}
        }
        // process form
        $formData=$this->oc['SourcePot\Datapool\Foundation\Element']->formProcessing($arr['callingClass'],$arr['callingFunction']);
        if (!empty($formData['cmd'])){
            $arr['settings']=array_merge($arr['settings'],$formData['val']['settings']);
        }
        // get instance of EventChart
        require_once($GLOBALS['dirs']['php'].'Foundation/charts/EventChart.php');
        $chart=new \SourcePot\Datapool\Foundation\Charts\EventChart($this->oc,$arr['settings']);
        // get selectors
        $selector=array('Source'=>$arr['selector']['Source']);
        $selector['Group']=(isset($arr['selector']['Group']))?$arr['selector']['Group']:FALSE;
        foreach($this->oc['SourcePot\Datapool\Foundation\Database']->entryIterator($selector,FALSE,'Read','Date') as $entry){
            if (!isset($entry['Content']['activity'])){continue;}
            $activity=intval($entry['Content']['activity']);
            $event=array('name'=>ucfirst($entry['Group']).'|'.$entry['Folder'],'timestamp'=>$entry['Content']['timestamp'],'value'=>$activity);
            $chart->addEvent($event);
        }
        if (empty($entry)){return $arr;}
        // compile html
        $arr['html'].=$chart->getChart(ucfirst($arr['selector']['Source']));
        $cntrArr=array('callingClass'=>$arr['callingClass'],'callingFunction'=>$arr['callingFunction'],'excontainer'=>FALSE);
        $matrix=array('Cntr'=>array());
        foreach($settingOptions as $settingKey=>$options){
            $cntrArr['options']=$options;
            $cntrArr['selected']=$arr['settings'][$settingKey];
            $cntrArr['key']=array('settings',$settingKey);
            $matrix['Cntr'][$settingKey]=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->select($cntrArr);
        }
        $arr['html'].=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->table(array('matrix'=>$matrix,'style'=>'clear:left;','hideHeader'=>FALSE,'hideKeys'=>TRUE,'keep-element-content'=>TRUE));
        return $arr;
    }
        
    /**
     * Load client structure, Groups and Folders into class property distinctGroupsAndFolders
     */
    private function getDistinctGroupsAndFolders(){
        $this->distinctGroupsAndFolders=array();
        $selector=array('Source'=>$this->entryTable,'Type'=>'%pi%');
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
    private function groupSelectorHtml($arr,$isDebugging=FALSE){
        $debugArr=array();
        $html='';
        // get group selector
        foreach($this->distinctGroupsAndFolders as $group=>$folderArr){
            $selector=current($folderArr);
            unset($selector['Folder']);
            $statusHtml=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->element(array('tag'=>'button','element-content'=>'Select '.$selector['Group'],'keep-element-content'=>TRUE,'key'=>array('select',$selector['Group']),'callingClass'=>__CLASS__,'callingFunction'=>__FUNCTION__));
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
                $settingSelector=$entrySelector;
                $settingSelector['Type']='piSetting';
                $settingSelector['disableAutoRefresh']=TRUE;
                $folderHtml.=$this->oc['SourcePot\Datapool\Foundation\Container']->container('PI settings '.$selected['Group'].'|'.$folder,'generic',$settingSelector,array('method'=>'getPiSettingsHtml','classWithNamespace'=>__CLASS__),array('style'=>array('float'=>'left','clear'=>'right','width'=>'fit-content','border'=>'none','margin'=>'0')));
                $statusSelector=$entrySelector;
                $statusSelector['Type']='piStatus';
                $folderHtml.=$this->oc['SourcePot\Datapool\Foundation\Container']->container('PI status '.$selected['Group'].'|'.$folder,'generic',$statusSelector,array('method'=>'getPiStatusHtml','classWithNamespace'=>__CLASS__),array('style'=>array('float'=>'left','clear'=>'right','width'=>'fit-content','border'=>'none','margin'=>'0')));
                $html.=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->element(array('tag'=>'article','element-content'=>$folderHtml,'keep-element-content'=>TRUE));
            } // loop through folders
        }
        return $html;
    }
    
    /**
     * PI settings
     */
    private function getPiSettingSelector($selector){
        $template=array('Source'=>$this->entryTable,'Type'=>'piSetting','Name'=>'Pi entry');
        $selector=array_merge($selector,$template);
        $selector=$this->oc['SourcePot\Datapool\Tools\MiscTools']->addEntryId($selector,array('Group','Folder','Name','Type'),0);
        return $selector;
    }
    
    private function getPiSetting($selector){
        $piEntry=$this->getPiSettingSelector($selector);
        $piEntry=$this->oc['SourcePot\Datapool\Foundation\Access']->addRights($piEntry,'SENTINEL_R','SENTINEL_R');
        $piEntry['Content']=array('mode'=>'capturing','captureTime'=>3600,'light'=>0,'alarm'=>0);
        $piEntry['Expires']='2999-01-01 01:00:00';
        return $this->oc['SourcePot\Datapool\Foundation\Database']->entryByIdCreateIfMissing($piEntry,TRUE);
    }
    
    /**
     * PI settings container plugin
     */
    public function getPiSettingsHtml($arr){
        $arr['html']='';
        $optionsArr=array('mode'=>array('idle'=>'Idle','capturing'=>'Capturing','message'=>'Message','alarm'=>'Alarm'),
                          'captureTime'=>array('20'=>'every 20 sec','600'=>'every 10 min','3600'=>'every 1 hour','28800'=>'every 8 hours'),
                          'light'=>array('0'=>'Off','1'=>'On'),
                          'alarm'=>array('0'=>'Off','1'=>'On'),
                          'activityThreshold'=>array('3'=>'>2','5'=>'>4','10'=>'>9','20'=>'>19'),
                          'Transmitter'=>array(),
                          'Recipient'=>array(),
                          );
        $arr['selector']=$this->getPiSetting($arr['selector']);
        $formData=$this->oc['SourcePot\Datapool\Foundation\Element']->formProcessing($arr['callingClass'],$arr['callingFunction']);
		if (!empty($formData['val'])){
            $arr['selector']=array_replace_recursive($arr['selector'],$formData['val']);
            $arr['selector']=$this->oc['SourcePot\Datapool\Foundation\Database']->updateEntry($arr['selector']);
        }
        $optionsArr['Transmitter']=$this->oc['SourcePot\Datapool\Root']->getImplementedInterfaces('SourcePot\Datapool\Interfaces\Transmitter');
        $transmitter=(isset($arr['selector']['Content']['Transmitter']))?$arr['selector']['Content']['Transmitter']:key($optionsArr['Transmitter']);
        $relevantFlatUserContentKey=$this->oc[$transmitter]->getRelevantFlatUserContentKey();
        $optionsArr['Recipient']=$this->oc['SourcePot\Datapool\Foundation\User']->getUserOptions(array(),$relevantFlatUserContentKey);
        $matrix=array();
        foreach($optionsArr as $contentKey=>$options){
            $selected=(isset($arr['selector']['Content'][$contentKey]))?$arr['selector']['Content'][$contentKey]:'';
            $matrix[$contentKey]['Preset']=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->select(array('options'=>$options,'selected'=>$selected,'keep-element-content'=>TRUE,'key'=>array('Content',$contentKey),'style'=>array(),'callingClass'=>$arr['callingClass'],'callingFunction'=>$arr['callingFunction']));
        }
        $arr['html'].=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->table(array('matrix'=>$matrix,'hideHeader'=>FALSE,'hideKeys'=>FALSE,'caption'=>$arr['selector']['Folder'],'keep-element-content'=>TRUE));
        return $arr;
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
        foreach($this->oc['SourcePot\Datapool\Foundation\Database']->entryIterator($arr['selector'],FALSE,'Read','Date',FALSE,1,0,array(),TRUE,FALSE) as $piEntry){
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
        $arr['html'].=$this->oc['SourcePot\Datapool\Tools\HTMLbuilder']->table(array('matrix'=>$matrix,'hideHeader'=>FALSE,'hideKeys'=>FALSE,'caption'=>$arr['selector']['Group'],'keep-element-content'=>TRUE));
        if ($isDebugging){
            $this->oc['SourcePot\Datapool\Tools\MiscTools']->arr2file($debugArr);
        }
        return $arr;
    }
}
?>