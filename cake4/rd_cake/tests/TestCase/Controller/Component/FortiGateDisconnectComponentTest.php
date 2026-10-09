<?php
namespace App\Test\TestCase\Controller\Component;

use App\Controller\Component\FortiGateDisconnectComponent;
use Cake\Controller\ComponentRegistry;
use PHPUnit\Framework\TestCase;

class FakeFortiGateDisconnect extends FortiGateDisconnectComponent
{
    public $response = [0, 'Received Disconnect-ACK Id 1'];
    public $sent = [];
    protected function send(string $ip, int $port, string $secret, string $packet): array
    {
        $this->sent[] = [$ip, $port, $packet];
        return $this->response;
    }
}

class FortiGateDisconnectComponentTest extends TestCase
{
    private function fixtures(): array
    {
        return [new FakeFortiGateDisconnect(new ComponentRegistry()),
            (object)['secret'=>'test-secret', 'coa_port'=>3799],
            (object)['username'=>'admin', 'framedipaddress'=>'192.168.23.59',
                'nasipaddress'=>'10.10.10.1', 'acctsessionid'=>'old-session', 'acctstoptime'=>null]];
    }

    public function testAcknowledgmentUsesUsernameAndIpWithoutClosingAccounting(): void
    {
        [$sender, $nas, $session] = $this->fixtures();
        $result = $sender->disconnect($nas, $session);
        $this->assertTrue($result['acknowledged']);
        $this->assertStringContainsString('User-Name = "admin"', $sender->sent[0][2]);
        $this->assertStringContainsString('Framed-IP-Address = 192.168.23.59', $sender->sent[0][2]);
        $this->assertStringContainsString('Message-Authenticator', $sender->sent[0][2]);
        $this->assertStringNotContainsString('Acct-Session', $sender->sent[0][2]);
        $this->assertNull($session->acctstoptime);
        // A new login can retain the same IP and username; no ban or session cache exists here.
        $session->acctsessionid = 'new-session';
        $this->assertTrue($sender->disconnect($nas, $session)['acknowledged']);
    }

    /** @dataProvider rejectedResponses */
    public function testOnlyValidatedAckIsSuccessful(int $exitCode, string $output): void
    {
        [$sender, $nas, $session] = $this->fixtures();
        $sender->response = [$exitCode, $output];
        $result = $sender->disconnect($nas, $session);
        $this->assertFalse($result['acknowledged']);
        $this->assertSame('error', $result['type']);
        $this->assertStringNotContainsString($nas->secret, $result['message']);
    }

    public function rejectedResponses(): array
    {
        return [[1, 'No reply from server'], [0, 'Sent Disconnect-Request Id 1'],
            [0, 'Received Disconnect-NAK Id 1\nError-Cause = Session-Context-Not-Found'],
            [1, 'Received Disconnect-ACK Id 1\nInvalid Response Authenticator']];
    }

    public function testRejectsMalformedAttributesBeforeSending(): void
    {
        [$sender, $nas, $session] = $this->fixtures();
        $session->username = "admin\nUser-Name = other";
        $this->assertFalse($sender->disconnect($nas, $session)['acknowledged']);
        $this->assertSame([], $sender->sent);
        $session->username = 'admin';
        $session->framedipaddress = '';
        $this->assertFalse($sender->disconnect($nas, $session)['acknowledged']);
        $this->assertSame([], $sender->sent);
    }

    public function testQuotesUsernameAsOneRadiusAttribute(): void
    {
        [$sender, $nas, $session] = $this->fixtures();
        $session->username = 'name"\\value';
        $this->assertTrue($sender->disconnect($nas, $session)['acknowledged']);
        $this->assertStringContainsString('User-Name = "name\\"\\\\value"', $sender->sent[0][2]);
    }
}
