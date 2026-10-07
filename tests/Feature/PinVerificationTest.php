<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\PinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PinVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const string CONTEXT = 'consignment.scheme-override';

    // ===== Endpoint =====

    #[Test]
    public function guest_cannot_reach_the_pin_endpoint(): void
    {
        // JSON request, jadi 401 -- bukan redirect ke login seperti halaman.
        $this->postJson('/pin/verify', ['pin' => '123456'])->assertUnauthorized();
    }

    #[Test]
    public function staff_exchanges_the_owner_pin_for_a_token(): void
    {
        $owner = User::factory()->owner()->withPin('123456')->create();
        $staff = User::factory()->staff()->create();

        $response = $this->actingAs($staff)->postJson('/pin/verify', [
            'pin' => '123456',
            'context' => self::CONTEXT,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'PIN Owner diterima.')
            ->assertJsonPath('context', self::CONTEXT)
            ->assertJsonPath('owner', $owner->name)
            ->assertJsonStructure(['token', 'expires_at']);

        $this->assertNotSame('', $response->json('token'));
    }

    #[Test]
    public function owner_exchanges_their_own_pin_without_asking_anyone(): void
    {
        $owner = User::factory()->owner()->withPin('654321')->create();

        $this->actingAs($owner)
            ->postJson('/pin/verify', ['pin' => '654321', 'context' => self::CONTEXT])
            ->assertOk();
    }

    #[Test]
    public function a_wrong_pin_is_refused_without_saying_which_owners_pin_exists(): void
    {
        User::factory()->owner()->withPin('123456')->create();
        $staff = User::factory()->staff()->create();

        $response = $this->actingAs($staff)
            ->postJson('/pin/verify', ['pin' => '999999'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pin');

        $this->assertSame('PIN Owner salah.', $this->pinErrorFrom($response));
    }

    #[Test]
    public function a_pin_is_only_accepted_as_six_digits(): void
    {
        $staff = User::factory()->staff()->create();

        foreach (['12345', '1234567', '12a456', '', '123 456'] as $pin) {
            $this->actingAs($staff)
                ->postJson('/pin/verify', ['pin' => $pin])
                ->assertStatus(422)
                ->assertJsonValidationErrors('pin');
        }
    }

    #[Test]
    public function a_store_with_no_owner_pin_says_so_instead_of_refusing_silently(): void
    {
        User::factory()->owner()->create(['pin' => null]);
        $staff = User::factory()->staff()->create();

        $response = $this->actingAs($staff)
            ->postJson('/pin/verify', ['pin' => '123456'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pin');

        $this->assertStringContainsString(
            'Belum ada Owner yang mengatur PIN',
            $this->pinErrorFrom($response),
        );
    }

    #[Test]
    public function an_inactive_owner_pin_is_not_accepted(): void
    {
        User::factory()->owner()->inactive()->withPin('123456')->create();
        $staff = User::factory()->staff()->create();

        $response = $this->actingAs($staff)
            ->postJson('/pin/verify', ['pin' => '123456'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pin');

        $this->assertStringContainsString('Belum ada Owner', $this->pinErrorFrom($response));
    }

    #[Test]
    public function a_staff_pin_does_not_authorise(): void
    {
        // PIN staff ada dan hash-nya benar, tapi staff bukan Owner. Kalau
        // pemeriksaan hanya melihat "ada PIN yang cocok", PIN staff akan membuka aksi
        // yang namanya Owner.
        User::factory()->owner()->withPin('123456')->create();
        $staff = User::factory()->staff()->withPin('654321')->create();

        $response = $this->actingAs($staff)
            ->postJson('/pin/verify', ['pin' => '654321'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pin');

        $this->assertSame('PIN Owner salah.', $this->pinErrorFrom($response));
    }

    #[Test]
    public function the_endpoint_is_throttled_per_account(): void
    {
        User::factory()->owner()->withPin('123456')->create();
        $staff = User::factory()->staff()->create();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($staff)
                ->postJson('/pin/verify', ['pin' => '000000'])
                ->assertStatus(422);
        }

        $this->actingAs($staff)
            ->postJson('/pin/verify', ['pin' => '000000'])
            ->assertStatus(429);
    }

    #[Test]
    public function a_throttled_account_does_not_lock_out_another_one(): void
    {
        User::factory()->owner()->withPin('123456')->create();
        $first = User::factory()->staff()->create();
        $second = User::factory()->staff()->create();

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->actingAs($first)->postJson('/pin/verify', ['pin' => '000000']);
        }

        $this->actingAs($second)
            ->postJson('/pin/verify', ['pin' => '123456'])
            ->assertOk();
    }

    #[Test]
    public function a_successful_exchange_records_both_the_cashier_and_the_owner(): void
    {
        $owner = User::factory()->owner()->withPin('123456')->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->postJson('/pin/verify', [
            'pin' => '123456',
            'context' => self::CONTEXT,
        ])->assertOk();

        $log = AuditLog::query()->where('action', 'AUTHORIZE_PIN')->sole();

        $this->assertSame($owner->id, (int) $log->entity_id);
        $this->assertSame(User::class, $log->entity);
        $this->assertSame($staff->id, (int) $log->after['requested_by']);
        $this->assertSame(self::CONTEXT, $log->after['context']);
    }

    // ===== Bentuk token =====

    #[Test]
    public function the_response_carries_no_pin_in_any_form(): void
    {
        User::factory()->owner()->withPin('123456')->create();
        $staff = User::factory()->staff()->create();

        $body = $this->actingAs($staff)
            ->postJson('/pin/verify', ['pin' => '123456'])
            ->assertOk()
            ->content();

        $this->assertStringNotContainsString('123456', $body);
    }

    #[Test]
    public function a_token_is_opaque_rather_than_a_readable_payload(): void
    {
        User::factory()->owner()->withPin('123456')->create();
        $staff = User::factory()->staff()->create();

        $token = $this->actingAs($staff)
            ->postJson('/pin/verify', ['pin' => '123456', 'context' => self::CONTEXT])
            ->json('token');

        // Tidak ada assertions "token tidak mengandung X" di sini: ciphertext
        // base64 bisa saja memuat karakter yang kebetulan sama, jadi assertion
        // seperti itu lulus atau gagal tanpa berarti apa pun. Yang diuji adalah
        // bahwa isinya tidak terbaca tanpa APP_KEY.
        $this->assertStringNotContainsString(self::CONTEXT, $token);

        $payload = json_decode(Crypt::decryptString($token), true);

        $this->assertIsArray($payload);
        $this->assertSame(self::CONTEXT, $payload['ctx']);
        $this->assertSame((int) $staff->id, (int) $payload['by']);
    }

    #[Test]
    public function a_token_expires_after_the_stated_lifetime(): void
    {
        User::factory()->owner()->withPin('123456')->create();
        $staff = User::factory()->staff()->create();

        $token = $this->actingAs($staff)
            ->postJson('/pin/verify', ['pin' => '123456'])
            ->json('token');

        $payload = json_decode(Crypt::decryptString($token), true);

        $this->assertSame(5 * 60, $payload['exp'] - now()->getTimestamp());
    }

    // ===== Yang tidak boleh diterima server =====

    #[Test]
    public function a_token_from_another_context_is_refused(): void
    {
        $owner = User::factory()->owner()->withPin('123456')->create();
        $staff = User::factory()->staff()->create();

        $token = $this->actingAs($staff)
            ->postJson('/pin/verify', ['pin' => '123456', 'context' => self::CONTEXT])
            ->json('token');

        $service = resolve(PinService::class);

        $this->assertNull($service->describeFailure($staff, $token, self::CONTEXT));
        $this->assertStringContainsString(
            'berlaku untuk aksi lain',
            (string) $service->describeFailure($staff, $token, 'label.print-over-qty'),
        );
    }

    #[Test]
    public function a_token_cannot_be_handed_to_another_cashier(): void
    {
        User::factory()->owner()->withPin('123456')->create();
        $asking = User::factory()->staff()->create();
        $other = User::factory()->staff()->create();

        $token = $this->actingAs($asking)
            ->postJson('/pin/verify', ['pin' => '123456', 'context' => self::CONTEXT])
            ->json('token');

        $service = resolve(PinService::class);

        $this->assertStringContainsString(
            'diminta oleh kasir lain',
            (string) $service->describeFailure($other, $token, self::CONTEXT),
        );
    }

    #[Test]
    public function an_expired_token_is_refused(): void
    {
        User::factory()->owner()->withPin('123456')->create();
        $staff = User::factory()->staff()->create();

        $token = $this->actingAs($staff)
            ->postJson('/pin/verify', ['pin' => '123456', 'context' => self::CONTEXT])
            ->json('token');

        $this->travel(6)->minutes();

        $service = resolve(PinService::class);

        $this->assertStringContainsString(
            'kedaluwarsa',
            (string) $service->describeFailure($staff, $token, self::CONTEXT),
        );
    }

    #[Test]
    public function the_same_cashier_may_submit_the_same_form_twice_inside_the_window(): void
    {
        User::factory()->owner()->withPin('123456')->create();
        $staff = User::factory()->staff()->create();

        $token = $this->actingAs($staff)
            ->postJson('/pin/verify', ['pin' => '123456', 'context' => self::CONTEXT])
            ->json('token');

        $service = resolve(PinService::class);

        // Not an oversight, and worth stating rather than leaving to be
        // discovered: the token is scoped and short-lived, not single-use. A
        // form that fails validation for some unrelated field and is submitted
        // again would otherwise have to ask the Owner for the same PIN twice
        // within a minute, which is how a rule like this gets worked around --
        // by keeping PINs in a sticky field. What is refused is a token used for
        // a different action, a different cashier, or after five minutes.
        $this->assertNull($service->describeFailure($staff, $token, self::CONTEXT));
        $this->assertNotNull($service->describeFailure($staff, $token, 'inventory.label-overprint'));
    }

    #[Test]
    public function an_owner_who_loses_their_role_invalidates_the_tokens_they_issued(): void
    {
        $owner = User::factory()->owner()->withPin('123456')->create();
        $staff = User::factory()->staff()->create();

        $token = $this->actingAs($staff)
            ->postJson('/pin/verify', ['pin' => '123456', 'context' => self::CONTEXT])
            ->json('token');

        $this->assertTrue(
            resolve(PinService::class)->check($staff, $token, self::CONTEXT),
        );

        $owner->update(['is_active' => false]);

        $this->assertStringContainsString(
            'tidak berlaku',
            (string) resolve(PinService::class)->describeFailure($staff, $token, self::CONTEXT),
        );
    }

    #[Test]
    public function an_untampered_or_foreign_token_is_refused(): void
    {
        User::factory()->owner()->withPin('123456')->create();
        $staff = User::factory()->staff()->create();

        $service = resolve(PinService::class);

        foreach (['not-a-token', '', str_repeat('x', 64)] as $token) {
            $this->assertFalse($service->check($staff, $token, self::CONTEXT));
        }

        $this->assertFalse($service->check($staff, null, self::CONTEXT));
    }

    #[Test]
    public function a_token_signed_by_another_key_is_refused(): void
    {
        User::factory()->owner()->withPin('123456')->create();
        $staff = User::factory()->staff()->create();

        $foreign = Crypt::encryptString(json_encode([
            'sub' => User::factory()->owner()->create()->id,
            'by' => $staff->id,
            'ctx' => self::CONTEXT,
            'exp' => now()->addHour()->getTimestamp(),
        ]));

        // Disamarkan dengan mengganti satu karakter supaya gagal dekripsi, bukan berhasil
        // lalu ditolak karena isinya salah.
        $tampered = substr($foreign, 0, -1).(($foreign[-1] === 'a') ? 'b' : 'a');

        $this->assertFalse(resolve(PinService::class)->check($staff, $tampered, self::CONTEXT));
    }

    #[Test]
    public function an_inactive_cashier_cannot_ask_for_a_token(): void
    {
        User::factory()->owner()->withPin('123456')->create();
        $staff = User::factory()->staff()->inactive()->create();

        // Penolakan datang dari pemeriksaan akun, yang berjalan sebelum pemeriksaan
        // PIN di dalam web stack, jadi jawabannya 401 dan bukan galat validasi
        // pada `pin`. Itu bukan hanya beda kode: 422 pada `pin` berarti "PIN-mu
        // salah", dan itu tidak benar -- PIN-nya benar, yang salah adalah
        // akunnya. Lapisan PIN tetap menyimpan pemeriksaan `is_active`-nya sendiri
        // untuk kalau urutan ini suatu saat berubah.
        $this->actingAs($staff)
            ->postJson('/pin/verify', ['pin' => '123456'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Akun ini dinonaktifkan. Minta Owner mengaktifkannya kembali di Pengaturan · Pengguna.');
    }

    #[Test]
    public function the_pin_is_compared_against_a_hash_and_never_stored_in_the_clear(): void
    {
        $owner = User::factory()->owner()->withPin('123456')->create();

        $this->assertNotSame('123456', $owner->getRawOriginal('pin'));
        $this->assertTrue(Hash::check('123456', $owner->getRawOriginal('pin')));
    }

    /**
     * Pesan dari body 422, bukan dari flash.
     *
     * Endpoint ini menjawab JSON, jadi pesan validasinya ada di body response
     * dan tidak pernah masuk ke session flash. Membaca dari flash akan selalu
     * mengembalikan kosong, dan test-nya akan lulus karena salah alasan.
     *
     * Diterima sebagai TestResponse supaya pemanggil tidak perlu menyimpan
     * response-nya di properti yang bisa saja tertinggal dari test sebelumnya.
     */
    private function pinErrorFrom(TestResponse $response): string
    {
        $errors = $response->json('errors');

        return (string) (is_array($errors) ? ($errors['pin'][0] ?? '') : '');
    }
}
