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
You can change this file in the configuration aswell.


### report

obb generates a short report about all incidents regarding a given domain.
The report includes:

* host name
* links to the reports on openbugbounty.org
* total number of incidents
* numer of fixed vulnerabilities
* total time
* average time it took to fix the vulnerabilities
* types and prevalence of vulnerabilities

### metrics

(Not implemented yet)
obb computes the average reponse time of all domains and compares them to a given domain.
(more to come)


## Dependencies:

Written / Testet on:

* PHP 7.2.0

It should work on PHP 5.x aswell.  

* MySQL Server

Other dependencies:
* php-xml
* php-mysqli

## Usage:

Get a report on a particular domain:
```
require 'obb.php';

$obb = new Obb\Obb();
echo $obb->report('example.com');
```
Result:
```
{"host":"example.com","reports":["https:\/\/www.openbugbounty.org\/reports\/328896\/"],"total":1,"fixed":0,"time":22374879,"average_time":0,"percent_fixed":0,"types":{"XSS":1}}
```
