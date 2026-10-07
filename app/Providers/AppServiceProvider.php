<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Label\HtmlLabelRenderer;
use App\Services\Label\LabelRenderer;
use App\Services\Notification\Transport\WaMeTransport;
use App\Services\Notification\Transport\WhatsappTransport;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /**
         * `LabelRenderer` diikat ke implementasi HTML sekarang, yaitu printer
         * lewat dialog browser. Nanti saat Print Agent dengan TSPL arrives,
         * cukup ganti baris ini -- tidak ada kelas lain yang perlu disentuh,
         * karena semuanya bicara ke interface.
         */
        $this->app->bind(LabelRenderer::class, HtmlLabelRenderer::class);

        /**
         * Transport WhatsApp sekarang adalah tautan `wa.me` yang dikirim manual
         * oleh Staff, sesuai SRS 6.5 yang menetapkannya sebagai fallback
         * resmi. Saat kredensial Cloud API tersedia, cukup ganti baris ini:
         * `NotificationSender` bicara ke interface, jadi tidak ada kelas lain
         * yang perlu disentuh.
         */
        $this->app->bind(WhatsappTransport::class, WaMeTransport::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->rateLimiters();
        $this->ownerGate();
    }

    /**
     * The single definition of who is an Owner.
     *
     * There is exactly one role boundary in this app, so the whole rule is one
     * line. It lives in a Gate rather than staying as `$user->isOwner()` calls
     * scattered through middleware, controllers and views so that the question
     * "may this person do owner work?" has exactly one answer in the codebase.
     * Every named ability below currently resolves to the same check -- with
     * only two roles there is no finer distinction to make yet -- but the names
     * are what the UI and the tests read, so when a third role arrives the
     * rules can diverge per ability without touching call sites.
     */
    private function ownerGate(): void
    {
        Gate::define('owner-only', fn (User $user): bool => $user->isOwner());

        foreach ([
            'manage-users',
            'manage-racks',
            'manage-products',
            'manage-consignor',
            'archive-consignment',
            'manage-series',
            'import-master',
            'commit-stock-in-pribadi',
        ] as $ability) {
            Gate::define($ability, fn (User $user): bool => $user->isOwner());
        }
    }

    private function rateLimiters(): void
    {
        /**
         * Tebakan PIN Owner dibatasi per akun, bukan per IP.
         *
         * Kasir yang sedang mengetik PIN Owner tidak boleh ikut terkunci karena
         * percobaan orang lain, dan percobaan yang salah dari beberapa kasir di
         * belakang satu IP bersama tidak boleh memakai jatah orang lain. Yang
         * dihitung adalah sesi yang sedang mencoba, karena itulah batas yang
         * ingin dijaga: berapa kali satu kasir boleh menebak.
         */
        RateLimiter::for('pin-verify', function (Request $request): Limit {
            return Limit::perMinute(5)->by('pin-verify:'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
    }
}
