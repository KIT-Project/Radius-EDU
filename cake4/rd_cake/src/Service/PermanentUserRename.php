<?php
namespace App\Service;

use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use InvalidArgumentException;

class PermanentUserRename {
    // These records belong to the RADIUS identity, not administrator accounts.
    private const TABLES = ['radcheck', 'radreply', 'radusergroup', 'radacct', 'radacct_history',
        'radpostauth', 'user_stats', 'user_stats_dailies'];

    public function rename(Table $users, int $id, int $cloud, string $expected, string $name, $realmIds = false): array {
        if ($name !== trim($name) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._@+-]{1,63}$/D', $name)) {
            throw new InvalidArgumentException('ชื่อผู้ใช้ต้องยาว 2–64 ตัว ใช้ภาษาอังกฤษ ตัวเลข และ . _ @ + - โดยไม่มีช่องว่าง');
        }
        $db = $users->getConnection();
        return $db->transactional(function () use ($users, $db, $id, $cloud, $expected, $name, $realmIds) {
            $query = $users->find()->where(['id' => $id, 'cloud_id' => $cloud]);
            if ($realmIds) { $query->where(['realm_id IN' => $realmIds]); }
            $entity = $query->epilog('FOR UPDATE')->first();
            if (!$entity) { throw new InvalidArgumentException('ไม่พบผู้ใช้หรือไม่มีสิทธิ์แก้ไข'); }
            $old = $entity->username;
            if ($old !== $expected) { throw new InvalidArgumentException('ชื่อถูกเปลี่ยนแล้ว กรุณาโหลดรายการใหม่'); }
            if ($old === $name) { throw new InvalidArgumentException('กรุณาระบุชื่อใหม่ที่ต่างจากชื่อเดิม'); }
            // Reject names used by any RADIUS identity or historical records to avoid merging accounts.
            if ($users->find()->where(['username' => $name])->count()) {
                throw new InvalidArgumentException('ชื่อผู้ใช้นี้มีอยู่แล้ว');
            }
            foreach (self::TABLES as $table) {
                if ($db->execute("SELECT 1 FROM `$table` WHERE username = ? LIMIT 1", [$name])->fetch('assoc')) {
                    throw new InvalidArgumentException('ชื่อนี้มีข้อมูล RADIUS หรือประวัติเดิมอยู่แล้ว กรุณาใช้ชื่ออื่น');
                }
            }
            $devices = TableRegistry::getTableLocator()->get('Devices')->find()->where(['permanent_user_id' => $id])->all();
            $identities = [$old];
            foreach ($devices as $device) { $identities[] = $device->name; }
            $marks = implode(',', array_fill(0, count($identities), '?'));
            if ($db->execute("SELECT 1 FROM radacct WHERE username IN ($marks) AND acctstoptime IS NULL LIMIT 1", $identities)->fetch('assoc')) {
                throw new InvalidArgumentException('บัญชีหรืออุปกรณ์ยังออนไลน์ กรุณาตัดการเชื่อมต่อและรอ Accounting-Stop ก่อนเปลี่ยนชื่อ');
            }
            foreach (self::TABLES as $table) {
                $db->execute("UPDATE `$table` SET username = ? WHERE username = ?", [$name, $old]);
            }
            $db->execute("UPDATE radcheck SET value = ? WHERE attribute = 'Rd-Device-Owner' AND value = ?", [$name, $old]);
            // Only username is dirty: retain password, token, dates, caps, profile and realm.
            $entity->set('username', $name);
            $users->saveOrFail($entity);
            return ['id' => $id, 'old' => $old, 'new' => $name];
        });
    }
}
