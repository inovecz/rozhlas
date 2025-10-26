<?php

declare(strict_types=1);

namespace App\Services;

class PlaylistPlaybackResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly array $context = []
    ) {
    }

    public static function success(array $context = []): self
    {
        return new self(true, 'ok', $context);
    }

    public static function failure(string $status, array $context = []): self
    {
        return new self(false, $status, $context);
    }
}
