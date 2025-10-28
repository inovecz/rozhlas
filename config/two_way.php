<?php

return [
    'route_prefix' => (static function () {
        $prefix = [];
        $hub = env('TWO_WAY_HUB_ADDRESS', 1);
        if ($hub !== null && $hub !== '') {
            if (is_numeric($hub)) {
                $prefix[] = (int) $hub;
            }
        }

        $additional = env('TWO_WAY_ROUTE_PREFIX', '');
        if (is_string($additional) && trim($additional) !== '') {
            foreach (explode(',', $additional) as $value) {
                $value = trim($value);
                if ($value === '' || !is_numeric($value)) {
                    continue;
                }
                $intValue = (int) $value;
                if (!in_array($intValue, $prefix, true)) {
                    $prefix[] = $intValue;
                }
            }
        }

        return $prefix;
    })(),
];

