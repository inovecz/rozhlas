<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocationGroup;
use Illuminate\Http\Response;

class ControlTabDataController extends Controller
{
    public function localities(): Response
    {
        $names = LocationGroup::query()
            ->where(function ($query): void {
                $query
                    ->whereNull('is_hidden')
                    ->orWhere('is_hidden', false);
            })
            ->orderBy('name')
            ->pluck('name')
            ->map(static fn ($name) => (string) $name)
            ->implode("\n");

        return response($names, 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function jingles(): Response
    {
        return response('', 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}

