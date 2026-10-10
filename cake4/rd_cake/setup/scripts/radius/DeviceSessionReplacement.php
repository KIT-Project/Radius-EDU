<?php
/** Per-user serialized oldest-first replacement, confirmed by CoA and Accounting Stop. */
class DeviceSessionReplacement
{
    private PDO $db;
    private $send;
    public function __construct(PDO $db, ?callable $send = null) {
        $this->db = $db;
        $this->send = $send ?? [$this, 'disconnect'];
    }
    private function rows(string $sql, array $params): array {
        $s = $this->db->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    private function active(string $username): array {
        return $this->rows("SELECT nasipaddress, MIN(framedipaddress) AS framedipaddress, MIN(nasidentifier) AS nasidentifier, MIN(acctstarttime) AS oldest, MIN(radacctid) AS first_id
            FROM radacct WHERE username=? AND acctstoptime IS NULL
            GROUP BY nasipaddress, COALESCE(CONCAT('ip:',NULLIF(NULLIF(framedipaddress,''),'0.0.0.0')),
                CONCAT('mac:',NULLIF(LOWER(REPLACE(REPLACE(callingstationid,':',''),'-','')),'')),CONCAT('session:',acctuniqueid))
            ORDER BY oldest ASC, first_id ASC", [$username]);
    }
    public function replace(string $username, string $ip, string $nas): void {
        if ($username === '' || strlen($username) > 253 || preg_match('/[\x00-\x1f]/', $username)) throw new RuntimeException('Invalid user');
        $lock = 'school-device:'.substr(hash('sha256', $username), 0, 48);
        $got = $this->rows('SELECT GET_LOCK(?, 2) AS locked', [$lock]);
        if (($got[0]['locked'] ?? 0) != 1) throw new RuntimeException('Busy');
        try {
            $users = $this->rows('SELECT session_limit,cloud_id FROM permanent_users WHERE username=?', [$username]);
            if (!$users) return; // Vouchers and other authentication backends have no per-user school limit.
            if (count($users) !== 1) throw new RuntimeException('Ambiguous user');
            $limit = (int)$users[0]['session_limit'];
            if ($limit <= 0) return;
            $deadline = microtime(true) + 9;
            while (true) {
                $devices = $this->active($username);
                // Only an explicit NAS/IP match proves reauthentication of the same device.
                $devices = array_values(array_filter($devices, fn($d) => !(
                    filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $ip !== '0.0.0.0' &&
                    $d['framedipaddress'] === $ip && $d['nasipaddress'] === $nas)));
                if (count($devices) < $limit) return;
                if (microtime(true) >= $deadline) throw new RuntimeException('Timed out');
                $old = $devices[0];
                if ($old['framedipaddress'] === '0.0.0.0' || !filter_var($old['framedipaddress'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new RuntimeException('Missing old IP');
                $settings = $this->rows("SELECT dc.type, (SELECT value FROM dynamic_client_settings WHERE dynamic_client_id=dc.id AND name='secret' LIMIT 1) AS secret, (SELECT value FROM dynamic_client_settings WHERE dynamic_client_id=dc.id AND name='coa_port' LIMIT 1) AS coa_port FROM dynamic_clients dc WHERE dc.nasidentifier=? AND dc.cloud_id=? AND dc.type='FortiGate-COA'",
                    [$old['nasidentifier'], $users[0]['cloud_id']]);
                if (!$settings) {
                    $settings = $this->rows('SELECT secret,coa_port,type FROM nas WHERE (nasname=? OR nasidentifier=?) AND cloud_id IN (?, -1)',
                        [$old['nasipaddress'], $old['nasidentifier'], $users[0]['cloud_id']]);
                }
                if (count($settings) !== 1 || $settings[0]['type'] !== 'FortiGate-COA') throw new RuntimeException('Invalid NAS');
                fwrite(STDERR, '[device-replacement] disconnect '.json_encode(['user'=>$username,'nas'=>$old['nasipaddress'],'old_ip'=>$old['framedipaddress']])."\n");
                if (!(($this->send)($username, $old, $settings[0]))) throw new RuntimeException('No Disconnect ACK');
                // Do not fabricate Accounting Stop. Wait briefly for the NAS to report it.
                do {
                    $remaining = $this->rows('SELECT radacctid FROM radacct WHERE username=? AND nasipaddress=? AND framedipaddress=? AND acctstoptime IS NULL LIMIT 1',
                        [$username, $old['nasipaddress'], $old['framedipaddress']]);
                    if (!$remaining) break;
                    usleep(100000);
                } while (microtime(true) < $deadline);
                if ($remaining) throw new RuntimeException('Missing Accounting Stop');
            }
        } finally {
            $this->rows('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
    public function disconnect(string $username, array $old, array $nas): bool {
        $ip = $old['nasipaddress']; $port = (int)($nas['coa_port'] ?: 3799);
        $secret = (string)$nas['secret'];
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $port < 1 || $port > 65535 ||
            $secret === '' || preg_match('/[\r\n\x00]/', $secret)) throw new RuntimeException('Invalid NAS');
        $file = tmpfile();
        if (!$file) throw new RuntimeException('Secret file unavailable');
        try {
            $path = stream_get_meta_data($file)['uri']; chmod($path, 0600); fwrite($file, $secret."\n"); fflush($file);
            $pipes = [];
            $proc = proc_open(['radclient','-x','-r','1','-t','2','-S',$path,"$ip:$port",'disconnect'],
                [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
            if (!is_resource($proc)) throw new RuntimeException('Transport unavailable');
            $user = str_replace(['\\','"'], ['\\\\','\\"'], $username);
            fwrite($pipes[0], "User-Name = \"$user\"\nFramed-IP-Address = ".$old['framedipaddress']."\nEvent-Timestamp = ".time()."\nMessage-Authenticator = 0x00000000000000000000000000000000\n");
            fclose($pipes[0]); $out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($proc);
            return $code === 0 && preg_match('/Received\s+Disconnect-ACK\b/', $out) === 1;
        } finally { fclose($file); }
    }
}
