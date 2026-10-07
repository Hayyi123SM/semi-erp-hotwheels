<?php

namespace App\Services\Import;

use App\Support\Enums;
use App\Support\ImportSchemas;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Berkas .xlsx siap isi untuk tiap modul impor.
 *
 * Tanpa ini, "petakan kolom" adalah pekerjaan tanpa acuan: orang membuka
 * Excel, membuat header dari ingatannya, lalu menebak dari halaman pemetaan
 * header mana yang cocok dengan field mana. Kalau tebakannya meleset di satu
 * huruf, seluruh kolom itu tidak terbaca.
 *
 * Berkas template menutup dua masalah sekaligus. Ia memberi nama header yang
 * persis sama dengan yang dikenali `HeaderMatcher`, jadi file dari template ini
 * terpetakan otomatis tanpa satu pun pilihan manual; dan ia menyatakan bentuk
 * datanya di sheet kedua, supaya orang tahu "Harga" mau diisi 175000 atau
 * Rp175.000 sebelum ketemu baris yang ditolak.
 *
 * Contoh isi sengaja memakai nilai yang jelas palsu ("Produk Contoh", email
 * contoh.test). Baris contoh yang terlihat meyakinkan lebih berbahaya --
 * orang lupa menghapus dan katalog ikut terisi barang fiktif.
 */
class ImportTemplateService
{
    /**
     * Contoh per kunci skema. Kunci yang tidak ada di sini tetap mendapat
     * contoh dari tipenya, jadi menambah field baru tidak membuat template
     * rusak.
     *
     * @var array<string, string>
     */
    private const EXAMPLES = [
        'name:p1' => 'Produk Contoh 1',
        'name:p2' => 'Wheel Klasik',
        'series_id' => 'Nama Seri yang sudah ada',
        'casting_code' => 'FX-0001',
        'year' => '2005',
        'color' => 'Merah',
        'packaging_type' => 'CARDED',
        'card_condition' => 'MINT',
        'blister_condition' => 'CLEAR',
        'factory_barcode_ref' => '8990000000001',
        'default_list_price' => '175000',
        'tags' => 'hotwheels;retro',
        'status' => 'ACTIVE',

        'wa_number' => '6281234567890',
        'address' => 'Alamat contoh',
        'agreement_date' => '2026-01-15',
        'scheme_type' => 'PERCENTAGE',
        'scheme_rate' => '25',
        'scheme_amount' => '0',
        'settlement_cycle' => 'MONTHLY',
        'min_payout' => '100000',
        'discount_policy' => 'STORE_BEARS',
        'loss_liability' => 'STORE',
        'bank_name' => 'BCA',
        'bank_account' => '0000000000',
        'bank_holder' => 'Nama Holders',

        'code' => 'A-S1-L1',
        'zone' => 'A',
        'type' => 'DISPLAY',
        'capacity' => '120',
        'is_active' => 'YA',

        'username' => 'user.contoh',
        'email' => 'contoh@contoh.test',
        'role' => 'STAFF',
        'pin' => '123456',
        'password' => 'password-contoh',
    ];

    /**
     * Contoh bawaan untuk kunci skema yang tidak punya contoh khusus.
     *
     * @var array<string, string>
     */
    private const BY_TYPE = [
        'enum' => 'ACTIVE',
        'boolean' => 'YA',
        'date' => '2026-01-15',
        'money' => '100000',
        'integer' => '100',
        'percentage' => '25',
        'tags' => 'nilai1;nilai2',
        'lookup' => 'Nama yang sudah ada',
        'string' => 'Teks',
    ];

    public function download(string $module): StreamedResponse
    {
        return response()->streamDownload(function () use ($module) {
            (new Xlsx($this->build($module)))->save('php://output');
        }, 'template-import-'.$module.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function build(string $module): Spreadsheet
    {
        $schema = ImportSchemas::for($module);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setTitle($schema['title']);

        $this->dataSheet($spreadsheet, $schema);
        $this->guideSheet($spreadsheet, $schema);

        return $spreadsheet;
    }

    /**
     * Sheet pertama: kolom siap isi.
     *
     * Baris kedua dan ketiga sengaja diisi contoh. Kolom yang benar-benar
     * kosong tentang cara mengisinya, dan orang akan menebak format yang
     * salah -- lalu benar-benar ditolak saat impor. Yang dijaga hanya
     * pengingat untuk menghapus keduanya, dan itu ditulis di sheet petunjuk.
     *
     * @param  array<string, mixed>  $schema
     */
    private function dataSheet(Spreadsheet $spreadsheet, array $schema): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data');

        $last = $this->lastColumn($schema);

        $head = $sheet->getStyle('A1:'.$last.'1');
        $head->getFont()->setBold(true);
        $head->getFill()->setFillType(Fill::FILL_SOLID);
        $head->getFill()->getStartColor()->setARGB('FFEFF6FF');
        $head->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        foreach ($schema['items'] as $index => $item) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($column.'1', $item['label']);
            $sheet->setCellValue($column.'2', $this->example($item));
        }

        $sheet->getColumnDimension('A')->setWidth(30);
    }

