<?php
/**
 * OPENBUGBOUNTY C2SEC MODULE
 *
 */
declare(strict_type=1);

use PHPUnit\Framework\TestCase;

require_once 'obb.php';

final class ObbTest extends TestCase{

    public function setUp(){
        $this->obb = new Obb\Obb();
        sleep(1);
    }   

    public function test_report_success1(){

        $domain = "google.com";
        $report = (array)json_decode($this->obb->report($domain));

        $this->assertArrayHasKey('host',$report);
        $this->assertArrayHasKey('reports',$report);
        $this->assertArrayHasKey('total',$report);
        $this->assertArrayHasKey('fixed',$report);
        $this->assertArrayHasKey('time',$report);
        $this->assertArrayHasKey('average_time',$report);
        $this->assertArrayHasKey('percentage_fixed',$report);
        $this->assertArrayHasKey('types',$report);
    }

    public function test_report_success2(){

        $domain = "google.com";
        $domain_data = $this->obb->report($domain,true);

        $this->assertInstanceOf(Obb\DomainData::class,$domain_data);
    }

    public function test_report_failure(){
        $domain = "notadomain.abc.123.";

        $this->expectException(Obb\NoResultException::class);
        $report = $this->obb->report($domain);
    }
 

    public function tearDown(){

    }

}

?>
