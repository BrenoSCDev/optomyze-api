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
        // Models that own polymorphic rows. The file-owning entries exist so
        // company_files can point back at what a stored file belongs to;
        // without them getMorphClass() throws under the enforced map.
        Relation::enforceMorphMap([
            'Lead'            => Lead::class,
            'Sale'            => Sale::class,
            'User'            => User::class,
            'Company'         => \App\Models\Company::class,
            'LeadCompany'     => \App\Models\LeadCompany::class,
            'LeadDocument'    => \App\Models\LeadDocument::class,
            'SaleDoc'         => \App\Models\SaleDoc::class,
            'ProductImage'    => \App\Models\ProductImage::class,
            'InternalMessage' => \App\Models\InternalMessage::class,
            'InboxMessage'    => \App\Models\InboxMessage::class,
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
