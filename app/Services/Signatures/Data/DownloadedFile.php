<?php

namespace App\Services\Signatures\Data;

/** A finished document pulled back from the vendor, in memory. */
class DownloadedFile
{
    public function __construct(
        public readonly string $contents,
        public readonly string $filename,
        public readonly string $mimeType = 'application/pdf',
    ) {
    }

    public function sizeBytes(): int
    {
        return strlen($this->contents);
    }
}
