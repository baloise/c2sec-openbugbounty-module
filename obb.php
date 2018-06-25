<?php
/*
 OPENBUGBOUNTY  C2SEC MODULE

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

require_once 'functions.php';
require_once 'domain_data.php';

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


    private function get_all_domains(){
        /*
            Returns a list von DomainData Objects, each for a different domain.
            Iterating through all incidents
            THIS WILL TAKE A LONG TIME AND/OR MAYBE OPENBUGBOUNTY WILL CLOSE THE CONNECTION DUE TO TOO MANY REQUESTS.
        */
        $counter = 0;
        $domain_list = array();
        $latest_id = $this->get_latest_reportID();
        for(;$counter < $latest_id;$counter++){
            sleep(1);  #for safety
            $res = $this->get_response($this->id_url . $counter);
            #TODO: Find better way to deal with Error messages. 
            if(NULL != json_decode($res)){
                continue;
            }
            $host = (string)$res->children()[0]->host;
            if(NULL == $domain_list[$host]){
                $domain_list[$host] = new DomainData($host);
            }
            $domain_list[$host]->add($res->children()[0]);
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
        $result = $this->get_all_domains();
        $times = extract_attribute($result,'average_time');
        $total_time = array_sum($times); 
        return $total_time / sizeof($result);
    }

    public function get_total_min_time(){
        /*
            Returns the absolute minimum response time of all domain-owners in average, in seconds
        */
        $result = $this->get_all_domains();
        $times = extract_attribute($result,'average_time');
        #Avoiding 0 as minimum,  average_time will be 0 if no incidents were fixed.
        $times = array_map(function($o){if(0 == $o)return INF;return $o;},$times);
        return min($times);
    }

    public function get_total_max_time(){
        /*
            Returns the absolute maximum response time of all domain-owners in average, in seconds
        */
        $result = $this->get_all_domains();
        $times = extract_attribute($result,'average_time');
        return max($times);
    }
}
?>
