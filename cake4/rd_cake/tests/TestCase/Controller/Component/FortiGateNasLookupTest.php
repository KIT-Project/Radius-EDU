<?php
namespace App\Test\TestCase\Controller\Component;

use App\Controller\Component\KickerComponent;
use Cake\Controller\ComponentRegistry;
use Cake\Controller\Controller;
use Cake\Http\ServerRequest;
use Cake\ORM\Entity;
use PHPUnit\Framework\TestCase;

class LookupKicker extends KickerComponent
{
    public $DynamicClients;
    public $Nas;
    public $FortiGateDisconnect;
    public function initialize(array $config): void {}
}
class LookupQuery
{
    public $conditions = [];
    public $row;
    public function where($conditions) { $this->conditions[] = $conditions; return $this; }
    public function contain($contain) { return $this; }
    public function first() { return $this->row; }
}
class LookupTable
{
    public $query;
    public function __construct($query) { $this->query = $query; }
    public function find() { return $this->query; }
}
class FortiGateNasLookupTest extends TestCase
{
    public function testSharedSystemNasIsIncludedAndUsesFortiGateSender(): void
    {
        $controller = new Controller(new ServerRequest(['query'=>['cloud_id'=>23]]));
        $kicker = new LookupKicker(new ComponentRegistry($controller));
        $dynamic = new LookupQuery();
        $nas = new LookupQuery();
        $nas->row = new Entity(['id'=>1,'cloud_id'=>-1,'type'=>'FortiGate-COA','coa_port'=>3799]);
        $kicker->DynamicClients = new LookupTable($dynamic);
        $kicker->Nas = new LookupTable($nas);
        $kicker->FortiGateDisconnect = new class {
            public $called = false;
            public function disconnect($nas, $session) {
                $this->called = true;
                return ['acknowledged'=>true,'type'=>'info'];
            }
        };
        $session = new Entity(['radacctid'=>1,'nasipaddress'=>'10.10.10.1','nasidentifier'=>'FortiGate-400F']);
        $this->assertTrue($kicker->kick($session, '')['acknowledged']);
        $this->assertTrue($kicker->FortiGateDisconnect->called);
        $this->assertContains(['Nas.cloud_id IN'=>[23,-1]], $nas->conditions);
        $this->assertContains(['DynamicClients.cloud_id'=>23], $dynamic->conditions);
    }
}
