<?php
/*
    OPENBUGBOUNTY C2SEC MODULE
*/
namespace obb;


require_once 'functions.php';

class DomainData {
    /*
     Class for accumulated data for the domain

     host: domain name
     reports: list of urls for the reports on the website
     total: total number of incidents
     fixed: number of incidents fixed
     average_time: average time it took to fix the incidents (fixeddate - reporteddate)
     percent_fixed: fixed / total
     types  : associative array, (type => number)
    */
    public $host = NULL;
    public $reports = array();
    public $total = 0;
    public $fixed = 0;
    public $time = 0;
    public $average_time = NULL;
    public $percent_fixed = 0.0;
    public $types = array();


    #XML childnodes that are relevant. array is used to check the response in case the API was changed.
    private $list_values = ['host','url','type','reporteddate','fixed','fixeddate'];


    public function __construct($host){
        $this->host = (string)$host;
    }


    private function validate($item){
        /*
            Validates the input. Returns nothing or Error message
        */
        foreach($this->list_values as $entry){
            if(!isset($item->$entry)){
                throw new XMLFormatException($entry);
            }
        }
    }

    public function add($item){
        /*
            Adds new incident to the object.
        */         
        $this->validate($item);

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
    }
    
    public function sumUp(){
        /*
            When all incidents are added, this calculates the average time and ratio of fixes.
            If the data cannot be processed (e.g. total is zero) nothing happens.
        */
        if($this->total <= 0){
            return;
        }
        $this->percent_fixed = $this->fixed / $this->total;
        if(0 ==  $this->percent_fixed){
            $this->average_time = 0;
        }else{
            $this->average_time = $this->time / $this->total;
        }
    }
}
?>
