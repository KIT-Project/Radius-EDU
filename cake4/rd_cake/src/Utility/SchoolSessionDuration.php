<?php
namespace App\Utility;

use InvalidArgumentException;

final class SchoolSessionDuration {
    public static function seconds($amount, $unit): int {
        $amount = filter_var($amount, FILTER_VALIDATE_INT);
        if (!in_array($unit, ['minutes', 'hours'], true) || $amount === false || $amount < 1) {
            throw new InvalidArgumentException('กรุณากรอกจำนวนเต็มและเลือกหน่วยนาทีหรือชั่วโมง');
        }
        $factor = $unit === 'hours' ? 3600 : 60;
        if ($amount > intdiv(604800, $factor)) {
            throw new InvalidArgumentException('ตั้งเวลาได้สูงสุด 7 วัน');
        }
        return $amount * $factor;
    }
}
