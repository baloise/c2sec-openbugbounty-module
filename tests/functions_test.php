<?php
/**
 * OPENBUGBOUNTY C2SEC MODULE
 *
 */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once 'functions.php';

final class FunctionsTest extends TestCase{

    public function test_get_id(){
    
        $valid_id = "583351";
        $valid_url = "https://www.openbugbounty.org/reports/583351/";

        $this->assertEquals($valid_id,Obb\get_id($valid_url));
    }

    public function test_get_id_exception(){
        $wrong_num_slashes_url = "https://www.openbugbounty.org/1/reports/583351";

        $this->expectException(Obb\FormatException::class);
        Obb\get_id($wrong_num_slashes_url);
    }

    public function test_get_id_exception2(){
        $no_url = "test text 123";

        $this->expectException(Obb\FormatException::class);
        Obb\get_id($no_url);
    }
}
?>
