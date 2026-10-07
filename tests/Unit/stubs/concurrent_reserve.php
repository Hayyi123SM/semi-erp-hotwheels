<?php

declare(strict_types=1);
use App\Services\Inventory\SkuService;
use Illuminate\Contracts\Console\Kernel;

/*
 * Stub yang dijalankan sebagai proses terpisah untuk uji konkurensi.
 *
 * Dikendarai oleh SkuConcurrencyTest. Tugasnya sederhana: bootstrap aplikasi,
 * alokasikan satu SKU via SkuService asli, lalu simpan hasil ke file. Sebuah
 * baris "READY" dicetak tepat sebelum alokasi agar penguji dapat mengukur
 * dengan pasti kapan pemanggilan mulai memblok.
 *
 * Koneksi dipaksa ke MySQL dev karena phpunit.xml menurunkan DB_CONNECTION=sqlite
 * ke environment variable, yang lebih diutamakan Laravel daripada .env.
 */

foreach ([
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '8889',
    'DB_DATABASE' => 'semi_erp_hotwheels',
    'DB_USERNAME' => 'root',
    'DB_PASSWORD' => 'root',
    'DB_URL' => '',
] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $ownerCode, $outFile] = $argv;

fwrite(STDOUT, "READY\n");
fflush(STDOUT);

try {
    $sku = $app->make(SkuService::class)->reserve($ownerCode);
    file_put_contents($outFile, (string) $sku);
    fwrite(STDOUT, "DONE {$sku}\n");
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    fwrite(STDERR, $e->getTraceAsString()."\n");
    exit(1);
}
