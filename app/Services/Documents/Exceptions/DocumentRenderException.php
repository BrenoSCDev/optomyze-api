<?php

namespace App\Services\Documents\Exceptions;

use RuntimeException;

/**
 * A template could not be rendered, or its output could not be turned into a
 * PDF.
 *
 * `line` is the template line number when the failure is a syntax problem the
 * author can fix — an unclosed `{% if %}`, an unknown tag. Author-facing
 * messages, because the person who sees them is the person editing the
 * template.
 */
class DocumentRenderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $templateLine = null,
    ) {
        parent::__construct($message);
    }

    public static function syntax(string $message, ?int $line = null): self
    {
        return new self($line ? "{$message} (linha {$line})" : $message, $line);
    }

    public static function pdf(string $message): self
    {
        return new self("Não foi possível gerar o PDF: {$message}");
    }
}
