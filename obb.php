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


function error($msg){
    /*
    Returns json encoded error message
    */
    return json_encode(array(ERROR=>$msg));
}

class EvalIncidents {
    /*
     "Struct" for accumulated data for the domain

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
    public $average_time = NULL;
    public $percent_fixed = 0.0;
    public $types = array();
}

class Obb {
    /*
     Mainclass of this module.
    */
    #URL for bugbounty API. Returns all incidents for a specific domain
    private $base_url = 'https://www.openbugbounty.org/api/1/search/?domain=';
    #XML childnodes that are relevant. array is used to check the response in case the API was changed.
    private $list_values = ['url','type','reporteddate','fixed','fixeddate'];

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
        $eval_incidents = $this->process_incidents($xml);
        #TODO: urls / hostname should not be put into another associative array
        $final_result = json_encode($eval_incidents);
        if(!$final_result){
            return error("Could not encode the result");
        }
        return $final_result;
    }

    private function process_incidents($xml){
        /*
         processes XML data from openbugbounty.
         Creates and returns an instance of EvalIncidents
         */
        $eval_incidents = new EvalIncidents();
        $time = 0;

        foreach($xml->children() as $item){

            #check format
            foreach($this->list_values as $entry){
                if(!isset($item->$entry)){
                    return error("XML Node " . $entry . " is missing.");
                }
            }
            array_push($eval_incidents->reports,$item->url);
            $eval_incidents->total += 1;

            if(1 == $item->fixed){
                $eval_incidents->fixed += 1;
            }
            $eval_incidents->types[(string)$item->type] += 1;
            if(NULL == $item->fixeddate){
                continue;
            }
            #requires date.timezone in php.ini to be set
            try{
                $fixed =  new \DateTime($item->fixeddate);
                $report = new \DateTime($item->reporteddate);
                $time += $fixed->getTimestamp() - $report->getTimestamp();
            }catch(Exception $e){
                echo $e;
                continue;
            }
        }
        #Takes name from the first one
        $eval_incidents->host = $xml->children()[0]->host;
        $eval_incidents->percent_fixed = $eval_incidents->fixed / $eval_incidents->total;
        $eval_incidents->average_time = $time / $eval_incidents->total;
        return $eval_incidents;
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

    #Will be integrated in the report later on.
    private function get_all_domains(){
        /*
            Returns a list von EvalIncidents Objects, each for a different domain.

            TODO: get a list of all domains (ca. 180.000) / or all incidents (ca 230.000) OR iterate through all incidents§
        */
        #$xml = $this->get_response($this->base_url);
        #return json_encode($this->process_incidents($xml));

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
