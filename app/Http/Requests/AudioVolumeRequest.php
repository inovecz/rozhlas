<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AudioVolumeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $volumes = config('audio.volumes', []);
        $identifiers = array_keys(is_array($volumes) ? $volumes : []);

        return [
            'scope' => ['required', 'string', Rule::in($identifiers)],
            'value' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'mute' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('scope')) {
            $this->merge([
                'scope' => strtolower((string) $this->input('scope')),
            ]);
        }

        if ($this->has('mute')) {
            $this->merge([
                'mute' => filter_var($this->input('mute'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }
}
