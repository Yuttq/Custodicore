<?php

namespace App\Providers;

use App\Services\Auth\GoogleIdTokenVerifier;
use Google\AccessToken\Verify;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GoogleIdTokenVerifier::class, function () {
            return new GoogleIdTokenVerifier(
                (array) config('services.google.client_ids', []),
                // Short timeouts: the JWKS fetch happens inside a login request.
                new Verify(new GuzzleClient(['timeout' => 5, 'connect_timeout' => 3])),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
