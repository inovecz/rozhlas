<?php

declare(strict_types=1);

namespace App\Services\ControlTab;

use App\Models\LocationGroup;

class ControlTabDataProvider
{
    public function listLocalities(): string
    {
        return LocationGroup::query()
            ->where(function ($query): void {
                $query
                    ->whereNull('is_hidden')
                    ->orWhere('is_hidden', false);
            })
            ->orderBy('name')
            ->pluck('name')
            ->map(static fn ($name) => (string) $name)
            ->implode("\n");
    }

    public function listJingles(): string
    {
        return '';
    }
}
