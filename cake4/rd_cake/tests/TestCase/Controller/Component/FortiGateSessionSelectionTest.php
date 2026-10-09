<?php
namespace App\Test\TestCase\Controller\Component;

use App\Controller\RadacctsController;
use Cake\ORM\Entity;
use PHPUnit\Framework\TestCase;

class SessionSelectionController extends RadacctsController
{
    public $Radaccts;
    public $Kicker;
    public function initialize(): void {}
}

class SessionSelectionQuery
{
    public $latest;
    public function where($conditions) { return $this; }
    public function order($order) { return $this; }
    public function first() { return $this->latest; }
}

class FortiGateSessionSelectionTest extends TestCase
{
    private function session(int $id, $stopped = null): Entity
    {
        return new Entity(['radacctid'=>$id,'acctstoptime'=>$stopped,'username'=>'admin',
            'nasipaddress'=>'10.10.10.1','framedipaddress'=>'192.168.23.59']);
    }

    private function invoke(Entity $selected, Entity $latest): array
    {
        $controller = new SessionSelectionController();
        $query = new SessionSelectionQuery();
        $query->latest = $latest;
        $controller->Radaccts = new class($query) {
            private $query;
            public function __construct($query) { $this->query = $query; }
            public function find() { return $this->query; }
        };
        $controller->Kicker = new class {
            public $calls = 0;
            public function kick($session, $token) {
                $this->calls++;
                return ['title'=>'ACK','message'=>'ACK','type'=>'info','acknowledged'=>true];
            }
        };
        $method = new \ReflectionMethod(RadacctsController::class, 'sendSessionDisconnects');
        $method->setAccessible(true);
        $method->invoke($controller, [$selected], 'test-token');
        return [$controller->viewBuilder()->getVars(), $controller->Kicker->calls];
    }

    public function testOldAccountingRowCannotDisconnectNewLoginAtSameIp(): void
    {
        [$response, $calls] = $this->invoke($this->session(10), $this->session(11));
        $this->assertFalse($response['success']);
        $this->assertSame(0, $calls);
    }

    public function testAlreadyStoppedSessionDoesNotSendDisconnect(): void
    {
        [$response, $calls] = $this->invoke($this->session(10, '2026-10-09 10:00:00'), $this->session(11));
        $this->assertFalse($response['success']);
        $this->assertSame(0, $calls);
    }

    public function testLatestLoginAtSameIpIsSelectableAndAccountingStaysOpen(): void
    {
        $session = $this->session(11);
        [$response, $calls] = $this->invoke($session, $session);
        $this->assertTrue($response['success']);
        $this->assertSame(1, $calls);
        $this->assertNull($session->acctstoptime);
    }
}
