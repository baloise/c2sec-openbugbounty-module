<?php
    require 'obb.php';
    $obb = new Obb\Obb();
    echo $obb->test_case($argv[1]);
?>
