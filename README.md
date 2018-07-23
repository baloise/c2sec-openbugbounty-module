# Openbugbounty Module

## Overview:

This PHP-module retrieves, processes and formats data from https://openbugbounty.org/api.

## Description:

### Configuration

The default configuration file is `./obb.ini`. 
To change it, you must edit the following line in `functions.php`:
```
define('CONFIG','./obb.ini');
```
The configuration contains the `incident_index`, which keeps track of wich incidents are already saved in the database.  
To setup the database connection, change `db_server`,`db_user`,`db_pass` and `database`.     

obb uses rsyslog to write its logfiles. You can set the facility  with `log_local_facility`-entry in the configuration file.   

### Report

obb generates a short report about all incidents regarding a given domain.  
The data for the report is always fetched directly from openbugbounty.  
The report includes:  

**host:** Domain  
**reports:** A list of URLs, linking to the openbbugbounty reports  
**total:** Number of incidents  
**fixed:** Number of fixed incidents  
**time:** Total amount of time (in seconds) the domain had unfixed incidents.   
**average_time:** Total amount of time devided by number of incidents.   
**percentage_fixed:** Number of fixed incidents devided by number of incidents   
**types:** A list of the vulnerabilites in format of: {"XSS":4,"REDIRECT":2}


### Metrics

obb can give you 
* a ranking for a given domain in terms of response time (1=shortest response time,0=longest response time)
* the average response time for all domains
* the worst and best performing domains

Only a domain with no current vulnerabilites can be a candidate for 'best'. 
The total time-to-fix is summed up for each incident individually. (So if there are 10 incidents on one day it counts as 10 days)    
The data for these metrics are coming from the database. In order to use them, you first have to populate your database.


### Database

When populating the database, the process starts to iterate through all incident ids from openbugbounty.   
The starting index found in `obb.ini` as `incident_index`.  
Each incident is saved. After every 50 incidents the database will be updated.  
Everytime the database is updated / populated, the still unfixed  incidents will be checked again.  
Incidents with a wrong fixed date are ignored.

## Dependencies:

Written / Testet on: PHP 7.2.0  
(But it should work on PHP 5.x aswell.)  

* MySQL Server

Other dependencies:
* php-xml
* php-mysqli
* php-curl

For testing:
* [PHPUnit](https://phpunit.de/index.html)

## Usage:

### Setup
```
require 'obb.php';

$obb = new Obb\Obb();
```

### Report
Get a report on a particular domain:
```
$obb->report('example.com');
```
Result:
```
{"host":"example.com","reports":["https:\/\/www.openbugbounty.org\/reports\/328896\/"],"total":1,"fixed":0,"time":22374879,"average_time":0,"percentage_fixed":0,"types":{"XSS":1}}
```

To return an associative array instead of JSON use:
```
$report = $obb->report('example.com',$obj = true);
echo $report['average_time'];
0
```

The report will be saved to the database automatically.

### Database

To do more than just generating a report, you need to populate a database. To fetch the data from openbugbounty use: 
```
$obb->fetch_domains();
```
To update the database for unfixed incidents, use:
```
$obb->check_unfixed_domains();
```
To do both steps in one:
```
$obb->fetch_domains(update=true);
```

To get all domain information from the database in form of associative arrays, use:
```
$all_domains = $obb->get_all_domains();
echo $all_domains['google.com']['total'];
13
```
When running `fetch_domains` initially (with incident_index equals 0) it will take a very long time (but the procedure can be discontinued and later called again, since every 50 incidents are stored safely)  
For safety reasons only one request per seconds is send.  

Go get only  all currently stored data use:
```
$all_domains = $obb->get_all_domains($fetch = false)
```

To populate the database you can also run `populate_database.php`

### Metrics

All functions descripted here use only the database as a source. They do not fetch it from openbugbounty.

To retrieve the total average response time of all domains, use:
```
echo $obb->get_avg_time();
```
It returns the following string. The time is measured in seconds.
```
{"total_average_time":19399344.782198}
```

To get a report of the best-performing domain in regards to response time use:
```
$best_domain = $obb->get_best_domain(); 
```

For the report of the worst-performing domain:
```
$worst_domain = $obb->get_worst_domain();
```

Rank of a given domain:
```
echo $obb->get_rank("test.com");
{"rank":0.564}
```
The rank is measured as a number between 0 and 1 (0 = worst, 1 = best).
