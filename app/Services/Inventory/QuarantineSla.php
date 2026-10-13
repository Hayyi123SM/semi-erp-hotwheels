<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Setting;

/**
 * Ambang umur kasus karantina (BR-12 + FR-ST-04).
 *
 * Satu kasus di rak karantina menua: lewat satu ambang ia perlu review, lewat
 * ambang berikutnya ia berubah menjadi masalah Owner. Angkanya seharusnya bisa
 * diatur dari Parameter Sistem, jadi nilainya dibaca dari `Setting` dengan
 * bawaan 3 dan 7 hari saat barisnya belum pernah disimpan.
 *
 * Dibaca di satu tempat supaya badge antrean, kartu KPI, dan teks di layar
 * tidak bisa memakai ambang yang berbeda-beda.
 */
class QuarantineSla
{
    /**
     * Umur (hari) sebuah kasus mulai dianggap perlu review.
     */
    public const int DEFAULT_REVIEW_DAYS = 3;

    /**
     * Umur (hari) sebuah kasus mulai dieskalasi ke Owner.
     */
    public const int DEFAULT_ESCALATE_DAYS = 7;

    public const string REVIEW_SETTING_KEY = 'karantina.review_days';

    public const string ESCALATE_SETTING_KEY = 'karantina.escalate_days';

    public static function reviewDays(): int
    {
        return max(0, (int) Setting::get(self::REVIEW_SETTING_KEY, self::DEFAULT_REVIEW_DAYS));
    }

    public static function escalateDays(): int
    {
        return max(0, (int) Setting::get(self::ESCALATE_SETTING_KEY, self::DEFAULT_ESCALATE_DAYS));
    }
}
