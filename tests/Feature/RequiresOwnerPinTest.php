<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\Concerns\RequiresOwnerPin;
use App\Models\User;
use App\Services\Auth\PinService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The rule "which actions need an Owner's PIN" has to be checkable in one place.
 *
 * This is wired as real requests against throwaway routes rather than by calling
 * the trait's methods directly, because the trait's whole job is the part that
 * only exists inside a request: reading the token off the POST, resolving the
 * acting user from the session, and turning a refusal into an error the form can
 * render. Calling `ownerPinRules()` in isolation would pass while every one of
 * those was broken -- and until this test, the trait had never been loaded by PHP
 * at all, so not even a syntax error in it would have been caught.
 */
class RequiresOwnerPinTest extends TestCase
{
    use RefreshDatabase;

    private ?User $staff = null;

    private ?User $otherStaff = null;

    private ?User $owner = null;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth'])->post('/_pin-probe/override', function (OverrideRequest $request) {
            return response()->json(['satisfied' => $request->ownerPinIsSatisfied()]);
        });

        Route::middleware(['web', 'auth'])->post('/_pin-probe/no-pin', function (NoPinRequest $request) {
            return response()->json(['satisfied' => $request->ownerPinIsSatisfied()]);
        });

        // Dibuat di sini, bukan saat dipakai: an Owner is the only role allowed
        // to mint a grant, so a test that mints one would otherwise depend on
        // which fixture happened to be built first.
        $this->owner();
    }

    #[Test]
    public function a_staff_action_without_a_token_is_refused(): void
    {
        // A form POST, so the refusal comes back the way every other validation
        // failure in this app comes back: redirected, with the error on the field.
        $this->postOverride($this->staff())
            ->assertRedirect('/_pin-probe/override')
            ->assertSessionHasErrors('pin_token');
    }

    #[Test]
    public function the_refusal_is_reported_on_the_field_the_form_has(): void
    {
        // The form renders errors per field. A message attached to a field the
        // form does not have is a message nobody reads, and the action still
        // fails without a stated reason.
        $this->postOverride($this->staff())
            ->assertRedirect('/_pin-probe/override')
            ->assertSessionHasErrorsIn('default', 'pin_token');
    }

    #[Test]
    public function a_staff_action_with_a_live_token_in_its_own_context_is_allowed(): void
    {
        $this->postOverride($this->staff(), [
            'pin_token' => $this->tokenFor('consignment.scheme-override'),
        ])
            ->assertOk()
            ->assertJson(['satisfied' => true]);
    }

    #[Test]
    public function a_token_issued_for_another_action_is_refused(): void
    {
        // The same Owner, the same cashier, the same second -- and still refused.
        // This is the property that makes a token worth issuing at all: it is
        // scoped to one action, so a token that leaks out of one form cannot be
        // carried into another.
        $this->postOverride($this->staff(), [
            'pin_token' => $this->tokenFor('inventory.label-overprint'),
        ])
            ->assertRedirect('/_pin-probe/override')
            ->assertSessionHasErrors('pin_token');
    }

    #[Test]
    public function a_token_issued_to_another_cashier_is_refused(): void
    {
        $this->postOverride($this->otherStaff(), [
            'pin_token' => $this->tokenFor('consignment.scheme-override'),
        ])
            ->assertRedirect('/_pin-probe/override')
            ->assertSessionHasErrors('pin_token');
    }

    #[Test]
    public function an_owner_does_not_authorise_themselves(): void
    {
        // The Owner is the one allowed to do this at all. Asking them to also
        // prove it adds a step and no security, and it is the step most likely to
        // be "fixed" later by leaving the token field empty.
        $this->postOverride($this->owner())
            ->assertOk()
            ->assertJson(['satisfied' => true]);
    }

    #[Test]
    public function an_inactive_staff_member_cannot_act_on_a_token_issued_earlier(): void
    {
        $staff = $this->staff();
        $token = $this->tokenFor('consignment.scheme-override');

        $staff->forceFill(['is_active' => false])->save();

        // A token lasts five minutes and a session can outlive that. Nothing in
        // the token says whether the person carrying it still works here, so the
        // request is the only place that can ask.
        //
        // The refusal now arrives from the account check, which runs ahead of the
        // PIN check in the web stack, so the deactivated account is signed out
        // rather than merely turned away at this one form. The PIN layer keeps
        // its own `is_active` guard: it is what refuses the action if the two
        // ever run in the other order, and a token should not be worth spending
        // on a question the account already answers.
        $this->postOverride($staff, ['pin_token' => $token])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    #[Test]
    public function a_request_that_does_not_need_a_pin_never_looks_at_the_token(): void
    {
        // `ownerPinRules()` returns nothing at all rather than an optional field,
        // and `ownerPinIsSatisfied()` follows `requiresOwnerPin()` rather than
        // deciding separately. The two agreeing is the point: if the check
        // demanded a token that the rules never asked for, this request would
        // pass validation and then be refused one layer lower, for a reason the
        // form never displayed.
        $this->actingAs($this->staff())
            ->post('/_pin-probe/no-pin', ['scheme' => 'PERCENTAGE', 'pin_token' => 'not-a-token'])
            ->assertOk()
            ->assertJson(['satisfied' => true]);
    }

    private function postOverride(User $user, array $overrides = []): TestResponse
    {
        return $this->actingAs($user)
            ->from('/_pin-probe/override')
            ->post('/_pin-probe/override', array_merge(['scheme' => 'PERCENTAGE'], $overrides));
    }

    private function tokenFor(string $context): string
    {
        return app(PinService::class)->issue($this->staff(), '123456', $context)->token;
    }

    /**
     * Memoised, because a grant is bound to the id of the cashier who asked for
     * it. A factory call that returns a fresh row per invocation would mint a
     * token for a person who is not the one posting, and every "allowed" case
     * would then be refused for the right reason and the wrong one.
     */
    private function staff(): User
    {
        return $this->staff ??= User::factory()->staff()->create();
    }

    private function otherStaff(): User
    {
        return $this->otherStaff ??= User::factory()->staff()->create();
    }

    private function owner(): User
    {
        return $this->owner ??= User::factory()->owner()->withPin('123456')->create();
    }
}

/**
 * Stand-in for a Phase B request: an action that carries a consignor's terms.
 *
 * Only `rules()` spreads the trait's rules in, and nothing else is needed. That is
 * the point of the test: an earlier version also required a `withValidator()`
 * call, and a request that provided it while still passing validation would have
 * looked fine in every test that never checked a refusal.
 */
class OverrideRequest extends FormRequest
{
    use RequiresOwnerPin;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scheme' => ['required', 'string', 'in:PERCENTAGE,NETT,FLAT'],
            ...$this->ownerPinRules(),
        ];
    }

    protected function ownerPinContext(): string
    {
        return 'consignment.scheme-override';
    }
}

/** Stand-in for a request that needs no Owner at all. */
class NoPinRequest extends FormRequest
{
    use RequiresOwnerPin;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scheme' => ['required', 'string', 'in:PERCENTAGE,NETT,FLAT'],
            ...$this->ownerPinRules(),
        ];
    }

    protected function ownerPinContext(): string
    {
        return 'consignment.scheme-override';
    }

    protected function requiresOwnerPin(): bool
    {
        return false;
    }
}
