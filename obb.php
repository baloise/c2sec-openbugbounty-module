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

    public $base_url = 'https://www.openbugbounty.org/api/1/search/?domain=';


    public function report($domain){
        /*
         Generates a report for given domain.
         Returns JSON.
         */

        $domain = htmlspecialchars($domain);

        #TODO Regex check for valid domain?
        if(NULL == $domain){
            return "No searchterm provided";
        }
        
        $url = $this->base_url . $domain;

        $curl_options = array(CURLOPT_URL => $url,
                            CURLOPT_HEADER => 1,
                            CURLOPT_RETURNTRANSFER => 1);	 
        $curl = curl_init();
        curl_setopt_array($curl,$curl_options);
        $res = curl_exec($curl);
        $status = curl_getinfo($curl);
        curl_close($curl);
        if (200 != $status["http_code"]){
            return "response code: " .  $status["http_code"];
        }
        
        #TODO cut body out from res
        $xml_str = trim(preg_split("/\?>/",$res)[1]);
        $xml = simplexml_load_string($xml_str);
        
        if(NULL == $xml || 0 == count($xml->children())){
            return "The search gave no result";
        }
        
        #output for now
        $eval_incidents = $this->process_incidents($xml);
        echo "TOTAL:  " . $eval_incidents->total . "\nFIXED: " .  $eval_incidents->fixed . "\n";
        echo "TYPES: \n";
        foreach(array_keys($eval_incidents->types) as $key){
            echo "\t" . $key . " : " . $eval_incidents->types[$key] . "\n";
        }
        echo "AVERAGE TIME  (seconds): " . $eval_incidents->average_time . "\n";

        $final_result = json_encode($eval_incidents);
        if(!$final_result){
            return "Could not encode";
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
                $fixed =  new DateTime($item->fixeddate);
                $report = new DateTime($item->reporteddate);
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
