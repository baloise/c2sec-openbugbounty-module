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
     * Database object
     */
    private $database_handler;


    /**
     * Rsyslog facility to save logs
     */
    private $syslog_facility = '0';


    /**
     * The constructor of an obb instance. 
     * All initial setup and configuration will happen here.
     *
     * @throws mysqli_sql_exception in case it could not connect to the database
     */
    public function __construct(){
        $config = parse_ini_file(CONFIG);
        $this->incident_index = $config["incident_index"];

        ini_set("display_errors",'0');

        #to prevent memory leak, (source unknown right now)
        ini_set("memory_limit", '500M');

        if(is_numeric($config["log_local_facility"]) and $config["log_local_facility"] >= 0 and $config["log_local_facility"] <= 7){
            $syslog_facility = $config["log_local_facility"];
        }

        if(openlog($ident=NAME,$options = LOG_PID,$facility=constant('LOG_LOCAL' . $syslog_facility))){
            echo "Using rsyslog faciltiy: local" . $syslog_facility . "\n";
        }else{
            echo "No logging\n";
        }

        if(NULL == $this->incident_index){
            syslog(LOG_NOTICE, "Incident index not found in obb.ini, setting to 48011. (First entry)");
            $this->incident_index = 48011;
        }

        $server = $config["db_server"];
        $user = $config["db_user"]; 
        $pass = $config["db_pass"];
        $db = $config["database"];

        
        $this->database_handler = new DatabaseHandler($server,$user,$pass,$db);       
    }


    public function __destruct(){
        syslog(LOG_NOTICE, "Exiting " . NAME . " now");
        closelog();
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
            handle_exception(new \InvalidArgumentException("No searchterm provided"));
        }

        $url = $this->base_url . $domain;
        $xml = $this->get_response($url);
        $domain_data = $this->process_incidents($xml);
        if($obj){
            return $domain_data;
        }
        $final_result = json_encode($domain_data);
        if(!$final_result){
            handle_exception(new EncodingException("Could not encode the result"));
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
            handle_exception(new XMLFormatException('host'));
        }

        $host = $xml->children()[0]->host;

        foreach($xml->children() as $item){
            $this->database_handler->write_database($item);
        }
        #TODO: change the format without first writing and reading from the database
        $domain_data = $this->database_handler->get_domain($host);
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
        
        $curl_options = array(CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => 1, CURLOPT_CONNECTTIMEOUT => 10);
        $counter = 0;
        $curl = curl_init();
        curl_setopt_array($curl,$curl_options);
        while(true){
            $res = curl_exec($curl);
            $status = curl_getinfo($curl);
            if(200 != $status["http_code"]){
                $counter++;
                if($counter >= CONNECTION_RETRIES){
                    handle_exception(new ConnectionException("Could not connect to openbugbounty.org: " . $status["http_code"]));
                    break;
                }
                sleep(10);
                syslog(LOG_WARNING,"Trying to connect ... status code: " . $status["http_code"] . "  " . $counter . "/" . CONNECTION_RETRIES);
            }else{
                curl_close($curl);
                break;
            }
        }
        if(0 == strlen($res)){
            handle_exception(new NoResultException("Empty response"));
        }
        $xml = simplexml_load_string($res);
        if(NULL == $xml || 0 == count($xml->children())){
            handle_exception(new NoResultException("Query " . $url . " gave no result"));
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
            handle_exception(new XMLFormatException("XML Node 'url' is missing"));
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

        $counter = $this->incident_index;
        $latest_id = $this->get_latest_reportID();
        $incidents = array();
        for(;$counter < $latest_id;$counter++){
            sleep(1);  #for safety
            $bulk_counter++;
            try{
                $res = $this->get_response($this->id_url . $counter);
            }catch (NoResultException $e){
                continue;
            }
            array_push($incidents,$res->children()[0]);
            if($bulk_counter >= BULK_SIZE){
                syslog(LOG_INFO,"Saving incidents :" . $counter . "/" . $latest_id);
                $this->database_handler->write_bulk($incidents);
                $this->update_incident_index($counter);
                $incidents = array();
                $bulk_counter = 0;
            }
        }
        $this->update_incident_index($latest_id);
        $this->check_unfixed_domains();
    }

    /**
     * Goes through all unresolved incidents, checks them and updates the database if necessary
     */
    public function check_unfixed_domains(){

        # update_file is not needed anymore, just query all incidents with fixeddate = NULL
        $unfixed_incidents = $this->database_handler->unfixed_incidents();
        foreach($unfixed_incidents as $incident){
            sleep(1);
            try{
                $res = $this->get_response($this->id_url . (int)$incident["id"]);
            }catch(\Exception $e){
                continue; 
            }
            if(1 == $res->children()[0]->fixed){
                $this->database_handler->write_database($res); 
            }
        }
    }

    /**
     * Returns a list von DomainData Objects, each for a different domain.
     * @param boolean $fetch if false only data from the database will be returned
     * @return DomainData[] List of all domains 
     */
    public function get_all_domains($fetch = true){

        $this->check_unfixed_domains();
        if($fetch){
            $this->fetch_domains();
        }
        $domain_list = $this->database_handler->load_domain_data();
        return $domain_list;
    }


    /**
     * Write to incident_index init file
     */
    private function update_incident_index($latest_id){
        $ini_handler = fopen(CONFIG,'r');
        $ini_handler_new = fopen(CONFIG . "~",'w+');
        if($ini_handler and $ini_handler_new){
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
     * @throws NoResultException if database is empty (database_handler->get_avg_time)
     * @return JSON time in seconds
     */
    public function get_avg_time(){
        syslog(LOG_INFO,"Querying average time");
        return json_encode(array("total_average_time"=>$this->database_handler->get_avg_time()));
    }

    /**
     * Returns the domain with the absolute minimum average response time.
     * @throws NoResultException if database is empty (database_handler->get_best)
     * @return JSON domain data
     */
    public function get_best_domain(){
        syslog(LOG_INFO,"Querying best domain");
        return json_encode($this->database_handler->get_best());
    }

    /**
     * Returns the domain with the absolute maximum response time. 
     * @throws NoResultException if database is empty (database_handler->get_worst)
     * @return JSON domain data
     */
    public function get_worst_domain(){
        syslog(LOG_INFO,"Querying worst domain");
        return json_encode($this->database_handler->get_worst());
    }
    
    /**
     * This will return the ranking the given domain in comparison to all others.
     * If the domain has no average time at all (no fixes ever) the result will be 0
     * @param string the domain name
     * @throws NoResultException if database is empty (database_handler->get_rank)
     * @return JSON (number between 0 and 1)
     */
    public function get_rank($domain){
        syslog(LOG_INFO,"Querying rank of " . $domain);
        return json_encode(array("rank"=>$this->database_handler->get_rank($domain)));
    }
}
?>
