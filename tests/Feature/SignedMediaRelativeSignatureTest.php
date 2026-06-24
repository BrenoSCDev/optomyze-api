<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

afterEach(function () {
    URL::forceScheme(null);
});

/**
 * Reproduces the actual prod bug: a request that arrives over plain HTTP
 * (no HTTPS server var, no trusted X-Forwarded-Proto) for a path+query
 * that was originally signed assuming HTTPS - exactly what happens behind
 * a reverse proxy that terminates TLS and isn't trusted by Laravel.
 *
 * Building a Symfony Request directly (rather than handing a URL string to
 * the test client) matters here: passing an "https://..." string to
 * $this->get() makes the test client itself set HTTPS=on to match, which
 * would silently hide the exact mismatch we're trying to prove/disprove.
 */
function requestAsIfPlainHttp(string $pathAndQuery): Request
{
    return Request::create($pathAndQuery, 'GET');
}

test('an absolute signature breaks when the validating request looks like plain http (the prod bug)', function () {
    URL::forceScheme('https');
    $absoluteUrl = URL::temporarySignedRoute(
        'files.signed',
        now()->addMinutes(30),
        ['path' => 'instagram/outbound/3/clip.webm'],
        true
    );
    URL::forceScheme(null);

    expect($absoluteUrl)->toStartWith('https://');

    $parts = parse_url($absoluteUrl);
    $request = requestAsIfPlainHttp($parts['path'] . '?' . $parts['query']);

    expect($request->isSecure())->toBeFalse();
    expect($request->hasValidSignature())->toBeFalse();
});

test('a relative signature stays valid when the validating request looks like plain http', function () {
    URL::forceScheme('https');
    $signedPath = URL::temporarySignedRoute(
        'files.signed',
        now()->addMinutes(30),
        ['path' => 'instagram/outbound/3/clip.webm'],
        false
    );
    URL::forceScheme(null);

    $request = requestAsIfPlainHttp($signedPath);

    expect($request->isSecure())->toBeFalse();
    expect($request->hasValidRelativeSignature())->toBeTrue();
});

test('the deployed signed-media route serves the file given a valid relative signature', function () {
    Storage::fake('local');
    Storage::disk('local')->put('instagram/outbound/3/clip.webm', 'fake-bytes');

    $signedPath = URL::temporarySignedRoute(
        'files.signed',
        now()->addMinutes(30),
        ['path' => 'instagram/outbound/3/clip.webm'],
        false
    );

    $response = $this->get($signedPath);

    $response->assertOk();
});

test('the deployed signed-media route rejects a tampered path', function () {
    Storage::fake('local');
    Storage::disk('local')->put('instagram/outbound/3/clip.webm', 'fake-bytes');

    $signedPath = URL::temporarySignedRoute(
        'files.signed',
        now()->addMinutes(30),
        ['path' => 'instagram/outbound/3/clip.webm'],
        false
    );

    $response = $this->get($signedPath . 'x');

    $response->assertForbidden();
});

test('the deployed signed-media route rejects an expired signature', function () {
    Storage::fake('local');
    Storage::disk('local')->put('instagram/outbound/3/clip.webm', 'fake-bytes');

    $signedPath = URL::temporarySignedRoute(
        'files.signed',
        now()->subMinutes(1),
        ['path' => 'instagram/outbound/3/clip.webm'],
        false
    );

    $response = $this->get($signedPath);

    $response->assertForbidden();
});
