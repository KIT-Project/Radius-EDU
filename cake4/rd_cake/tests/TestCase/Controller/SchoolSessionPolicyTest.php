<?php
namespace App\Test\TestCase\Controller;

use App\Controller\ProfilesController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

class SchoolSessionProfilesController extends ProfilesController {
    public $Radgroupchecks;
    public $Radgroupreplies;
}

class SchoolSessionPolicyTest extends TestCase {
    private function controller(array $data): SchoolSessionProfilesController {
        $controller = (new ReflectionClass(SchoolSessionProfilesController::class))->newInstanceWithoutConstructor();
        $property = new ReflectionProperty(ProfilesController::class, 'reqData');
        $property->setAccessible(true);
        $property->setValue($controller, $data);
        return $controller;
    }

    private function invoke($controller, string $method, ...$args) {
        $reflection = new ReflectionMethod(ProfilesController::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($controller, ...$args);
    }

    private function table(array $rows = []) {
        return new class($rows) {
            public $deleted = [];
            public $saved = [];
            private $rows;
            public function __construct($rows) { $this->rows = $rows; }
            public function deleteAll($where) { $this->deleted[] = $where; }
            public function newEntity($data) { return (object)$data; }
            public function saveOrFail($entity) { $this->saved[] = $entity; return $entity; }
            public function find() { return $this; }
            public function where($where) { return $this; }
            public function all() { return $this->rows; }
        };
    }

    public function testSimplifiedSaveReplacesManagedLimitsWithSessionTimeoutOnly(): void {
        $controller = $this->controller(['school_session_policy' => '1', 'school_session_timeout' => '14400']);
        $controller->Radgroupchecks = $this->table();
        $controller->Radgroupreplies = $this->table();
        $this->assertTrue($this->invoke($controller, '_validateSchoolSessionPolicy'));
        $this->invoke($controller, '_doRadius', 'SimpleAdd_46');
        $this->assertSame([['groupname' => 'SimpleAdd_46']], $controller->Radgroupchecks->deleted);
        $this->assertSame([['groupname' => 'SimpleAdd_46']], $controller->Radgroupreplies->deleted);
        $this->assertCount(1, $controller->Radgroupreplies->saved);
        $reply = $controller->Radgroupreplies->saved[0];
        $this->assertSame('Session-Timeout', $reply->attribute);
        $this->assertSame('14400', $reply->value);
        $this->assertSame(':=', $reply->op);
    }

    public function testEditLoadsPreviouslySavedSessionDuration(): void {
        $controller = $this->controller([]);
        $controller->Radgroupchecks = $this->table();
        $controller->Radgroupreplies = $this->table([(object)['attribute' => 'Session-Timeout', 'value' => '7200']]);
        $data = $this->invoke($controller, '_getRadius', 'SimpleAdd_46');
        $this->assertSame(7200, $data['school_session_timeout']);
    }

    public function testProfileWithoutExplicitTimeoutDisplaysEightHours(): void {
        $controller = $this->controller([]);
        $controller->Radgroupchecks = $this->table();
        $controller->Radgroupreplies = $this->table();
        $data = $this->invoke($controller, '_getRadius', 'SimpleAdd_46');
        $this->assertSame(28800, $data['school_session_timeout']);
    }
}
