<?php
namespace App\Test\TestCase\Controller;
use App\Utility\PortalSessionProof;
use PHPUnit\Framework\TestCase;

class PortalSessionProofTest extends TestCase
{
    public function testProofKeepsUsernameAndAccountingWatermark(): void
    {
        $token = PortalSessionProof::issue('192.0.2.10', 'Teacher', 42, 'test-key', 1000);
        $proof = PortalSessionProof::verify($token, '192.0.2.10', 'test-key', 1001);
        $this->assertSame('Teacher', $proof['username']);
        $this->assertSame(42, $proof['after']);
    }

    public function testCannotUseProofFromAnotherIPOrSigningKey(): void
    {
        $token = PortalSessionProof::issue('192.0.2.10', 'Teacher', 42, 'test-key', 1000);
        $this->assertNull(PortalSessionProof::verify($token, '192.0.2.11', 'test-key', 1001));
        $this->assertNull(PortalSessionProof::verify($token, '192.0.2.10', 'other-key', 1001));
        $this->assertNull(PortalSessionProof::verify($token . 'tampered', '192.0.2.10', 'test-key', 1001));
    }

    public function testExpiredFutureAndMalformedProofsAreRejected(): void
    {
        $token = PortalSessionProof::issue('192.0.2.10', 'Teacher', 42, 'test-key', 1000);
        $this->assertNull(PortalSessionProof::verify($token, '192.0.2.10', 'test-key', 1181));
        $this->assertNull(PortalSessionProof::verify($token, '192.0.2.10', 'test-key', 999));
        $this->assertNull(PortalSessionProof::verify('bad', '192.0.2.10', 'test-key', 1001));
    }
}
