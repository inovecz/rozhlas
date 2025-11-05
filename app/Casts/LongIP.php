<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class LongIP implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        if ($value === null || $value === '') {
            return null;
        }

        $long = is_numeric($value) ? (int) $value : ip2long((string) $value);
        if ($long === false) {
            return null;
        }

        return long2ip($long) ?: null;
    }

    public function set($model, string $key, $value, array $attributes)
    {
        $candidate = $value;
        if ($candidate === null || trim((string) $candidate) === '') {
            $candidate = request()?->ip() ?: '127.0.0.1';
        }

        $resolved = ip2long((string) $candidate);
        if ($resolved === false) {
            $resolved = ip2long('127.0.0.1');
        }

        return $resolved;
    }
}
