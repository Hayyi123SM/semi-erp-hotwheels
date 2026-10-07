<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pembuktian nyata pertahanan berlapis pada SkuService terhadap race
 * condition saat dua kasir commit bersamaan.
 *
 * Uji ini sengaja TIDAK memakai database :memory: bawaan test suite. SQLite
 * menjalankan lockForUpdate() sebagai no-op, sehingga tidak bisa membuktikan
 * penguncian. Yang diuji di sini adalah perilaku di MySQL asli:
 *
 *   1. Koneksi kedua terbukti MENANTI selama baris urut masih dikunci oleh
 *      transaksi pertama (prasyarat nomor tidak kembar).
 *   2. Dua proses nyata yang berjalan paralel tidak pernah mendapat SKU yang
 *      sama, dan urutannya tetap berurutan tanpa bolong.
 *
 * Secara bawaan uji ini dilewati agar suite tidak bergantung pada sebuah
 * MySQL eksternal. Untuk menjalankannya secara nyata:
 *
 *   SKIP_CONCURRENCY=0 vendor/bin/phpunit tests/Unit/SkuConcurrencyTest.php
 */
class SkuConcurrencyTest extends TestCase
{
    private const OWNER = 'TCON';

    private const CATEGORY = 'HW';

    private ?\PDO $mysql = null;

    /** @var array<string, list<mixed>> */
    private array $children = [];

