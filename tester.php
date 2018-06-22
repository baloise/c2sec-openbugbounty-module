<?php
    require 'obb.php';
    $obb = new Obb\Obb();
    echo $obb->report($argv[1]);
?>
