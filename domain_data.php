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
    public function validate($item){

        foreach($this->list_values as $entry){
            if(!isset($item->$entry)){
                throw new XMLFormatException($entry);
            }
        }
    }

    /**
     * Updates the data, if an incident got fixed
     * Warning: Validation does not happen when calling this.
     * validate($item) should be called before
     * @param SimpleXMLElement $item
     * @return int -2 if dates are corrupt, 0 in case of success
     */
    public function add_fix($item){
        
        if(1 == $item->fixed){
        
            $fixed_time = new \DateTime($item->fixeddate);
            $report_time = new \DateTime($item->reporteddate);

            $diff = $fixed_time->getTimestamp() - $report_time->getTimestamp();
            if(0 > $diff){
                echo "Fix Date was not correctly entered";
                return -2;
            }else{
                $this->time += $diff;
                $this->to_update = true;
                $this->fixed += 1;
            }
        }
        return 0;
    }

    /**
     * Adds new incident to the object.
     * @param SimpleXMLElement $item
     * @return int -2 if the dates are corrupt, -1 if the incident is a duplicate, 0 in case of success
     */   
    public function add($item){
      
        $this->validate($item);

        if(in_array((string)$item->url,$this->reports)){
            return -1;
        }
            
        if(0 > $this->add_fix($item)){
            return -2;
        }

        array_push($this->reports,(string)$item->url);
        $this->total += 1;
        $this->types[(string)$item->type] += 1;
        $this->to_update = true;
        return 0;
    }

    /**
     * When all incidents are added, this calculates the average time and ratio of fixes.
     * If the data cannot be processed (e.g. total is zero) nothing happens.
     */   
    public function sumUp(){

        $this->to_update = false;
        if(0 >= $this->total or 0 >= $this->fixed){
            $this->average_time = -1;
            return;
        }
        $this->percentage_fixed = $this->fixed / $this->total;
        if(0 ==  $this->percentage_fixed){
            $this->average_time = -1;
        }else{
            $this->average_time = $this->time / $this->fixed;
        }
    }
}
?>
