# Openbugbounty Module

## Overview:

This PHP-module retrieves, processes and formats data from https://openbugbounty.org/api.
It generates a short report about all incidents regarding a given domain.
The report includes:

* host name
* links to the reports on openbugbounty.org
* total number of incidents
* numer of fixed vulnerabilities
* total time
* average time it took to fix the vulnerabilities
* types and prevalence of vulnerabilities


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
Get the total average/max/min time from all domains:
```
echo $obb->get_total_average_time() / get_total_max_time() / get_total_min_time()
```
