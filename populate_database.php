<?php
/**
 * OPENBUGBOUNTY C2SEC MODULE
 *
 * This script populates the provided database (configured in 'obb.ini)
 *
 */
require 'obb.php';

$obb = new Obb\Obb();
$obb->get_all_domains();
?>
