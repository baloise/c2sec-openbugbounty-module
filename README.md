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

obb also keeps track of which incidents might need update, namely the ones that are not fixed yet.
The ids of those are saved to `.to_update_file`. 

### Report

obb generates a short report about all incidents regarding a given domain.  
The data for the report is always fetched directly from openbugbounty.
The report includes:

* host name
* links to the reports on openbugbounty.org
* total number of incidents
* numer of fixed vulnerabilities
* total time
* average time it took to fix the vulnerabilities
* types and prevalence of vulnerabilities

### Metrics

obb can give you 
* a performance value for a given domain in regard of the response time (1=shortest response time,0=longest response time)
* the average response time for all domains
* the worst and best performing domains

The data for these metrics are coming from the database. In order to use them, you first have to populate your database.

### Database

obb saves domain data in its own format, so no individual incidents is recorded. Instead the accumulated data of one domain (DomainData) is stored.  
When populating the database, the process starts to iterate through all incident ids from openbugbounty. The starting index found in `obb.ini` as `incident_index`.  
Each incident is read and processed. After every 50 incidents the database will be updated.  
To keep track of unfixed incidents, obb writes them to a file `.to_update_file`. Everytime the database is updated / populated, those incidents will be checked again.

## Dependencies:

Written / Testet on:

* PHP 7.2.0

It should work on PHP 5.x aswell.  

* MySQL Server

Other dependencies:
* php-xml
* php-mysqli

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
{"host":"example.com","reports":["https:\/\/www.openbugbounty.org\/reports\/328896\/"],"total":1,"fixed":0,"time":22374879,"average_time":0,"percent_fixed":0,"types":{"XSS":1}}
```

### Database
`get_all_domains()` fetches the data from openbugbounty, updates the database and returns an array of all DomainData Object  
When running it initially (with incident_index equals 0) it will take a very long time (but the procedure can be discontinued and later renewed, since every 50 incidents are stored safely)  
```
$obb->get_all_domains();
```

Go get only  all currently stored data:
```
$obb->load_domain_data($fetch = false)
```

### Metrics

Total average time:
```
echo $obb->get_avg_time();
19399344.782198
```

Best / worst performing domain (shortest/longest response time):
```
$obb->get_min_time();
$obb->get_max_time();
```

Rank of a given domain (0 to 1):
```
echo $obb->get_rank("test.com");
0.564
```
