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
     * Database object
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
 
        if(NULL == $this->incident_index){
            #TODO: implement logging
            echo "Incident index not found in obb.ini, setting to 0.";
            $this->incident_index = 0;
        }
        $this->to_update_file = "./.to_update_file";

        $server = $config["db_server"];
        $user = $config["db_user"]; 
        $pass = $config["db_pass"];
        $db = $config["database"];
        
        $this->database_handler = new DatabaseHandler($server,$user,$pass,$db);       
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
        $this->database_handler->write_database($domain_data);
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
        $latest_id = get_id($latest_reports->children()[0]->url);
        return $latest_id;
    }

    /**
     * Fetches all new incidents from openbugbounty
     * THIS MIGHT TAKE A LONG TIME AND/OR MAYBE OPENBUGBOUNTY WILL CLOSE THE CONNECTION DUE TO TOO MANY REQUESTS.
     */
    public function fetch_domains(){

        #keeping track of when we need to save the data to the drive
        $bulk_counter = 0;

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
        $this->update_incident_index($latest_id);
    }

    /**
     *  Goes through all unresolved incidents, checks them and updates the database if necessary
     */
    public function check_unfixed_domains(){

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
                $incident = $res->children()[0];
                $host = (string)$incident->host;
                if(1 == $incident->fixed){
                    try{
                        $newly_fixed = $this->database_handler->get_domain($host);
                        #if this incident is a duplicate, try to just add the fix 
                        #it can happen that incidents contained in .to_update are not saved in the database
                        #(if the execution is interrupted between writes
                        if(-1 == $newly_fixed->add($incident)){
                            $newly_fixed->add_fix($incident);
                        }
                    }catch(Exception $e){
                        #this entry does not exist yet. create new one.
                        $newly_fixed = new DomainData($host);
                        $newly_fixed->add($incident);
                    }
                    $this->database_handler->write_database($newly_fixed);
                }else{
                    fwrite($update_file_handle_new,$line);
                }
            }
            fclose($update_file_handle_new);
            fclose($update_file_handle);
            unlink($this->to_update_file);
            rename($this->to_update_file . "~", $this->to_update_file);
        }
    }

    /**
     * Returns a list von DomainData Objects, each for a different domain.
     * @param boolean $fetch if false only data from the database will be returned
     * @return DomainData[] List of all domains 
     */
    public function get_all_domains($fetch = true){

        $this->check_unfixed_domanis();
        if($fetch){
            $this->fetch_domains();
        }
        $domain_list = $this->database_handler->load_domain_data();
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
                    fwrite($ini_handler_new,"incident_index=".$latest_id."\n");
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
     * Returns the average time of all (recorded) incidents in seconds.
     * @return JSON time in seconds
     */
    public function get_avg_time(){
        return json_encode(array("total_average_time"=>$this->database_handler->get_avg_time()));
    }

    /**
     * Returns the domain with the absolute minimum average response time.
     * @return JSON domain data
     */
    public function get_best_domain(){
        return json_encode($this->database_handler->get_best());
    }

    /**
     * Returns the domain with the absolute maximum response time. 
     * @return JSON domain data
     */
    public function get_worst_domain(){
        return json_encode($this->database_handler->get_worst());
    }
    
    /**
     * This will return the ranking the given domain in comparison to all others.
     * If the domain has no average time at all (no fixes ever) the result will be 0
     * @param string the domain name
     * @return JSON (number between 0 and 1)
     */
    public function get_rank($domain){
        return json_encode(array("rank"=>$this->database_handler->get_rank($domain)));
    }
}
?>
