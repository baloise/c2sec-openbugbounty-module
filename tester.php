<?php
    echo "Short test of obb starting\n";
    require 'obb.php';
    $obb = new Obb\Obb();
    
    echo "\nGetting report on: " . $argv[1] . "\n\n";
    echo $obb->report($argv[1]);

    echo "\n\nBest domain:\n\n";
    echo $obb->get_best_domain();

    echo "\n\nWorst domain:\n\n";
    echo $obb->get_worst_domain();

    echo "\n\nAverage time:\n\n";
    echo $obb->get_avg_time();
    echo "\n\n";
    
    echo "\n\nRanking of: " . $argv[1] . "\n\n";
    echo $obb->get_rank($argv[1]) . "\n\n";
    


?>
