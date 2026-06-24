<?php

namespace App\Providers;

use App\Models\Lead;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

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
        Relation::enforceMorphMap([
            'Lead' => Lead::class,
            'Sale' => Sale::class,
            'User' => User::class,
        ]);

        // Prod sits behind a TLS-terminating reverse proxy that forwards
        // plain HTTP internally, and TrustProxies isn't configured to read
        // X-Forwarded-Proto - so without this, generated absolute URLs
        // (signed routes included) come out as http://, which then fails
        // signature validation when fetched over https.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
