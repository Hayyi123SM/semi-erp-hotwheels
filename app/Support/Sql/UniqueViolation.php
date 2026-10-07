<?php

declare(strict_types=1);

namespace App\Support\Sql;

use Illuminate\Database\QueryException;

/**
 * Penanda pelanggaran constraint UNIQUE, seragam lintas driver.
 *
 * Dipakai setiap kode yang merespons bentrok UNIQUE dengan mencoba ulang --
 * alokasi nomor urut, khususnya -- supaya aturan "error mana yang boleh diulang"
 * ditulis tepat satu kali. Dua salinan aturan ini akan berpisah pada driver
 * ketiga yang ditambahkan nanti, dan yang kalah selalu driver yang tidak
 * sempat diperbarui: nomor yang seharusnya di-retry jadi gagal keras, atau
 * sebaliknya, error foreign key ikut di-retry lalu berulang-ulang.
 *
 * SQLSTATE 23000 sengaja tidak dipakai sebagai penanda tunggal karena ia
 * juga mencakup pelanggaran foreign key dan NOT NULL, yang tidak boleh
 * di-retry.
 */
final class UniqueViolation
{
    /**
     * SQLSTATE resmi PostgreSQL untuk pelanggaran constraint unik.
     */
    private const string POSTGRES_UNIQUE_SQLSTATE = '23505';

    /**
     * MySQL: 1062 duplicated entry, 1555 no matching row saat UPDATE ... LIMIT.
     */
    private const array MYSQL_ERRNOS = [1062, 1555];

    /**
     * SQLite: errno 19 (constraint) -- pesannya yang membedakan UNIQUE dari
     * constraint lain, karena SQLite memakai satu nomor untuk semuanya.
     */
    private const int SQLITE_CONSTRAINT_ERRNO = 19;

    /**
     * Laravel 12 belum menyediakan QueryException::isUniqueConstraintError(),
     * jadi kombinasi SQLSTATE dan errno berikut dipakai sebagai penggantinya.
     */
    public static function is(QueryException $e): bool
    {
        if ((string) $e->getCode() === self::POSTGRES_UNIQUE_SQLSTATE) {
            return true;
        }

        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        if (in_array($driverCode, self::MYSQL_ERRNOS, true)) {
            return true;
        }

        return $driverCode === self::SQLITE_CONSTRAINT_ERRNO
            && str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
