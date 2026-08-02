<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per user holding the settings the Preferences panel manages.
 *
 * The allow-lists below are the single source of truth for what each field may
 * hold; both the validation rules and the "is this still valid" checks read
 * them, so adding a notification sound or a search target is a one-line change.
 */
class UserPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'theme',
        'language',
        'optus_enabled',
        'notification_sound',
        'default_search_target',
    ];

    protected $casts = [
        'optus_enabled' => 'boolean',
    ];

    public const THEMES = ['dark', 'light'];

    public const LANGUAGES = ['en', 'pt-BR', 'de', 'es'];

    public const SOUNDS = [
        'default', 'soft-bell', 'digital-pop', 'subtle-ping', 'bright-chime', 'none',
    ];

    /**
     * `menu` searches the navigation itself rather than a database table, so it
     * is resolved entirely on the client — it has no endpoint.
     */
    public const SEARCH_TARGETS = ['leads', 'deals', 'users', 'menu'];

    /** Matches the column defaults, for building a response without a write. */
    public const DEFAULTS = [
        'theme'                 => 'dark',
        'language'              => 'en',
        'optus_enabled'         => false,
        'notification_sound'    => 'default',
        'default_search_target' => 'leads',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function validationRules(): array
    {
        return [
            'theme'                 => ['sometimes', 'in:' . implode(',', self::THEMES)],
            'language'              => ['sometimes', 'in:' . implode(',', self::LANGUAGES)],
            'optus_enabled'         => ['sometimes', 'boolean'],
            'notification_sound'    => ['sometimes', 'in:' . implode(',', self::SOUNDS)],
            'default_search_target' => ['sometimes', 'in:' . implode(',', self::SEARCH_TARGETS)],
        ];
    }
}
