<?php

namespace App\Providers;

use App\Models\Lead;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One registry for the whole request, so drivers resolved through it
        // are shared and can be swapped wholesale in tests.
        $this->app->singleton(
            \App\Services\Signatures\SignatureProviderRegistry::class,
            fn ($app) => new \App\Services\Signatures\SignatureProviderRegistry($app)
        );
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
            'Appointment'     => \App\Models\Appointment::class,
            'Company'         => \App\Models\Company::class,
            'LeadCompany'     => \App\Models\LeadCompany::class,
            'LeadDocument'    => \App\Models\LeadDocument::class,
            'SaleDoc'         => \App\Models\SaleDoc::class,
            'ProductImage'    => \App\Models\ProductImage::class,
            'InternalMessage' => \App\Models\InternalMessage::class,
            'InboxMessage'    => \App\Models\InboxMessage::class,

            // Document platform: a Document is the business record, and its
            // rendered versions plus the signature module's file rows all own
            // entries in company_files.
            'Document'          => \App\Models\Document::class,
            'DocumentVersion'   => \App\Models\DocumentVersion::class,
            'SignatureRequest'  => \App\Models\SignatureRequest::class,
            'SignatureDocument' => \App\Models\SignatureDocument::class,
        ]);

        /*
         * The configured PDF driver, resolvable as its interface.
         *
         * Bound here so every caller goes through the same `documents.pdf`
         * config rather than re-implementing the lookup — swapping dompdf for
         * a Chrome-backed renderer stays a one-line config change.
         */
        $this->app->bind(\App\Services\Documents\Contracts\PdfRenderer::class, function () {
            $driver = (string) config('documents.pdf.driver', 'dompdf');
            $class  = config("documents.pdf.drivers.{$driver}");

            if (!$class || !class_exists($class)) {
                throw new \App\Services\Documents\Exceptions\DocumentRenderException(
                    "Driver de PDF [{$driver}] não está registrado."
                );
            }

            return $this->app->make($class);
        });

        // Document lifecycle side effects. Registered explicitly rather than
        // auto-discovered because these listeners answer to several events
        // each, and because the list is the readable statement of what a
        // signed contract actually causes.
        //
        // Their methods are named on*(), NOT handle*(), and must stay that
        // way. Laravel's DiscoverEvents scans app/Listeners and registers
        // every `handle*` method whose first parameter is a typed event — so a
        // listener named handleSigned() gets registered by discovery AND by
        // the explicit line below, and then runs twice. That is not
        // theoretical: it double-wrote contract timeline entries and
        // double-sent signature notifications until 2026-08-07.
        Event::listen(
            \App\Events\DocumentSigned::class,
            [\App\Listeners\Documents\MoveDealToWon::class, 'onSigned']
        );
        Event::subscribe(\App\Listeners\Documents\WriteDocumentRecord::class);
        Event::subscribe(\App\Listeners\Documents\NotifyDocumentOwner::class);

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
