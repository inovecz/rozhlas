<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class LongIP implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        return long2ip($value);
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return ip2long($value);
    }
}
