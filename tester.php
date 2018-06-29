<?php
    echo "Short test of obb starting\n";
    require 'obb.php';
    $obb = new Obb\Obb();
    
    echo "\nGetting report on: " . $argv[1] . "\n\n";
    echo $obb->report($argv[1]);

    echo "\n\nPopulating/updating database\n\n";
    $obb->get_all_domains();

    echo "\n\nRanking of: " . $argv[1] . "\n\n";
    echo $obb->get_rank($argv[1]);
?>
