# Openbugbounty Module 

## Overview:  

This PHP-module retrieves, processes and formats data from https://openbugbounty.org/api. 
It generates a short report about all incidents regarding a given domain.
The report includes:

* total number of incidents
* numer of fixed vulnerabilities
* average time it took to fix the vulnerabilities
* types and prevalence of vulnerabilities

Not implemented yet!
In near future it should provide additional methods and return all data in JSON. 

## Usage:

```
require 'obb.php';

$obb = new Obb();
$obb->report('mydomain.com');
```