    private function devMysql(): \PDO
    {
        if ($this->mysql !== null) {
            return $this->mysql;
        }

        try {
            $this->mysql = new \PDO(
                'mysql:host=127.0.0.1;port=8889;dbname=semi_erp_hotwheels',
                'root',
                'root',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
        } catch (\Throwable) {
            $this->mysql = null;
            $this->markTestSkipped('MySQL dev tidak terjangkau; uji konkurensi dilewati.');
        }

        return $this->mysql;
    }

    private function onlyWhenRequested(): void
    {
        if (! env('SKIP_CONCURRENCY', true)) {
            return;
        }

        $this->markTestSkipped(
            'Lewati secara bawaan. Jalankan SKIP_CONCURRENCY=0 dengan MySQL dev terhubung '
            .'untuk uji konkurensi yang sesungguhnya (lockForUpdate no-op di SQLite).'
        );
    }

    /**
     * Bersihkan jejak pemilik uji lalu pastikan baris urut sudah ada agar
     * SELECT ... FOR UPDATE mengunci baris yang nyata.
     */
    private function resetOwner(): void
    {
        $pdo = $this->devMysql();

        $pdo->exec('DELETE FROM sku_sequences WHERE owner_code = '.$pdo->quote(self::OWNER));

        $pdo->exec(sprintf(
            'INSERT INTO sku_sequences (owner_code, category_code, last_seq, created_at, updated_at) '
            .'VALUES (%s, %s, 0, NOW(), NOW()) ON DUPLICATE KEY UPDATE last_seq = 0',
            $pdo->quote(self::OWNER),
            $pdo->quote(self::CATEGORY),
        ));
    }

    /**
     * Luncurkan proses anak yang menjalankan SkuService asli pada MySQL dev.
     *
     * @return string path file hasil proses anak
     */
    private function child(): string
    {
        $out = tempnam(sys_get_temp_dir(), 'sku_');
        $stub = __DIR__.'/stubs/concurrent_reserve.php';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open([PHP_BINARY, $stub, self::OWNER, $out], $descriptors, $pipes);

        if (! is_resource($proc)) {
            $this->fail('Gagal meluncurkan proses anak.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        fclose($pipes[0]);

        $key = (string) $out;
        $this->children[$key] = [
            'proc' => $proc,
            'out' => $pipes[1],
            'err' => $pipes[2],
            'buffer' => '',
            'result' => null,
            'exit' => null,
        ];

        return $key;
    }

    private function stdout(string $key): string
    {
        $this->children[$key]['buffer'] .= (string) stream_get_contents($this->children[$key]['out']);

        return $this->children[$key]['buffer'];
    }

    private function stderr(string $key): string
    {
        $child = $this->children[$key];

        return (string) stream_get_contents($child['err']);
    }

    private function isRunning(string $key): bool
    {
        $status = proc_get_status($this->children[$key]['proc']);

        return $status['running'];
    }

    private function waitUntilReady(string $key): void
    {
        $deadline = microtime(true) + 30;
        $ranAtExit = false;

        while (microtime(true) < $deadline) {
            if (str_contains($this->stdout($key), 'READY')) {
                $ranAtExit = true;

                return;
            }

            if (! $this->isRunning($key)) {
                $ranAtExit = false;

                break;
            }

            usleep(20_000);
        }

        $status = proc_get_status($this->children[$key]['proc']);
        $this->fail(
            'Stub tidak siap. Sudah running sebelumnya: '.($ranAtExit ? 'ya' : 'tidak')
            .', running: '.var_export($status['running'], true)
            .', exitcode: '.var_export($status['exitcode'], true)
            .', termsig: '.var_export($status['termsig'], true)
            .' STDOUT: "'.$this->stdout($key).'" STDERR: "'.$this->stderr($key).'"'
        );
    }

    private function waitForExit(string $key): int
    {
        do {
            usleep(20_000);
        } while ($this->isRunning($key));

        $this->stdout($key);

        return proc_close($this->children[$key]['proc']);
    }

    private function readResult(string $key): string
    {
        $this->waitUntilReady($key);
        $this->waitForExit($key);

        // Nama file hasil = kunci anak.
        return trim((string) file_get_contents($key));
    }

    #[Test]
    public function a_second_connection_blocks_until_the_row_lock_is_released(): void
    {
        $this->onlyWhenRequested();
        $this->resetOwner();

        $pdo = $this->devMysql();
        $pdo->beginTransaction();
        $pdo->query(sprintf(
            'SELECT last_seq FROM sku_sequences WHERE owner_code = %s AND category_code = %s FOR UPDATE',
            $pdo->quote(self::OWNER),
            $pdo->quote(self::CATEGORY),
        ))->fetchAll();

        $key = $this->child();
        file_put_contents('/tmp/test_trace.log', "A1 child started\n", FILE_APPEND);
        $this->waitUntilReady($key);
        file_put_contents('/tmp/test_trace.log', 'A2 ready ok, buffer='.$this->children[$key]['buffer']."\n", FILE_APPEND);

        // Anak baru saja memasuki reserve(). Selama baris urut masih dikunci,
        // reserve harus menanti, bukan membaca nilai lama.
        usleep(1_000_000);

        $this->assertTrue(
            $this->isRunning($key),
            'Reserve di koneksi kedua harus menanti lock dari transaksi pertama. STDERR: '.$this->stderr($key)
        );

        $pdo->rollBack();
        file_put_contents('/tmp/test_trace.log', "A3 rollback done\n", FILE_APPEND);

        $this->assertSame('TCON-HW-001', $this->readResult($key));
    }

    #[Test]
    public function two_parallel_processes_never_receive_the_same_sku(): void
    {
        $this->onlyWhenRequested();
        $this->resetOwner();

        $a = $this->child();
        $b = $this->child();

        $skuA = $this->readResult($a);
        $skuB = $this->readResult($b);

        $skus = [$skuA, $skuB];
        sort($skus);

        $this->assertSame(
            ['TCON-HW-001', 'TCON-HW-002'],
            $skus,
            'Dua proses paralel harus mendapat nomor berbeda dan berurutan.',
        );

        $pdo = $this->devMysql();
        $lastSeq = (int) $pdo->query(sprintf(
            'SELECT last_seq FROM sku_sequences WHERE owner_code = %s AND category_code = %s',
            $pdo->quote(self::OWNER),
            $pdo->quote(self::CATEGORY),
        ))->fetchColumn();

        $this->assertSame(2, $lastSeq);
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $key => $child) {
            if (! is_resource($child['proc'])) {
                continue;
            }

            $status = proc_get_status($child['proc']);

            if ($status['running']) {
                proc_terminate($child['proc']);
            }

            proc_close($child['proc']);
        }

        parent::tearDown();
    }
}
