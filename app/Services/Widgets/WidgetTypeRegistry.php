<?php

namespace App\Services\Widgets;

use InvalidArgumentException;

/**
 * Maps widgets.type onto the class that knows how to configure, render and
 * process it. Register future widget types here.
 */
class WidgetTypeRegistry
{
    /** @var array<string, class-string<WidgetType>> */
    private const TYPES = [
        'form' => FormWidgetType::class,
        'chat' => ChatWidgetType::class,
    ];

    public static function resolve(string $type): WidgetType
    {
        if (!isset(self::TYPES[$type])) {
            throw new InvalidArgumentException("Unsupported widget type [{$type}].");
        }

        return app(self::TYPES[$type]);
    }

    public static function supports(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::TYPES);
    }
}
