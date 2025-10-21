<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VolumeSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'groups' => ['required', 'array', 'min:1'],
            'groups.*.id' => ['required', 'string', Rule::in(array_keys(config('volume', [])))],
            'groups.*.items' => ['required', 'array'],
            'groups.*.items.*.id' => ['required', 'string'],
            'groups.*.items.*.value' => ['required', 'numeric'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $config = config('volume', []);

            foreach ((array) $this->input('groups', []) as $group) {
                $groupId = $group['id'] ?? null;
                if (!is_string($groupId) || !isset($config[$groupId]['items'])) {
                    $validator->errors()->add('groups', __('Neznámá skupina hlasitostí.'));
                    continue;
                }

                foreach ((array) ($group['items'] ?? []) as $item) {
                    $itemId = $item['id'] ?? null;
                    if (!is_string($itemId) || !array_key_exists($itemId, $config[$groupId]['items'])) {
                        $validator->errors()->add('groups', __('Neznámá položka hlasitosti.'));
                    }
                }
            }
        });
    }
}
