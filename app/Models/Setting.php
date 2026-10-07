<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value', 'description'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    /**
     * Simpan satu setelan, dengan `updateOrCreate` dan bukan `update`.
     *
     * `update()` akan diam-diam gagal di baris yang belum ada -- dan gagal
     * diam-diam adalah cara paling mahal untuk menemukan setelan yang tidak
     * pernah tersimpan, karena seluruh pembacaan berikutnya jatuh ke nilai
     * bawaan tanpa ada yang melaporkannya.
     */
    public static function set(string $key, mixed $value, ?string $description = null): self
    {
        return static::query()->updateOrCreate(
            ['key' => $key],
            array_filter(
                ['value' => $value, 'description' => $description],
                fn (mixed $item): bool => $item !== null,
            ),
        );
    }

    /**
     * Hapus satu setelan supaya pembacaan jatuh ke nilai bawaan.
     *
     * Ini bukan `set($key, null)`. `set()` menyaring nilai `null` supaya
     * parameter `$description` yang tidak diisi tidak menimpa deskripsi yang
     * sudah ada, dan penyaringan itu berlaku untuk `$value` juga. Efeknya
     * memanggil `set($key, null)` pada setelan yang sudah ada tidak mengubah
     * apa pun -- jadi form yang memberi pilihan "kosongkan untuk memakai
     * bawaan" akan terlihat berhasil sementara nilainya masih yang lama.
     */
    public static function forget(string $key): void
    {
        static::query()->where('key', $key)->delete();
    }

    /**
     * Baca sekumpulan setelan sekaligus, hasilnya selalu punya semua kunci.
     *
     * `get()` mengembalikan nilai bawaan saat baris belum ada, dan pemanggil yang
     * memeriksa hasilnya satu per satu mudah lupa salah satu. Di sini bentuk
     * kembaliannya dijamin: setiap kunci yang diminta selalu ada, walau nilainya
     * bawaan.
     *
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public static function many(array $defaults): array
    {
        $stored = static::query()
            ->whereIn('key', array_keys($defaults))
            ->pluck('value', 'key')
            ->all();

        return array_replace($defaults, array_intersect_key($stored, $defaults));
    }
}
