# Openbugbounty Module

## Overview:

This PHP-module retrieves, processes and formats data from https://openbugbounty.org/api.
It generates a short report about all incidents regarding a given domain.
The report includes:

* total number of incidents
* numer of fixed vulnerabilities
* average time it took to fix the vulnerabilities
* types and prevalence of vulnerabilities


## Usage:

```
require 'obb.php';

$obb = new Obb\Obb();
echo $obb->report('mydomain.com');
```
Result:
```
{total":13,"fixed":1,"average_time":42297774,"percent_fixed":0.076923076923077,"types":{"REDIRECT":13}}
```
