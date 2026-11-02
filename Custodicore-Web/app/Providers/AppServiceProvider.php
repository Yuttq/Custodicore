<?php

namespace App\Providers;

use App\Http\Middleware\StartSession;
use App\Logging\RedactQueryBindings;
use App\Services\Auth\GoogleIdTokenVerifier;
use App\Support\MailDeliveryCheck;
use Google\AccessToken\Verify;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

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
        // Mobile bearer tokens stop working as soon as the account is no
        // longer active (inactive/suspended). Sanctum's own checks
        // (expiration, expires_at, provider) still decide first: an invalid
        // token never becomes valid here. A rejected token gives the API a 401.
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid) => $isValid && $token->tokenable?->status === 'active'
        );

        // Lets the log tap swap query values for ? placeholders when a failed
        // query's message is logged as plain text (see RedactQueryBindings).
        Event::listen(ConnectionEstablished::class, fn (ConnectionEstablished $event) => RedactQueryBindings::watch($event->connection));
        foreach (DB::getConnections() as $connection) {
            RedactQueryBindings::watch($connection);
        }

        MailDeliveryCheck::warnIfNotDelivering($this->app->isProduction());
    }
}
