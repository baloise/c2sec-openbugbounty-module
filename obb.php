<?php
/*
 * OPENBUGBOUNTY C2SEC MODULE
 *
 *
 */
namespace obb;

require_once 'functions.php';
require_once 'domain_data.php';

/**
 * Mainclass of this module.
 */
class Obb {

    #URL for bugbounty API. Returns all incidents for a specific domain
    private $base_url = 'https://www.openbugbounty.org/api/1/search/?domain=';

    #URL for bugbounty API. Returns an Incidents by id. 
    private $id_url = 'https://www.openbugbounty.org/api/1/id/?id=';


    /**
     * Keeping track of the incidents that we already know of.
     */
    private $incident_index;


    /**
     * Database connection.
     * Only DomainData Object are saved in the database.
     * This data will only be used if information about ALL domains is requested. 
     * For a regular request, regarding one domain, the information will be retrieved from the API.
     */
    private $conn;

    /**
     * path to file containing all incident ids that need checking (if the incident got fixed)
     */
    private $to_update_file;

    /**
     * The constructor of an obb instance. 
     * All initial setup and configuration will happen here.
     *
     * @throws mysqli_sql_exception in case it could not connect to the database
     */
    public function __construct(){
        $config = parse_ini_file(CONFIG);
        $this->incident_index = $config["incident_index"];
        $this->to_update_file = $config["to_update_file"];       
 
        if(NULL == $this->incident_index){
            #TODO: implement logging
            echo "Incident index not found in obb.ini, setting to 0.";
            $this->incident_index = 0;
        }
        if(NULL == $this->to_update_file){
            echo "path to to_update_file not set, setting default './.to_update_file'";
            $this->to_update_file = './.to_update_file';
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $server = $config["db_server"];
        $user = $config["db_user"]; 
        $pass = $config["db_pass"];
        $db = $config["database"];
        
        $this->conn = new \mysqli($server,$user,$pass,$db);

        /*
            create tables in first run
            For now I will simply encode all reports and types into a string, so there won't be a need for additional tables and queries.
            Later this might change, if there will be a need to query for a single vulnerablitity or similiar.
        */
        $res = $this->conn->query("CREATE TABLE IF NOT EXISTS domain_data 
                                    (host VARCHAR(50),
                                    reports LONGTEXT,
                                    total INT, 
                                    fixed INT, 
                                    time BIGINT, 
                                    average_time DOUBLE, 
                                    percentage_fixed FLOAT, 
                                    types TEXT)");
    }

    #testing
    public function test_case($input){
        return $this->report($input);
    }

    /**
     * Generates a report for given domain.
     * @param string $domain Name of the subject.
     * @param boolean $obj if true, return will be DomainData
     * @throws EncodingException if DomainData could not be encoded
     * @return string JSON-formatted DomainData
     */
    public function report($domain, $obj = false){

        $domain = htmlspecialchars($domain);

        #TODO Regex check for valid domain?
        if(NULL == $domain){
            return error("No searchterm provided");
        }

        $url = $this->base_url . $domain;
        $xml = $this->get_response($url);
        $domain_data = $this->process_incidents($xml);
        if($obj){
            return $domain_data;
        }
        $final_result = json_encode($domain_data);
        if(!$final_result){
            throw new EncodingException("Could not encode the result");
        }
        return $final_result;
    }


    /**
     * processes XML data from openbugbounty.
     * Input should be a list of incident reports regarding ONE domain.
     * @param SimpleXMLElement $xml Parsed XML Structure from the API
     * @throws XMLFormatException if the data cannot be processed / the API changed
     * @return DomainData
     */
    private function process_incidents($xml){

        if(!isset($xml->children()[0]->host)){
            throw new XMLFormatException('host');
        }
        $domain_data = new DomainData($xml->children()[0]->host);

        foreach($xml->children() as $item){
            $domain_data->add($item); 
        }
        $domain_data->sumUp();
        return $domain_data;
    }

    /**
     * curls an url, expects XML as result.
     * @param string $url URL to curl
     * @throws ConnectionException if response code is not equal 200.
     * @throws NoResultException if the response body is empty or if the query resulted in an empty XML-tag.
     * @return SimpleXMLElement  
     */
    private function get_response($url){
        
        $curl_options = array(CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => 1);
        $curl = curl_init();
        curl_setopt_array($curl,$curl_options);
        $res = curl_exec($curl);
        $status = curl_getinfo($curl);
        curl_close($curl);
        if(200 != $status["http_code"]){
            throw new ConnectionException("Could not connect to openbugbounty.org: " . $status["http_code"]);
        }
        if(0 == strlen($res)){
            throw new NoResultException("Empty response");
        }
        $xml = simplexml_load_string($res);
        if(NULL == $xml || 0 == count($xml->children())){
            throw new NoResultException("The search gave no result");
        }
        return $xml;
    }

    /**
     * Returns the latest report's ID registered on openbugbounty.org
     * @throws XMLFormatException if the data cannot be processed / the API changed
     * @throws FormatException if the URL is not accessible
     * @return int the ID of the latest report
     */ 
    private function get_latest_reportID(){

        $latest_reports = $this->get_response($this->base_url);

        if(NULL != json_decode($latest_reports)){
            return $latest_reports;
        } 
        if(!isset($latest_reports->children()[0]->url)){
            throw new XMLFormatException("XML Node 'url' is missing");
        }
        $latest_url = preg_split("/\//",$latest_reports->children()[0]->url);
        if(sizeof($latest_url) != URL_SPLIT_LENGTH){
            throw new FormatException("URL format seems to be false." . $latest_reports->children()[0]->url);
        }
        $latest_id = $latest_url[sizeof($latest_url)-2];
        if(!is_numeric($latest_id)){
            throw new FormatException("URL format seems to be false, ID is not a number" . $latest_id);
        }
        return $latest_id;
    }

    /**
     * Returns a list von DomainData Objects, each for a different domain.
     * Iterating through all incidents
     * THIS MIGHT TAKE A LONG TIME AND/OR MAYBE OPENBUGBOUNTY WILL CLOSE THE CONNECTION DUE TO TOO MANY REQUESTS.
     * @return DomainData[] List of all domains 
     */
    public function get_all_domains(){
    
        $domain_list = array();
        #load all domaindata object from database
        #check list of unfixed incident 
        #if the status changed update it, delete entry, update domaindata
        #set to update flag, so sumUp will be called

        #get all new ones
        $counter = $incident_index;
        $latest_id = $this->get_latest_reportID();
        for(;$counter < $latest_id;$counter++){
            sleep(1);  #for safety
            try{
                $res = $this->get_response($this->id_url . $counter);
            }catch (NoResultException $e){
                continue;
            }
            $host = (string)$res->children()[0]->host;
            if(NULL == $domain_list[$host]){
                $domain_list[$host] = new DomainData($host);
            }
            $domain_list[$host]->add($res->children()[0]);
        }
        #sum everyone up, with to_update flag
        foreach($domain_list as $domain_data){
            if($domain_data->to_update){
                $domain_data->sumUp();
            }
        }
        #save domain_list to drive
        return $domain_list;
    }


    /**
     * Returns the average time of all incidents (from the input array) in seconds.
     * @param DomainData[] $domain_list 
     * @return float time in seconds
     */
    public function get_total_average_time($domain_list){
        
        $times = extract_attribute($domain_list,'average_time');
        $total_time = array_sum($times); 
        return $total_time / sizeof($result);
    }

    /**
     * Returns the absolute minimum response time of all domain-owners (from the input list) in average, in seconds
     * @param DomainData[] $domain_list
     * @return int time in seconds
     */
    public function get_total_min_time($domain_list){
        
        $times = extract_attribute($doman_list,'average_time');
        #Avoiding 0 as minimum,  average_time will be 0 if no incidents were fixed.
        $times = array_map(function($o){if(0 == $o)return INF;return $o;},$times);
        return min($times);
    }

    /**
     * Returns the absolute maximum response time of all domain-owners (from the input list) in average, in seconds
     * @param DomainData[] $domain_list
     * @return int time in seconds
     */
    public function get_total_max_time($domain_list){
        
        $times = extract_attribute($domain_list,'average_time');
        return max($times);
    }
    
    /**
     * This will return the ranking the given domain  in comparison to the input list.
     * Returns 0 to 1. 1.0 = best response time, 0.0 = worst response time            
     */
    public function get_rank($domain,$domain_list){
        $res = $this->report($domain);
        $times = extract_attribute($domain_list,'average_time');
        sort($times);
        $pos = array_search($res->average_time,$times);
        return $pos / sizeof($times);
    }
}
?>
