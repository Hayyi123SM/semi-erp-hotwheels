<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos;

use App\Enums\ShiftStatus;
use App\Http\Requests\Concerns\NormalizesNumbers;
use App\Http\Requests\Concerns\RequiresOwnerPin;
use App\Models\Shift;
use App\Models\User;
use App\Services\Auth\PinService;
use App\Services\Pos\PosSettings;
use App\Services\Pos\ShiftService;
use App\Support\Format;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Tutup shift kasir: berapa uang yang benar-benar ada di laci setelah penjualan.
 *
 * PIN Owner di sini KONDISIONAL, bukan `required`. Yang menentukan adalah selisih
 * antara uang yang dihitung dan uang yang seharusnya ada, jadi aturan kondisionalnya
 * harus tahu uang itu sendiri -- dan tidak boleh bergantung pada angka yang diketik
 * kasir di form yang sama. Kalau ambangnya dihitung dari `closing_cash` yang sedang
 * divalidasi, field yang belum terisi akan terbaca sebagai nol, dan nol selalu
 * berada di dalam ambang.
 *
 * Selisihnya dihitung dari `closing_cash` yang sudah dibaca apa adanya, dibanding
 * rekap yang dihitung ulang dari database. Angka di layar bukan sumber kebenaran:
 * kasir yang menutup shift di tab lain tidak boleh mengarang selisih dengan
 * mengetik angka yang membuatnya terlihat kecil.
 *
 * Yang diotorisasi di `authorize()` adalah shift mana yang boleh ditutup, bukan
 * siapa yang menutupnya -- kasir menutup shiftnya sendiri, bukan shift orang lain.
 * Pin Owner tetap urusan integritas angka, dan itu urusan validasi.
 */
class CloseShiftRequest extends FormRequest
{
    use NormalizesNumbers;
    use RequiresOwnerPin;

    /**
     * Shift milik sendiri boleh ditutup; shift orang lain milik laporan.
     *
     * Owner boleh menutup shift mana pun, karena closing shift oleh kasir yang
     * sedang tugas adalah hal yang wajar saat kasirnya hilang di tengah shift dan
     * tidak pernah datang untuk menutup shiftnya.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $shift = $this->shift();

        if ($user === null || $shift === null) {
            return false;
        }

        return $user->isOwner() || $shift->user_id === $user->getKey();
    }

    /**
     * Nama aksi yang dicantumkan di dalam token.
     *
     * Dipisah dari void transaksi dan dari override skema komisi, supaya token
     * yang diberikan untuk satu aksi tidak bisa dipakai untuk menutup shift.
     */
    protected function ownerPinContext(): string
    {
        return 'pos.close-shift';
    }

    public function rules(): array
    {
        return [
            'closing_cash' => ['required', 'integer', 'min:0', 'max:'.PosSettings::MAX_AMOUNT],
            'notes' => ['nullable', 'string', 'max:500'],
            // Disebar, bukan ditulis sebagai `'pin_token' => ...` sendiri: saat
            // selisihnya masih dalam ambang, daftar ini kosong dan field-nya tidak
            // diperiksa sama sekali -- bukan sekadar opsional.
            ...$this->ownerPinRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'closing_cash.required' => 'Hitung uang di laci dulu sebelum menutup shift.',
            'closing_cash.integer' => 'Uang penutup harus angka bulat rupiah.',
            'closing_cash.min' => 'Uang penutup tidak bisa negatif.',
            'closing_cash.max' => 'Uang penutup terlalu besar untuk dicatat.',
            'notes.max' => 'Catatan maksimal 500 karakter.',
        ];

        if ($this->differenceNeedsApproval()) {
            $messages['pin_token.required'] = $this->differenceNotice();
        }

        return $messages;
    }

    /**
     * @return array<string, 'integer'>
     */
    protected function normalizableNumbers(): array
    {
        return ['closing_cash' => 'integer'];
    }

    /**
     * Shift yang sedang ditutup, dari route model binding.
     *
     * Diambil dari route, bukan dicari ulang, supaya yang divalidasi adalah baris
     * yang sama persis dengan yang ditutup controller.
     */
    public function shift(): ?Shift
    {
        $shift = $this->route('shift');

        return $shift instanceof Shift ? $shift : null;
    }

    public function closingCash(): int
    {
        return (int) $this->validated('closing_cash');
    }

    public function notes(): ?string
    {
        $notes = $this->validated('notes');

        return is_string($notes) && trim($notes) !== '' ? trim($notes) : null;
    }

