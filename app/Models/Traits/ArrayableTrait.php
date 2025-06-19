<?php

namespace App\Models\Traits;

use Illuminate\Support\Str;

trait ArrayableTrait
{
    public function getToArray(string|array|null $scope = 'default'): array
    {
        $array = [];
        $scope = $scope ?? 'default';
        $scopes = is_array($scope) ? $scope : [$scope];

        foreach ($scopes as $singleScope) {
            $params = null;
            if (Str::of($singleScope)->contains(':')) {
                $params = explode(':', $singleScope);
                $singleScope = array_shift($params);
            }
            $scopeToMethodEnding = Str::of($singleScope)->headline()->replace(' ', '');
            $methodName = 'getToArray'.$scopeToMethodEnding;
            if (method_exists($this, $methodName)) {
                if ($params) {
                    $array += $this->$methodName(...$params);
                } else {
                    $array += $this->$methodName();
                }
            } else {
                $methodName = 'getArray'.$scopeToMethodEnding;
                if (method_exists($this, $methodName)) {
                    if ($params) {
                        $array += $this->$methodName(...$params);
                    } else {
                        $array += $this->$methodName();
                    }
                }
            }
        }
        $array = empty($array) ? $this->getToArrayDefault() : $array;

        if (method_exists($this, 'getCustomFields')) {
            $array = array_merge($array, ['custom_fields' => $this->getCustomFields()]);
        }
        return $array;
    }

    public function getToArrayDefault(): array
    {
        return $this->toArray();
    }
}
