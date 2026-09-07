<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth', function (Request $request): Limit {
            $email = Str::lower($request->string('email')->toString());

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('auth-account', fn (Request $request): Limit => Limit::perMinute(5)->by(
            'auth-account|'.Str::lower($request->string('email')->toString()),
        ));

        RateLimiter::for('pairing', fn (Request $request): Limit => Limit::perMinute(5)->by('pairing|'.$request->ip()));
        RateLimiter::for('pin', fn (Request $request): Limit => Limit::perMinute(30)->by('pin|'.$request->ip()));
        RateLimiter::for('shared-device', function (Request $request): Limit {
            $device = $request->attributes->get('shared_device');

            return Limit::perMinute(30)->by('shared-device|'.($device?->getKey() ?? $request->ip()));
        });

        RateLimiter::for('reporting', function (Request $request): Limit {
            $actor = $request->user();
            $key = $actor === null ? $request->ip() : $actor->getAuthIdentifier();

            return Limit::perMinute(30)->by('reporting|'.$key);
        });
    }
}
