<?php
/**
 * OPENBUGBOUNTY C2SEC MODULE
 *
 * (If the database is empty the tests will fail)
 */

use PHPUnit\Framework\TestCase;

require_once 'obb.php';

final class ObbTest extends TestCase{

    private $key_list = ['host','reports','total','fixed','time','average_time','percentage_fixed','types'];

    public function setUp(){
        $this->obb = new Obb\Obb();
        sleep(3);
    } 

    /**
     * Assert that all keys are set in a report/domain_data object
     */
    private function assert_domaindata_keys($array){

        foreach($this->key_list as $key){
            $this->assertArrayHasKey($key,$array);
        }
    }

    /**
     * Asserts that the values of a report/domain_data object are within valid range
     */
    private function assert_domaindata_values($array){
   
        foreach($this->key_list as $key){ 
            $this->assertNotNull($array[$key]);
        }
    
        $this->assertGreaterThanOrEqual(0.0,$array['percentage_fixed']);    
        $this->assertLessThanOrEqual(1.0,$array['percentage_fixed']);
        $this->assertGreaterThanOrEqual(-1.0,$array['average_time']);
        $this->assertGreaterThan(0.0,$array['time']);
        $this->assertGreaterThanOrEqual(0,$array['fixed']);
        $this->assertGreaterThanOrEqual(1,$array['total']);
    }

    public function test_report_success1(){

        $domain = "google.com";
        $report = (array)json_decode($this->obb->report($domain));
        $this->assert_domaindata_keys($report);
        $this->assert_domaindata_values($report);
    }

    public function test_report_success2(){

        $domain = "google.com";
        $domain_data = $this->obb->report($domain,true);

        $this->assertInstanceOf(Obb\DomainData::class,$domain_data);
    }

    public function test_report_failure(){
        $domain = "notadomain.abc.123.";

        $this->expectException(Obb\NoResultException::class);
        $report = (array)json_decode($this->obb->report($domain));
    }

    public function test_avg(){
        
        $avg_time = (array)json_decode($this->obb->get_avg_time());
        
        $this->assertArrayHasKey('total_average_time',$avg_time); 
        $this->assertGreaterThanOrEqual(0.0,$avg_time['total_average_time']);
    }

    public function test_best_domain(){
    
        $best = (array)json_decode($this->obb->get_best_domain());
        $this->assert_domaindata_keys($best); 
        $this->assert_domaindata_values($best);
    }

    public function test_worst_domain(){
       
        $worst = (array)json_decode($this->obb->get_worst_domain());
        $this->assert_domaindata_keys($worst);    
        $this->assert_domaindata_values($worst);
    }

    public function test_rank_success(){
        $domain = "google.com";
        
        $rank = (array)json_decode($this->obb->get_rank($domain));
        $this->assertGreaterThanOrEqual(0.0,$rank['rank']); 
        $this->assertLessThanOrEqual(1.0,$rank['rank']);
    }

    public function test_rank_failure(){
        $domain = "abc.abc.123.";
        $this->expectException(Obb\NoResultException::class);
        $rank = $this->obb->get_rank($domain);
    }
}

?>