    /**
     * Sheet kedua: apa arti tiap kolom.
     *
     * Pengingat untuk menghapus baris contoh ada DI sini, bukan di sheet
     * Data. Catatan yang diletakkan di sheet Data ikut terbaca sebagai satu
     * baris data, dan berakhir jadi satu produk atau satu penitip bernama
     * "hapus baris ini" -- pengingatnya sendiri yang jadi barang yang
     * diimpor.
     *
     * @param  array<string, mixed>  $schema
     */
    private function guideSheet(Spreadsheet $spreadsheet, array $schema): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Petunjuk');

        $sheet->setCellValue('A1', 'Baris 2 pada sheet "Data" adalah contoh isi. Hapus baris itu sebelum mengunggah.');
        $sheet->getStyle('A1')->getFont()->setBold(true);

        $titles = ['Field Sistem', 'Wajib', 'Tipe', 'Nilai yang diperbolehkan', 'Contoh', 'Aturan'];
        $headLine = 3;

        foreach ($titles as $index => $title) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).$headLine, $title);
        }

        $last = Coordinate::stringFromColumnIndex(count($titles));

        $head = $sheet->getStyle('A'.$headLine.':'.$last.$headLine);
        $head->getFont()->setBold(true);
        $head->getFill()->setFillType(Fill::FILL_SOLID);
        $head->getFill()->getStartColor()->setARGB('FFEFF6FF');

        $row = $headLine + 1;
        foreach ($schema['items'] as $item) {
            $sheet->setCellValue('A'.$row, $item['label']);
            $sheet->setCellValue('B'.$row, ! empty($item['required']) ? 'YA' : 'tidak');
            $sheet->setCellValue('C'.$row, $this->typeLabel($item));
            $sheet->setCellValue('D'.$row, $this->allowed($item));
            $sheet->setCellValue('E'.$row, $this->example($item));
            $sheet->setCellValue('F'.$row, $this->rules($item));

            $row++;
        }

        $grid = $sheet->getStyle('A'.$headLine.':'.$last.($row - 1));
        $grid->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // Lebar kolom hanya relevan untuk sheet petunjuk: kolom "Nilai yang
        // diperbolehkan" memuat daftar enum yang panjang, dan tanpa lebar
        // eksplisit Excel memotongnya jadi satu huruf per baris.
        $widths = ['A' => 34, 'B' => 8, 'C' => 16, 'D' => 48, 'E' => 30, 'F' => 36];
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setAutoSize(false);
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function lastColumn(array $schema): string
    {
        return Coordinate::stringFromColumnIndex(max(1, count($schema['items'])));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function example(array $item): string
    {
        $key = (string) $item['key'];

        if (isset(self::EXAMPLES[$key])) {
            return self::EXAMPLES[$key];
        }

        return self::BY_TYPE[$item['type']] ?? self::BY_TYPE['string'];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function typeLabel(array $item): string
    {
        return match ($item['type']) {
            'money' => 'Uang (Rp)',
            'integer' => 'Angka bulat',
            'percentage' => 'Persen',
            'boolean' => 'Ya / Tidak',
            'tags' => 'Daftar tag',
            'enum' => 'Pilihan tetap',
            'lookup' => 'Relasi',
            'date' => 'Tanggal',
            default => 'Teks',
        };
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function allowed(array $item): string
    {
        if ($item['type'] === 'enum') {
            return implode(' / ', Enums::values($item['enum']));
        }

        if ($item['type'] === 'boolean') {
            return 'YA / TIDAK';
        }

        if ($item['type'] === 'lookup') {
            return 'Nama yang sudah terdaftar di Master Data (huruf besar/kecil tidak berpengaruh)';
        }

        return match ($item['type']) {
            // Poin pemisah dan "Rp" tidak boleh ikut. `Numbers` memang sudah
            // membersihkannya, tapi jauh lebih murah tidak mengirimnya sama
            // sekali daripada mengirim lalu membersihkannya.
            'money' => 'Angka bulat tanpa titik atau koma, contoh: 175000',
            'integer' => 'Angka bulat tanpa titik pemisah',
            'percentage' => 'Angka bulat 0-100, tanpa tanda persen',
            'tags' => 'Pisahkan dengan titik koma (;), maksimal 10',
            'date' => 'Format YYYY-MM-DD',
            default => 'Teks bebas',
        };
    }

    /**
     * Aturan validasi field, ditulis apa adanya.
     *
     * Tidak semua aturan adalah string: skema penitip memuat objek
     * `WhatsappNumberFormat` dan `UniqueWhatsappNumber`. Nama kelasnya yang
     * ditampilkan -- persis yang dibaca pembaca kode, jadi tidak perlu
     * dipoles lebih jauh.
     *
     * @param  array<string, mixed>  $item
     */
    private function rules(array $item): string
    {
        $store = (string) ($item['store'] ?? $item['key']);

        $rules = array_map(
            fn (mixed $rule): string => is_object($rule) ? class_basename($rule) : (string) $rule,
            $item['rules'][$store] ?? []
        );

        return implode(', ', $rules) ?: '-';
    }
}
