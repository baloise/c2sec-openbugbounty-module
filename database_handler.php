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
     * Only DomainData Object are saved in the database.
     * This data will only be used if information about ALL domains is requested. 
     * For a regular request, regarding one domain, the information will be retrieved from the API.
     */
    private $conn;

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

    public function __construct($server,$user,$pass,$db){
    
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $this->conn = new \mysqli($server,$user,$pass,$db);

        /*
            create tables in first run
            For now I will simply encode all reports and types into a string, so there won't be a need for additional tables and queries.
            Later this might change, if there will be a need to query for a single vulnerablitity or similiar.
        */
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
     * Creates a DomainData Object from a result row (expect a full row/query!)
     * @param array the result row
     * @throw InvalidArgumentException if $row does not contain expected keys
     * @return DomainData
     */  
    private function construct_domain($row){
    
        if(!isset($row['host'])){
            throw new \InvalidArgumentException("input does not contain expected keys");
        }

        $host = $row['host'];
        $domain = new DomainData($host);
        $domain->reports = (array)json_decode($row['reports']);
        $domain->total = $row['total'];
        $domain->fixed = $row['fixed'];
        $domain->time = $row['time'];
        $domain->average_time = $row['average_time'];
        $domain->percentage_fixed = $row['percentage_fixed'];
        $domain->types = (array)json_decode($row['types']);
        $domain->to_update = false;
        return $domain;
    }

    /**
     * Write a DomainData object to the database or update it
     * @param DomainData 
     */
    public function write_database($incident){

        $reporteddate = new \DateTime($incident->reporteddate);
        
        if(1 == $incident->fixed){
            $fixeddate = new \DateTime($incident->fixeddate);
            if($incident->fixeddate < $incident->reporteddate){
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
     * @param DomainData[] 
     * @return DomainData[] the updated list
     */
    public function write_bulk($incident_list){
        foreach($incident_list as $incident){
                $this->write_database($incident);
            }
    }

    /**
     * Returns the total average response time of all domains
     * (With the waiting time for each incident seperately!)
     * @throws Exception if the result-set is null
     * @return int time in seconds
     */
    public function get_avg_time(){



        $res = $this->conn->query("SELECT AVG(time) FROM (" . $this->query_timediff . ") incident_time");
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

        $query = "SELECT AVG(time),host FROM (" . $this->query_timediff . ")host_time GROUP BY host ORDER BY AVG(time)";
        $res = $this->conn->query($query);
        while(($host = $res->fetch_row()[1])){
           if(NULL == $this->conn->query("SELECT * FROM incident WHERE host = '" . $host . "' AND fixeddate = '". INVALID_DATE . "'")->fetch_row()){
                return $this->get_domain($host);
           }
        }
    }

    /**
     * Returns the domain with the maximum  response time
     * @throws Exception if the result-set is null
     * @return DomainData
     */
    public function get_worst(){

        $query = "SELECT AVG(time),host FROM (" . $this->query_timediff . ")host_time GROUP BY host ORDER BY AVG(time) DESC LIMIT 1";
        $res = $this->conn->query($query);
        $host = $res->fetch_row()[1];
        return $this->get_domain($host);
    }

    /**
     * Returns the rank of a given domain (1 = best , 0 = worst)
     * @param string the domain name
     * @throws Exception if the result-set is null
     * @throws Exception if $domain is not provided
     * @return float a number between 0 and 1
     */
    public function get_rank($domain){
        if(NULL == $domain){
            throw new \Exception("domain is empty");
        }
        $res = $this->conn->query("SELECT host FROM domain_data WHERE average_time > 0 ORDER BY average_time");
        if(NULL == $res or 0 == $res->num_rows){
            throw new \Exception("Database is empty");
        }
        $i = 0;
        while(($row = $res->fetch_row())){
            if($row[0] == $domain){
                break;
            }
            $i++;
        }
        return 1-($i/$res->num_rows);
    }
}
?>
