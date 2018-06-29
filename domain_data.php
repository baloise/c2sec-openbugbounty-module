<?php
/**
 * OPENBUGBOUNTY C2SEC MODULE
 *
 *
 */
namespace obb;


require_once 'functions.php';


/**
 * Class for accumulated data for the domain
 */
class DomainData {

    public $host = NULL;
    public $reports = array();
    public $total = 0;
    public $fixed = 0;
    public $time = 0;
    public $average_time = NULL;
    public $percentage_fixed = 0.0;
    public $types = array();
    public $to_update;

    #XML childnodes that are relevant. array is used to check the response in case the API was changed.
    private $list_values = ['host','url','type','reporteddate','fixed','fixeddate'];


    public function __construct($host){
        $this->host = (string)$host;
        $this->to_update = true;
    }

    /**
     * Validates the input.
     * @param SimpleXMLElement $item
     * @throws XMLFormatException if the XML structure is different than expected
     */
    private function validate($item){

        foreach($this->list_values as $entry){
            if(!isset($item->$entry)){
                throw new XMLFormatException($entry);
            }
        }
    }

    /**
     * Adds new incident to the object.
     * @param SimpleXMLElement $item
     */   
    public function add($item){
      
        $this->validate($item);

        if(in_array((string)$item->url,$this->reports)){
            return;
        }

        array_push($this->reports,(string)$item->url);
        $this->total += 1;

        if(1 == $item->fixed){
            $this->fixed += 1;
        }
        $this->types[(string)$item->type] += 1;
        if(NULL == $item->fixeddate){
            return;
        }
        #requires date.timezone in php.ini to be set
        $fixed =  new \DateTime($item->fixeddate);
        $report = new \DateTime($item->reporteddate);
        $this->time += $fixed->getTimestamp() - $report->getTimestamp();
        if($this->time < 0){
            #log
            echo "Fix Date was not correctly entered";
        }
        $this->to_update = true;
    }

    /**
     * When all incidents are added, this calculates the average time and ratio of fixes.
     * If the data cannot be processed (e.g. total is zero) nothing happens.
     */   
    public function sumUp(){

        $this->to_update = false;
        if($this->total <= 0){
            return;
        }
        $this->percentage_fixed = $this->fixed / $this->total;
        if(0 ==  $this->percentage_fixed){
            $this->average_time = -1;
        }else{
            $this->average_time = $this->time / $this->total;
        }
    }
}
?>
