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

    #XML childnodes that are relevant. array is used to check the response in case the API was changed.
    private $list_values = ['host','url','type','reporteddate','fixed','fixeddate'];


    public function __construct($host){
        $this->host = (string)$host;
    }

    /**
     * Validates the input.
     * @param SimpleXMLElement $item
     * @throws XMLFormatException if the XML structure is different than expected
     */
    public function validate($item){

        foreach($this->list_values as $entry){
            if(!isset($item,$entry)){
                throw new XMLFormatException($entry);
            }
        }
    }


    /**
     * Adds new incident to the object.
     * @param Databas row $item
     * @return int -2 if the dates are corrupt, -1 if the incident is a duplicate, 0 in case of success
     */   
    public function add($item){
      
        $this->validate($item);


        $this->total += 1;
        array_push($this->types,$item["type"]);
        array_push($this->reports,$item["report"]);
        $diff = 0;
        try{
            $reporteddate = new \DateTime($item["reporteddate"]);

            if(NULL != $item["fixeddate"]){
            
                $fixeddate = new \DateTime($item["fixeddate"]);
                $diff = $fixeddate->getTimestamp() - $reporteddate->getTimestamp();
                if($diff < 0){
                    return;
                }
                $this->fixed += 1;
            }else{
                $now = new \Datetime();
                $diff = $now->getTimestamp() - $reporteddate->getTimestamp();
            }
        }catch(\Exception $e){
            echo $e;
            return;
        }

        $this->time += $diff;
    }

    /**
     * When all incidents are added,
     * If the data cannot be processed (e.g. total is zero) nothing happens.
     */   
    public function sumUp(){

        $this->types = array_count_values($this->types);

        if(0 >= $this->total or 0 >= $this->fixed){
            $this->average_time = -1;
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
