<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\PaperSize;
use App\Enums\PrintMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Simpan pengaturan cetak dokumen dari halaman Perangkat: ukuran kertas, cara
 * cetak, dan perilaku setelah commit bukti terima.
 *
 * Halaman ini menyimpan tiga nilai, jadi validasinya juga membatasi ketiganya.
 * Yang tetap dijaga adalah daftar nilai yang dikenal: `Rule::in()` dipakai,
 * bukan sekadar `required`, supaya request yang menyebut ukuran kertas atau cara
 * cetak yang tidak pernah ada ditolak di layar, bukan menunggu
 * `PaperSize::tryFrom()` mengembalikan null dan halaman diam-diam jatuh ke
 * bawaan.
 */
class SaveReceiptPrinterSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'paper' => ['required', Rule::in($this->paperValues())],
            'method' => ['required', Rule::in($this->methodValues())],
            'post_commit_mode' => ['nullable', Rule::in(['auto_print', 'preview', 'go_to_labels'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'paper.required' => 'Pilih ukuran kertas.',
            'paper.in' => 'Ukuran kertas tidak dikenal. Pilih struk 58 mm, struk 80 mm, atau A4.',
            'method.required' => 'Pilih cara cetak.',
            'method.in' => 'Cara cetak tidak dikenal. Pilih cetak lewat browser atau cetak thermal.',
            'post_commit_mode.in' => 'Pilihan perilaku setelah commit tidak valid.',
        ];
    }

    /**
     * Kertas yang dipilih.
     *
     * `tryFrom()` dan bukan `from()` supaya pemanggilnya tidak perlu melempar
     * `ValueError` untuk nilai yang lolos validasi. Kalau rules-nya nanti berubah
     * dan ada nilai yang lolos tanpa dikenal, hasilnya `null` dan pemanggil
     * memakai bawaan -- bukan 500.
     */
    public function paper(): PaperSize
    {
        return PaperSize::tryFrom((string) $this->validated('paper')) ?? PaperSize::Mm80;
    }

    /**
     * Cara cetak yang dipilih, dengan bawaan browser untuk nilai yang tidak dikenal.
     *
     * Dinamai `printMethod`, bukan `method`: `Request::method()` sudah dipakai
     * untuk kata kerja HTTP, dan membajaknya di sini akan membuat pemanggil
     * `$request->method()` -- di dalam maupun luar form -- mendapat enum, bukan
     * `GET`/`POST`. Perbedaan satu kata ini melindungi kelas request dari
     * menjadi magnet bug yang sulit dilacak.
     */
    public function printMethod(): PrintMethod
    {
        return PrintMethod::tryFrom((string) $this->validated('method')) ?? PrintMethod::Browser;
    }

    /**
     * @return list<string>
     */
    private function paperValues(): array
    {
        return array_map(
            static fn (PaperSize $paper): string => $paper->value,
            PaperSize::cases(),
        );
    }

    /**
     * @return list<string>
     */
    private function methodValues(): array
    {
        return array_map(
            static fn (PrintMethod $method): string => $method->value,
            PrintMethod::cases(),
        );
    }
}
