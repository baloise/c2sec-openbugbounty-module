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

    public function __construct($server,$user,$pass,$db){
    
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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
                                    types TEXT,
                                    PRIMARY KEY(host))");
    }    

    /**
     * Loads all data from the database into an array of DomainData
     * @return DomainData[]
     */
    public function load_domain_data(){

        $domain_list = array();
        $res = $this->conn->query("SELECT * FROM domain_data");
        while(($row = $res->fetch_assoc())){
            $host = $row['host'];
            $domain_list[$host] = new DomainData($host);
            $domain_list[$host]->reports = (array)json_decode($row['reports']);
            $domain_list[$host]->total = $row['total'];
            $domain_list[$host]->fixed = $row['fixed'];
            $domain_list[$host]->time = $row['time'];
            $domain_list[$host]->average_time = $row['average_time'];
            $domain_list[$host]->percentage_fixed = $row['percentage_fixed'];
            $domain_list[$host]->types = (array)json_decode($row['types']);
            $domain_list[$host]->to_update = false;
        }
        return $domain_list;
    }

    /**
     * Write a DomainData object to the database or update it
     * @param DomainData 
     */
    public function write_database($domain_data){

        $stmt = $this->conn->prepare("REPLACE INTO domain_data (host,reports,total,fixed,time,average_time,percentage_fixed,types) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->bind_param("ssiiidds",$domain_data->host,
                                    json_encode($domain_data->reports),
                                    $domain_data->total,
                                    $domain_data->fixed,
                                    $domain_data->time,
                                    $domain_data->average_time,
                                    $domain_data->percentage_fixed,
                                    json_encode($domain_data->types));
        $res = $stmt->execute();
        $stmt->close();
        if(!$res){
            #log
            echo "database write could not be performed";
        }
    }

    /**
     * Writes a bulk (of save_bulk_size) into the database
     * @param DomainData[] 
     * @return DomainData[] the updated list
     */
    public function write_bulk($domain_list){
        foreach($domain_list as $domain_data){
            if($domain_data->to_update){
                $domain_data->sumUp();
                $this->write_database($domain_data);
            }
        }
        return $domain_list;
    }
}
?>
