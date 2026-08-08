<?php

namespace App\Services\Signatures;

use App\Services\Signatures\Contracts\SignatureProvider;
use App\Services\Signatures\Exceptions\UnsupportedProviderException;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves a provider key onto its driver, through the container.
 *
 * Drivers are declared in config/signatures.php and built by the container,
 * so they get constructor injection like anything else and can be swapped or
 * faked in tests with a plain `$this->app->bind()`. Registering a new vendor
 * is a config entry plus a class — this file does not change.
 */
class SignatureProviderRegistry
{
    /** @var array<string, SignatureProvider> */
    private array $resolved = [];

    public function __construct(private readonly Container $container)
    {
    }

    public function get(string $key): SignatureProvider
    {
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $driver = config("signatures.providers.{$key}.driver");

        if (!$driver || !class_exists($driver)) {
            throw UnsupportedProviderException::forKey($key);
        }

        $provider = $this->container->make($driver);

        if (!$provider instanceof SignatureProvider) {
            throw new UnsupportedProviderException(
                "Driver [{$driver}] for signature provider [{$key}] must implement " . SignatureProvider::class . '.'
            );
        }

        return $this->resolved[$key] = $provider;
    }

    public function default(): SignatureProvider
    {
        return $this->get($this->defaultKey());
    }

    public function defaultKey(): string
    {
        return (string) config('signatures.default', 'clicksign');
    }

    public function supports(string $key): bool
    {
        return (bool) config("signatures.providers.{$key}.driver");
    }

    /** @return string[] */
    public function keys(): array
    {
        return array_keys((array) config('signatures.providers', []));
    }

    /**
     * Every registered provider with its label and capabilities — what the
     * integrations screen lists.
     *
     * @return array<int, array{key: string, label: string, capabilities: array}>
     */
    public function catalog(): array
    {
        return array_map(function (string $key) {
            $provider = $this->get($key);

            return [
                'key'          => $provider->key(),
                'label'        => $provider->label(),
                'capabilities' => $provider->capabilities()->toArray(),
            ];
        }, $this->keys());
    }

    /** Config for one provider, e.g. its environment base URLs. */
    public function config(string $key, ?string $path = null, mixed $default = null): mixed
    {
        $base = "signatures.providers.{$key}";

        return config($path ? "{$base}.{$path}" : $base, $default);
    }
}
