<?php
namespace App\Controller\Component;

use Cake\Controller\Component;

/** Disconnect firewall authentication without disabling the account or blocking its IP. */
class FortiGateDisconnectComponent extends Component
{
    public function disconnect($nas, $session): array
    {
        $username = (string)$session->username;
        $clientIp = (string)$session->framedipaddress;
        $serverIp = (string)$session->nasipaddress;
        $secret = (string)$nas->secret;
        $port = (int)($nas->coa_port ?: 3799);
        if (!$username || preg_match('/[\r\n\x00]/', $username) || strlen($username) > 253 ||
            !filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ||
            !filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ||
            !$secret || preg_match('/[\r\n\x00]/', $secret) || $port < 1 || $port > 65535) {
            return $this->result(false, 'ข้อมูลผู้ใช้ IP หรือ shared secret สำหรับตัด session ไม่ครบ');
        }
        // FortiGate captive portal identifies the session with User-Name + Framed-IP-Address.
        $quotedUsername = str_replace(['\\', '"'], ['\\\\', '\\"'], $username);
        $packet = "User-Name = \"$quotedUsername\"\nFramed-IP-Address = $clientIp\n" .
            'Event-Timestamp = ' . time() . "\nMessage-Authenticator = 0x00000000000000000000000000000000\n";
        try {
            [$exitCode, $output] = $this->send($serverIp, $port, $secret, $packet);
        } catch (\Throwable $error) {
            return $this->result(false, 'ไม่สามารถเรียก radclient เพื่อส่งคำสั่งตัด session ได้');
        }
        // radclient verifies the response authenticator using the shared secret.
        if ($exitCode === 0 && preg_match('/Received\s+Disconnect-ACK\b/', $output)) {
            return $this->result(true, 'FortiGate ยืนยันคำสั่งตัด session แล้ว กำลังรอ Accounting Stop');
        }
        if (preg_match('/Received\s+Disconnect-NAK\b/', $output)) {
            $cause = '';
            if (preg_match('/Error-Cause\s*=\s*([A-Za-z0-9_-]+)/', $output, $match)) {
                $cause = ' (' . $match[1] . ')';
            }
            return $this->result(false, 'FortiGate ปฏิเสธคำสั่งตัด session' . $cause);
        }
        return $this->result(false, 'ไม่ได้รับ Disconnect-ACK ที่ถูกต้อง ตรวจ CoA, UDP 3799 และ shared secret');
    }

    protected function send(string $ip, int $port, string $secret, string $packet): array
    {
        // Keep the secret out of shell commands, process arguments, and API responses.
        $secretFile = tmpfile();
        if ($secretFile === false) throw new \RuntimeException('Cannot create secret file');
        $process = null;
        $pipes = [];
        try {
            $path = stream_get_meta_data($secretFile)['uri'];
            if (!chmod($path, 0600) || fwrite($secretFile, $secret . "\n") === false) {
                throw new \RuntimeException('Cannot write secret file');
            }
            fflush($secretFile);
            $command = ['radclient', '-x', '-r', '1', '-t', '3', '-S', $path, "$ip:$port", 'disconnect'];
            $process = proc_open($command, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['redirect',1]], $pipes);
            if (!is_resource($process)) throw new \RuntimeException('Cannot start radclient');
            fwrite($pipes[0], $packet);
            fclose($pipes[0]);
            unset($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            unset($pipes[1]);
            $exitCode = proc_close($process);
            $process = null;
            return [$exitCode, $output];
        } finally {
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            fclose($secretFile);
        }
    }

    private function result(bool $acknowledged, string $message): array
    {
        return ['title' => $acknowledged ? 'Disconnect acknowledged' : 'Disconnect failed',
            'message' => $message, 'type' => $acknowledged ? 'info' : 'error', 'acknowledged' => $acknowledged];
    }
}
