<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VolumeLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $groups = array_keys(config('volume', []));

        return [
            'group' => ['required', 'string', Rule::in($groups)],
            'id' => ['required', 'string'],
            'value' => ['required', 'numeric'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $group = $this->input('group');
            $itemId = $this->input('id');

            if (!is_string($group) || !is_string($itemId)) {
                return;
            }

            $items = config(sprintf('volume.%s.items', $group), []);
            if (!is_array($items) || !array_key_exists($itemId, $items)) {
                $validator->errors()->add('id', __('Neznámá hlasitostní položka.'));
            }
        });
    }
}
