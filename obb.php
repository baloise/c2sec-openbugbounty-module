<?php
/*
 * OPENBUGBOUNTY C2SEC MODULE
 *
 *
 */
namespace obb;

require_once 'functions.php';
require_once 'domain_data.php';
require_once 'database_handler.php';

/**
 * Mainclass of this module.
 */
class Obb {

    /**
     * URL for bugbounty API. Returns all incidents for a specific domain
     */
    private $base_url = 'https://www.openbugbounty.org/api/1/search/?domain=';

    /**
     * URL for bugbounty API. Returns an Incidents by id. 
     */
    private $id_url = 'https://www.openbugbounty.org/api/1/id/?id=';


    /**
     * Keeping track of the incidents that we already know of.
     */
    private $incident_index;


    /**
     * How many entries of newly read Datasets are written to the database at once.  
     */
    private $save_bulk_size = 50;


    /**
     * path to file containing all incident ids that need checking (if the incident got fixed)
     */
    private $to_update_file;

    /**
     * 
     */
    private $database_handler;

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

        $server = $config["db_server"];
        $user = $config["db_user"]; 
        $pass = $config["db_pass"];
        $db = $config["database"];
        
        $this->database_handler = new DatabaseHandler($server,$user,$pass,$db);       
 
    }

    #testing
    public function test_case($input){
        #return $this->report($input);
        return $this->get_rank($input,$this->get_all_domains()); 
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
    
        #load all domaindata object from database
        $domain_list = $this->database_handler->load_domain_data();
        #keeping track of when we need to save the data to the drive
        $bulk_counter = 0;
        
        #check list of unfixed incident 
        #if the status changed: update DomainData object, remove entry from list
        $update_file_handle = fopen($this->to_update_file,'r');
        $update_file_handle_new = fopen($this->to_update_file . "~",'w+');
        if($update_file_handle){
            while(($line = fgets($update_file_handle))){
                $id = (int)$line;
                try{
                    $res = $this->get_response($this->id_url . $id);
                }catch(NoResultException $e){
                    continue;
                }
                $host = (string)$res->children()[0]->host;
                #It is possible that we have marked some domains for update, even though we have not saved them
                #(when we terminate the run between bulk writes) 
                #so we ignore them just for now, they will be written back later
                if(NULL == $domain_list[$host]){
                    continue;
                }
                if(1 == $res->children()[0]->fixed){
                    $domain_list[$host]->add($res->children()[0]);
                }else{
                    fwrite($update_file_handle_new,$line);
                }
            }
            fclose($update_file_handle_new);
            fclose($update_file_handle);
            unlink($this->to_update_file);
            rename($this->to_update_file . "~", $this->to_update_file);
        }
        #get all new ones
        $update_file_handle = fopen($this->to_update_file,'a');
        $counter = $this->incident_index;
        $latest_id = $this->get_latest_reportID();
        for(;$counter < $latest_id;$counter++){
            sleep(1);  #for safety
            $bulk_counter++;
            #output for now
            echo $counter . "/" . $latest_id . "\n";
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
            #if an unfixed incident, write it to the list
            if(0 == $res->children()[0]->fixed){
               fwrite($update_file_handle,$counter . "\n"); 
            }
            if($bulk_counter >= $this->save_bulk_size){
                $domain_list = $this->database_handler->write_bulk($domain_list);
                $this->update_incident_index($counter);
                $bulk_counter = 0;
            }
        }
        fclose($update_file_handle);

        #sum everyone up, with to_update flag, and write to database
        $this->update_incident_index($latest_id);
        $domain_list = $this->database_handler->write_bulk($domain_list);
        return $domain_list;
    }


    /**
     * write to incident_index init file
     * unpleasent solution, may require redo or import of pear Config_Lite
     */
    private function update_incident_index($latest_id){
        $ini_handler = fopen(CONFIG,'r');
        $ini_handler_new = fopen(CONFIG . "~",'w+');
        if($ini_handler){
            while(($line = fgets($ini_handler))){
                if(substr($line,0,14) === "incident_index"){
                    fwrite($ini_handler_new,"incident_index=".$latest_id . "\n");
                }else{
                    fwrite($ini_handler_new,$line);
                }
            }
            fclose($ini_handler);
            fclose($ini_handler_new);
            unlink(CONFIG);
            rename(CONFIG . "~", CONFIG);
        }
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
        if(sizeof($times) == 0){
            throw new \InvalidArgumentException("domain_list is not the excepted type or is empty");
        }
        sort($times);
        $pos = array_search($res->average_time,$times);
        if(false == pos){
            #TODO: find better Exception
            throw new \Exception("domain_list does not contain domain");
        }
        return $pos / sizeof($times);
    }
}
?>
