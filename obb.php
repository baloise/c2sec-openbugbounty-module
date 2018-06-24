<?php
/*
 Openbugbounty C2SEC Module

 Workflow:
 1.) Input: domainname of subject.
 2.) Query openbugbounty.org API for all incidents
 3.) Collect / Parse incidents
 4.) Process incidents
 5.) Evaluate 'score' / ouput-value
 6.) Format result to JSON
 7.) Return JSON

 Also provide additional methods, data, metrics
*/
namespace obb;

define('ERROR','Error');
define('URL_SPLIT_LENGTH',6);

function error($msg){
    /*
    Returns json encoded error message
    */
    return json_encode(array(ERROR=>$msg));
}

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

        foreach($this->list_values as $entry){
            if(!isset($item->$entry)){
                return error("XML Node " . $entry . " is missing.");
            }
        }
    }

    public function add($item){
         
        $test = $this->validate($item);
        if(NULL != json_decode($test)){
            return $test;
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
        try{
            $fixed =  new \DateTime($item->fixeddate);
            $report = new \DateTime($item->reporteddate);
            $this->time += $fixed->getTimestamp() - $report->getTimestamp();
        }catch(Exception $e){
            echo $e;
            return;
        }
    }
    
    public function sumUp(){
        if($this->total <= 0){
            return error("DomainData could not be summed up");
        }
        $this->percent_fixed = $this->fixed / $this->total;
        $this->average_time = $this->time / $this->total;
    }
}

class Obb {
    /*
     Mainclass of this module.
    */
    #URL for bugbounty API. Returns all incidents for a specific domain
    private $base_url = 'https://www.openbugbounty.org/api/1/search/?domain=';

    #URL for bugbounty API. Returns an Incidents by id. 
    private $id_url = 'https://www.openbugbounty.org/api/1/id/?id=';

    public function report($domain){
        /*
         Generates a report for given domain.
         Returns JSON.
         */

        $domain = htmlspecialchars($domain);

        #TODO Regex check for valid domain?
        if(NULL == $domain){
            return error("No searchterm provided");
        }

        $url = $this->base_url . $domain;
        $xml = $this->get_response($url);
        #Error is encoded in json, so we just need to know if we can decode it.
        if(NULL != json_decode($xml)){ 
            return $xml;
        }
        $domain_data = $this->process_incidents($xml);
        $final_result = json_encode($domain_data);
        if(!$final_result){
            return error("Could not encode the result");
        }
        return $final_result;
    }

    private function process_incidents($xml){
        /*
         processes XML data from openbugbounty.
         Creates and returns an instance of DomainData
         */
        $domain_data = new DomainData($xml->children()[0]->host) or die("XML Node 'host' is missing");

        foreach($xml->children() as $item){

            #check format
                    $domain_data->add($item); 
        }
        $final = $domain_data->sumUp();
        if(NULL != json_decode($final)){
            return $final;
        }
        return $domain_data;
    }

    private function get_response($url){
        /*
            curls an url, excepts XML as a result.
            returns the SimpleXMLElement or JSON (Error)
        */
        $curl_options = array(CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => 1);
        $curl = curl_init();
        curl_setopt_array($curl,$curl_options);
        $res = curl_exec($curl);
        $status = curl_getinfo($curl);
        curl_close($curl);
        if(200 != $status["http_code"]){
            return error("Could not connect to openbugbounty.org " . $status["http_code"]);
        }
        if(0 == strlen($res)){
            return error("Empty response");
        }
        $xml = simplexml_load_string($res);
        if(NULL == $xml || 0 == count($xml->children())){
            return error("The search gave no result");
        }
        return $xml;
    }

    private function get_latest_reportID(){
        /*
            Returns the latest report's ID registered on openbugbounty.org
        */ 
        $latest_reports = $this->get_response($this->base_url);

        if(NULL != json_decode($latest_reports)){
            return $latest_reports;
        } 
        if(!isset($latest_reports->children()[0]->url)){
            return error("XML Node 'url' is missing");
        }
        $latest_url = preg_split("/\//",$latest_reports->children()[0]->url);
        if(sizeof($latest_url) != URL_SPLIT_LENGTH){
            return error("URL format seems to be false." . $latest_reports->children()[0]->url);
        }
        $latest_id = $latest_url[sizeof($latest_url)-2];
        if(!is_numeric($latest_id)){
            return error("URL format seems to be false, ID is not a number" . $latest_id);
        }
        return $latest_id;
    }


    #Will be integrated in the report later on.
    private function get_all_domains(){
        /*
            Returns a list von DomainData Objects, each for a different domain.
            Iterating through all incidents
        */
        $domain_list = array();
        $latest_id = $this->get_latest_reportID();           
        for(;$counter < $latest_id;$counter++){
            sleep(1);
            $res = $this->get_response($this->id_url . $counter);
            if(NULL != json_decode($res)){
                continue;
            }
            $host = $res->children()[0]->host;
            if(NULL == $domain_list[$host]){
                $domain_list[$host] = new DomainData($host); 
            }
            # Why won't this work
            $domain_list[$host]->add($res);
        }
        foreach($domain_list as $domain_data){
            $domain_data->sumUp();
        }
        return $domain_list;
    }

    public function get_total_average_time(){
        /*
            Returns the average time of all incidents (from all domains) in seconds.
        */
    }

    public function get_total_min_time(){
        /*
            Returns the absolute minimum response time of all domain-owners in average, in seconds
        */
    }

    public function get_total_max_time(){
        /*
            Returns the absolute maximum response time of all domain-owners in average, in seconds
        */
    }
}
?>
