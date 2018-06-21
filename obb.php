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
     avg_time: average time it took to fix the incidents (fixeddate - reporteddate)
     percent_fixed: fixed / total
     types  : associative array, (type => number)
    */
    public $total = 0;
    public $fixed = 0;
    public $avg_time = NULL;
    public $percent_fixed = 0.0;
    public $types = array();
}


class Obb {
    /*
     Mainclass of this module.
    */
    
    public function report($domain){
        /*
         Generates a report for given domain.
         Returns JSON.
         */
        #TODO Regex check for valid domain?
        if(NULL == $domain){
            echo "No searchterm provided";
            exit();
        }
        
        $url = 'https://www.openbugbounty.org/api/1/search/?domain=' . $domain;
        
        #TODO simply into one call
        $curl = curl_init();
        curl_setopt($curl,CURLOPT_URL, $url);
        curl_setopt($curl,CURLOPT_HEADER,1);
        curl_setopt($curl,CURLOPT_RETURNTRANSFER,1);
        $res = curl_exec($curl);
        $status = curl_getinfo($curl);
        if (200 != $status["http_code"]){
            echo $status["http_code"] . "\n";
            echo "Could not retrieve data";
            exit();
        }
        curl_close($curl);
        
        #TODO cut body out from res
        $xml_str = trim(split("\?>",$res)[1]);
        $xml = simplexml_load_string($xml_str);
        
        if(NULL == $xml){
            echo "The search gave no result";
            exit();
        }
        
        #output for now
        $eval_incidents = $this->process_incidents($xml);
        echo "TOTAL:  " . $eval_incidents->total . "\nFIXED: " .  $eval_incidents->fixed . "\n";
        echo "TYPES: \n";
        foreach(array_keys($eval_incidents->types) as $key){
            echo "\t" . $key . " : " . $eval_incidents->types[$key] . "\n";
        }
        echo "AVERAGE TIME  (days): " . $eval_incidents->avg_time . "\n";
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
                $time += $fixed->diff($report)->format("%d");
            }catch(Exception $e){
                echo $e;
                continue;
            }
        }
        #TODO cut to 2 decimals OR save time as days:minutes:seconds?
        $eval_incident->avg_time = $time / $eval_incident->total;
        return $eval_incident;
    }
}
?>