    /**
     * Apakah selisih kas pada request ini perlu persetujuan Owner.
     *
     * Dijawab dari dua sumber: angka yang diketik kasir, dan rekap yang dihitung
     * dari database. Kalau salah satunya tidak bisa dibaca -- form tengah terisi,
     * atau shift sudah tidak ada -- jawabannya `true`. Meminta PIN yang tidak
     * perlu jauh lebih mudah daripada menutup shift tanpa persetujuan yang
     * seharusnya ada.
     */
    public function differenceNeedsApproval(): bool
    {
        $shift = $this->shift();
        $closingCash = $this->rawClosingCash();

        if (! $shift instanceof Shift || $closingCash === null) {
            return true;
        }

        // Shift yang sudah ditutup tidak punya laci yang perlu dihitung lagi:
        // uangnya sudah tercatat pada `closing_cash` yang lama. Menagih PIN di
        // sini akan mengirim kasir ke dialog PIN untuk angka yang memang tidak
        // akan pernah disimpan -- lalu `ShiftService::close()` yang menjawab
        // dengan kalimat yang sebenarnya benar: shift ini sudah tutup.
        if ($shift->status === ShiftStatus::Closed) {
            return false;
        }

        $service = app(ShiftService::class);

        return $service->differenceNeedsApproval($closingCash - $service->expectedCash($shift));
    }

    /**
     * Owner yang mengesahkan selisih di luar ambang, atau `null`.
     *
     * `null` berarti tidak ada yang perlu diotorisasi: selisihnya masih dalam
     * ambang, dan pemanggilnya berwenang atas aksinya sendiri.
     *
     * Owner yang menutup shiftnya sendiri tercatat atas namanya sendiri, walau
     * dia tidak perlu mengetik PIN apa pun. Kalau baris ini dibiarkan kosong,
     * maka setiap selisih yang ditutup Owner -- termasuk yang paling besar --
     * tidak akan punya siapa pun yang menandatanganinya.
     */
    public function cashDiffApprover(): ?User
    {
        if (! $this->differenceNeedsApproval()) {
            return null;
        }

        $user = $this->user();

        if ($user === null) {
            return null;
        }

        if ($user->isOwner()) {
            return $user;
        }

        return app(PinService::class)->approverFor(
            $user,
            $this->input($this->ownerPinField()),
            $this->ownerPinContext(),
        );
    }

    /**
     * PIN Owner di sini bukan soal hak akses, tapi soal integritas data.
     *
     * Owner tidak perlu mengotorisasi dirinya sendiri -- memaksanya mengetik PIN
     * menambah langkah tanpa menambah keamanan. Yang menentukan adalah
     * selisihnya: dia boleh menutup shift dengan selisih berapa pun, kasir tidak
     * boleh.
     */
    protected function requiresOwnerPin(): bool
    {
        return $this->differenceNeedsApproval() && ! $this->user()?->isOwner();
    }

    /**
     * Uang penutup seperti yang diketik, atau `null` kalau belum bisa dibaca.
     *
     * Dibaca dari input mentah, bukan dari `validated()`: `rules()` disusun
     * SEBELUM validasi selesai, jadi membaca `validated()` di sini selalu
     * mengembalikan kosong dan PIN-nya tidak akan pernah diminta.
     */
    private function rawClosingCash(): ?int
    {
        $raw = $this->input('closing_cash');

        if (! is_numeric($raw)) {
            return null;
        }

        return (int) round((float) $raw);
    }

    /**
     * Kalimat yang menjelaskan kenapa PIN Owner dibutuhkan, lengkap dengan angkanya.
     */
    private function differenceNotice(): string
    {
        $shift = $this->shift();

        if (! $shift instanceof Shift) {
            return 'Minta PIN Owner untuk menutup shift ini.';
        }

        $service = app(ShiftService::class);
        $expected = $service->expectedCash($shift);
        $closingCash = $this->rawClosingCash();

        if ($closingCash === null) {
            return sprintf(
                'Minta PIN Owner untuk menutup shift ini. Seharusnya ada %s di laci.',
                Format::rupiah($expected),
            );
        }

        return sprintf(
            'Selisih %s: ada %s di laci, seharusnya %s. Minta PIN Owner untuk melanjutkan.',
            Format::rupiah(abs($closingCash - $expected)),
            Format::rupiah($closingCash),
            Format::rupiah($expected),
        );
    }
}
