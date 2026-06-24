<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\URL;

afterEach(function () {
    // Don't leak the forced scheme into other tests in the same process.
    URL::forceScheme(null);
});

test('forces https url generation when the app environment is production', function () {
    $this->app['env'] = 'production';

    (new AppServiceProvider($this->app))->boot();

    $url = URL::temporarySignedRoute('files.signed', now()->addMinutes(5), ['path' => 'instagram/outbound/3/file.webm']);

    expect($url)->toStartWith('https://');
});

test('does not force https url generation outside production', function () {
    $this->app['env'] = 'testing';

    (new AppServiceProvider($this->app))->boot();

    $url = URL::temporarySignedRoute('files.signed', now()->addMinutes(5), ['path' => 'instagram/outbound/3/file.webm']);

    expect($url)->toStartWith('http://');
});
