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

function error($msg){
    /*
    Returns json encoded error message
    */
    return json_encode(array("Error"=>$msg));
}

class EvalIncidents {
    /*
     "Struct" for accumulated data for the domain

     total: total number of incidents
     fixed: number of incidents fixed
     average_time: average time it took to fix the incidents (fixeddate - reporteddate)
     percent_fixed: fixed / total
     types  : associative array, (type => number)
    */
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
    public $base_url = 'https://www.openbugbounty.org/api/1/search/?domain=';
    #XML childnodes that are relevant. array is used to check the response in case the API was changed.
    private $list_values = ['type','reporteddate','fixed','fixeddate','fixeddate'];

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
        $curl_options = array(CURLOPT_URL => $url,CURLOPT_RETURNTRANSFER => 1);
        $curl = curl_init();
        curl_setopt_array($curl,$curl_options);
        $res = curl_exec($curl);
        $status = curl_getinfo($curl);
        curl_close($curl);
        if(200 != $status["http_code"]){
            return error("Could not connect to openbugbounty.org: " . $status["http_code"]);
        }
        if(0 == strlen($res)){
            return error("Empty response");
        }
        $xml = simplexml_load_string($res);
        if(NULL == $xml || 0 == count($xml->children())){
            return error("The search gave no result");
        }
        $eval_incidents = $this->process_incidents($xml);
        $final_result = json_encode($eval_incidents);
        if(!$final_result){
            return error("Could not encode the result");
        }
        return $final_result;
    }

    private function process_incidents($xml){
        /*
         processes XML data from openbugbounty.
         */
        $eval_incident = new EvalIncidents();
        $time = 0;

        foreach($xml->children() as $item){

            #check format
            foreach($this->list_values as $entry){
                if(!isset($item->$entry)){
                    return error("XML Node " . $entry . " is missing.");
                }
            }

            $eval_incident->total += 1;

            if(1 == $item->fixed){
                $eval_incident->fixed += 1;
            }
            $eval_incident->types[(string)$item->type] += 1;
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
        $eval_incident->percent_fixed = $eval_incident->fixed / $eval_incident->total;
        $eval_incident->average_time = $time / $eval_incident->total;
        return $eval_incident;
    }
}
?>
