<?php

namespace App\Providers;

use App\Support\Visibility\MenuVisibility;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class VisibilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(MenuVisibility::class, fn () => new MenuVisibility);
    }

    public function boot(): void
    {
        Blade::if('feature', function (string $key) {
            $user = auth()->user();

            return app(MenuVisibility::class)->featureVisible($user, $key);
        });
    }
}
