<?php
/**
 * OPENBUGBOUNTY C2SEC MODULE
 *
 *
 */
namespace obb;


/**
 * Database handling class
 */
class DatabaseHandler{

    /**
     * Database connection.
     * Data of each incident will be stored
     * This data will only be used if information about ALL domains is requested. 
     * For a regular request, regarding one domain, the information will be retrieved from the API.
     */
    private $conn;

    /**
     * This query prepares the data of the incidents for the DomainData. 
     * Transforms each row for incidents into
     * id | host | report(url) | time (between fix and report or now and report) | fixed (true or false)
     */
    public $query_timediff = "SELECT  
                                id,
                                host,
                                report,
                                IF(fixeddate = '" . INVALID_DATE . "', 
                                    UNIX_TIMESTAMP(NOW()),
                                    UNIX_TIMESTAMP(fixeddate))
                                - UNIX_TIMESTAMP(reporteddate) AS time,
                                type,
                                IF (fixeddate = '" . INVALID_DATE . "',
                                    false,
                                    true) AS fixed
                                FROM incident";


    private $xml_nodes = ['url','host','type','reporteddate','fixeddate'];


    public function __construct($server,$user,$pass,$db){
    
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $this->conn = new \mysqli($server,$user,$pass,$db);

        $res = $this->conn->query("CREATE TABLE IF NOT EXISTS incident 
                                    (id INT,
                                    host VARCHAR(100),
                                    report LONGTEXT,
                                    reporteddate DATETIME, 
                                    fixeddate DATETIME,
                                    type TEXT,
                                    PRIMARY KEY(id))");
    }    

    /**
     * Loads all data from the database into an array of DomainData
     * @return DomainData[]
     */
    public function load_domain_data(){

        $domain_list = array();
        $res = $this->conn->query("SELECT * FROM (" . $query_timediff . ")incident_time");
        while(($row = $res->fetch_assoc())){
            $host = $row['host'];
            if(NULL == $domain_list[$host]){
                $domain_list[$host] = new DomainData($host);
            }
            $domain_list[$host]->add($row); 
        }
        foreach($domain_list as $domain_data){
            $domain_data->sumUp();
        }
        return $domain_list;
    }

    /**
     * Returns a DomainData Object from a given name
     * @param string domainame
     * @throws Exception if the name was not found
     * @throws Exception if the name was not provided
     * @return DomainData
     */
    public function get_domain($host){

        if(NULL == $host){
            throw new \Exception("domain is empty");
        }
        $res = array();
        $stmt = $this->conn->prepare("SELECT * FROM (" . $this->query_timediff . ")incident_time  WHERE host = ?");
        $stmt->bind_param("s",$host);
        if(!$stmt->execute()){
            throw new \Exception("No domain " . $host . " found");
        }
        $res = $stmt->get_result();
        $domain_data = new DomainData($host);
        while(($row = $res->fetch_assoc())){
            $domain_data->add($row);
        }
        $domain_data->sumUp();
        return $domain_data;
    }


    /**
     * Returns all incidents that are unfixed
     * @return array[] list of incidents
     */
    public function unfixed_incidents(){
        
        $res = $this->conn->query("SELECT id FROM incident WHERE fixeddate = '" . INVALID_DATE . "'");
        return $res->fetch_all(MYSQLI_ASSOC);
    }


    /**
     * Validates the XML input from the openbugbounty API
     * @param SimpleXMLElement 
     * @throws XMLFormatException
     */
    private function validate($incident){

        foreach($this->xml_nodes as $entry){
            if(!isset($incident->$entry)){
                throw new XMLFormatException("Node " . $entry . " is missing");
            }
        }
    }

    /**
     * Writes an incident to the database or updates it
     * If the fixeddate was set wrong in the report, the report is ignored
     * @param array the incident data 
     */
    public function write_database($incident){

        $this->validate($incident);

        $reporteddate = new \DateTime($incident->reporteddate);
        
        if(1 == $incident->fixed){
            $fixeddate = new \DateTime($incident->fixeddate);
            if($fixeddate < $reporteddate){
                echo "Fixeddate was set incorrectly";
                return;
            }
        }else{
            #Since comparing with NULL does not work in SQL IF Statement
            $fixeddate = new \DateTime(INVALID_DATE);
        }

        $stmt = $this->conn->prepare("REPLACE INTO incident (id,host,report,reporteddate,fixeddate,type) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("isssss",get_id($incident->url),
                                    $incident->host,
                                    $incident->url,
                                    $reporteddate->format('Y-m-d H:i:s'),
                                    $fixeddate->format('Y-m-d H:i:s'),
                                    $incident->type);
        $res = $stmt->execute();
        $stmt->close();
        if(!$res){
            echo "database write could not be performed";
        }
    }

    /**
     * Writes a bulk (of save_bulk_size) into the database
     * @param array[] a list of incidents
     */
    public function write_bulk($incident_list){

        foreach($incident_list as $incident){
                $this->write_database($incident);
            }
    }

    /**
     * Returns the total average response time of all domains
     * @throws Exception if the result-set is null
     * @return int time in seconds
     */
    public function get_avg_time(){

        $res = $this->conn->query("SELECT AVG(time) FROM (" . $this->query_timediff . ")incident_time");
        if(NULL == $res or 0 == $res->num_rows){
            throw new \Exception("Database is empty");
        }
        return $res->fetch_row()[0];
    }

    /**
     * Returns the domain with the minimum response time
     * @throws Exception if the result-set is null
     * @return DomainData
     */
    public function get_best(){

        $query = "SELECT AVG(time),host FROM (" . $this->query_timediff . ")incident_time GROUP BY host ORDER BY AVG(time)";
        $res = $this->conn->query($query);
        $date = INVALID_DATE;
        $stmt = $this->conn->prepare("SELECT id FROM incident WHERE host = ?  AND fixeddate = ?");
        while(($host = $res->fetch_row()[1])){
            $stmt->bind_param("ss",$host,$date);
            $stmt->execute();
            $res_check = $stmt->get_result();
            if(NULL == $res_check->fetch_row()){
                return $this->get_domain($host);
            }
        }
        throw new NoResultException("The database seems to be empty");
    }

    /**
     * Returns the domain with the maximum  response time
     * @throws Exception if the result-set is null
     * @return DomainData
     */
    public function get_worst(){

        $query = "SELECT AVG(time),host FROM (" . $this->query_timediff . ")incident_time GROUP BY host ORDER BY AVG(time) DESC LIMIT 1";
        $res = $this->conn->query($query);
        $host = $res->fetch_row()[1];
        return $this->get_domain($host);
    }

    /**
     * Returns the rank of a given domain (1 = best , 0 = worst)
     * @param string the domain name
     * @throws NoResultException if the result-set is null
     * @throws NoResultException if $domain is not provided
     * @return float a number between 0 and 1
     */
    public function get_rank($domain){
       
        if(NULL == $domain){
            throw new NoResultException("No searchterm provided");
        } 
        $total_number_domains = $this->conn->query("SELECT COUNT(DISTINCT host) FROM incident")->fetch_row()[0];
        if(0 == $total_number_domains or NULL == $total_number_domains){
            throw new NoResultException("The database seems to be empty");
        }
        $prepared_table = "SELECT AVG(time) AS avg_time ,host 
                            FROM (" . $this->query_timediff . ")incident_time 
                            GROUP BY host ORDER BY AVG(time)";

        $query = "SELECT COUNT(host) 
                  FROM (" . $prepared_table . ")prepared_table
                  WHERE avg_time > (SELECT avg_time FROM ( " . $prepared_table . ")prepared_table WHERE host = ?)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s",$domain);
        $stmt->execute();
        $res = $stmt->get_result();
        $number_worse_domains = $res->fetch_row()[0];
        return $number_worse_domains / $total_number_domains;
    }
}
?>
