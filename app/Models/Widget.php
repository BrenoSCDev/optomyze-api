<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * An embeddable widget belonging to a company.
 *
 * `type` picks the behaviour (see App\Services\Widgets\WidgetTypeRegistry) and
 * `config` holds that type's schema, so the table serves every future widget.
 */
class Widget extends Model
{
    use HasCompanyTimezone, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'company_id',
        'created_by',
        'type',
        'public_id',
        'name',
        'status',
        'allowed_domains',
        'config',
    ];

    protected $casts = [
        'allowed_domains' => 'array',
        'config' => 'array',
        'last_submission_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(WidgetSubmission::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Public identifier embedded in the customer's website. Prefixed by type so
     * a snippet is self-describing, and random enough not to be guessable.
     */
    public static function newPublicId(string $type = 'form'): string
    {
        do {
            $publicId = $type . '_' . Str::lower(Str::random(24));
        } while (self::withTrashed()->where('public_id', $publicId)->exists());

        return $publicId;
    }

    /**
     * Whether a browser Origin/Referer is covered by this widget's domain list.
     * Matching is host-based ("https://site.com/page" == "site.com") and a
     * configured bare domain also covers its "www." form and any subdomain.
     */
    public function allowsOrigin(?string $origin): bool
    {
        $domains = $this->allowed_domains ?? [];

        if (empty($domains)) {
            return false;
        }

        $host = self::normalizeHost($origin);

        if ($host === null) {
            return false;
        }

        foreach ($domains as $domain) {
            $allowed = self::normalizeHost($domain);

            if ($allowed === null) {
                continue;
            }

            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reduce a URL, origin or bare domain to a comparable lowercase host,
     * dropping scheme, port, path and any leading "www.".
     *
     * Returns null for anything that isn't a syntactically valid hostname, so
     * garbage can neither be stored as an allowed domain nor match one.
     */
    public static function normalizeHost(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $value = trim($value);

        if (!str_contains($value, '://')) {
            $value = 'https://' . $value;
        }

        $host = parse_url($value, PHP_URL_HOST);

        if (!$host) {
            return null;
        }

        $host = strtolower($host);

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        if (strlen($host) > 253) {
            return null;
        }

        // Labels of letters/digits/hyphens; a dotted name (acme.com) or the
        // bare "localhost" that local development runs on.
        $labels = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/';

        if (!preg_match($labels, $host)) {
            return null;
        }

        if (!str_contains($host, '.') && $host !== 'localhost') {
            return null;
        }

        return $host;
    }
}
