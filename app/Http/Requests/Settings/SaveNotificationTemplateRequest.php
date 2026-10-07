<?php

namespace App\Http\Requests\Settings;

use App\Services\Notification\NotificationTemplate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Simpan isi template pesan dari halaman Pengaturan.
 *
 * Yang divalidasi bukan cuma "tidak kosong". Template yang lolos disimpan
 * ututannya, lalu dipakai ulang untuk setiap pesan berikutnya, jadi template
 * yang salah tidak berhenti di layar pengaturan: ia diam-diam mengubah isi
 * e-receipt yang dikirim ke penitip. Dua hal dijaga di sini.
 *
 * 1. Setiap variabel yang dikenal template harus ada. `{doc_no}` yang hilang
 *    tidak merusak tampilan -- placeholder-nya hilang begitu saja, dan
 *    penitip yang membaca e-receipt tidak punya cara tahu dokumen mana yang
 *    dimaksud. Tidak ada error yang muncul, dan tidak ada yang salah.
 *
 * 2. Tidak boleh ada variabel asing. `{harga}` yang diketik Staff tidak akan
 *    terisi dan tidak akan meninggalkan jejak. Pesan yang terlihat rapi tapi
 *    salah lebih berbahaya daripada pesan yang gagal terkirim, karena yang
 *    gagal minimal membuat operator turun tangan.
 */
class SaveNotificationTemplateRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'template' => ['required', Rule::in(NotificationTemplate::values())],
            'body' => ['required', 'string', 'max:4000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Isi template tidak boleh kosong.',
            'body.max' => 'Isi template maksimal 4000 karakter.',
            'template.in' => 'Jenis template tidak dikenal.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $template = $this->notificationTemplate();

            if ($template === null) {
                return;
            }

            $body = (string) $this->input('body');

            foreach ($this->missingVariables($template, $body) as $name) {
                $validator->errors()->add('body', sprintf(
                    'Variabel {%s} belum dipakai di template ini. Menghilangkannya membuat pesan kehilangan sebagian isi tanpa ada yang memberi tahu.',
                    $name,
                ));
            }

            foreach ($this->unknownVariables($template, $body) as $name) {
                $validator->errors()->add('body', sprintf(
                    'Variabel {%s} tidak dikenal oleh template ini. Yang tersedia: %s.',
                    $name,
                    $this->knownList($template),
                ));
            }
        });
    }

    public function notificationTemplate(): ?NotificationTemplate
    {
        $value = $this->input('template');

        return is_string($value) ? NotificationTemplate::tryFrom($value) : null;
    }

    public function templateName(): string
    {
        return (string) $this->validated('template');
    }

    public function body(): string
    {
        return (string) $this->validated('body');
    }

    /**
     * @return list<string>
     */
    private function missingVariables(NotificationTemplate $template, string $body): array
    {
        return array_values(array_filter(
            $template->srsParameters(),
            static fn (string $name): bool => ! str_contains($body, '{'.$name.'}'),
        ));
    }

    /**
     * Placeholder yang bukan milik template ini.
     *
     * Hanya `nama_variabel` yang diperiksa. Tulisan lain seperti `{1}` bukan
     * variabel template, dan membiarkannya lolos lebih baik daripada menolak
     * template yang sah karena format nomor lama ikut tersisa di isinya.
     *
     * @return list<string>
     */
    private function unknownVariables(NotificationTemplate $template, string $body): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $body, $matches);

        $found = $matches[1] ?? [];

        return array_values(array_unique(array_diff($found, $template->srsParameters())));
    }

    private function knownList(NotificationTemplate $template): string
    {
        return implode(', ', array_map(
            static fn (string $name): string => '{'.$name.'}',
            $template->srsParameters(),
        ));
    }
}
