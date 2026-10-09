<?php
namespace App\Test\TestCase\Controller;

use App\Utility\SchoolSessionDuration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SchoolSessionDurationTest extends TestCase {
    public function testMinuteAndHourConversions(): void {
        $this->assertSame(60, SchoolSessionDuration::seconds('1', 'minutes'));
        $this->assertSame(5400, SchoolSessionDuration::seconds('90', 'minutes'));
        $this->assertSame(28800, SchoolSessionDuration::seconds('8', 'hours'));
        $this->assertSame(604800, SchoolSessionDuration::seconds('168', 'hours'));
        $this->assertSame(604800, SchoolSessionDuration::seconds('10080', 'minutes'));
    }

    /** @dataProvider invalidDurations */
    public function testInvalidDurationsAreRejected($amount, $unit): void {
        $this->expectException(InvalidArgumentException::class);
        SchoolSessionDuration::seconds($amount, $unit);
    }

    public function invalidDurations(): array {
        return [[0, 'minutes'], [-1, 'hours'], ['1.5', 'hours'], ['invalid', 'minutes'],
            [169, 'hours'], [10081, 'minutes'], [8, 'seconds'], [null, 'hours']];
    }
}
