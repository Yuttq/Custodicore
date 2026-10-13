<?php

namespace App\Providers;

use App\Http\Middleware\StartSession;
use App\Services\Auth\GoogleIdTokenVerifier;
use Google\AccessToken\Verify;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Session\SessionManager;
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

        // Same construction as the stock StartSession singleton
        // (SessionServiceProvider), so session blocking keeps its cache.
        $this->app->singleton(StartSession::class, function ($app) {
            return new StartSession($app->make(SessionManager::class), fn () => $app->make(CacheFactory::class));
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
