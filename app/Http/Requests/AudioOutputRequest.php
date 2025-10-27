<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AudioOutputRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $outputs = config('audio.outputs.items', []);
        $identifiers = array_keys(is_array($outputs) ? $outputs : []);

        return [
            'identifier' => ['required', 'string', Rule::in($identifiers)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('identifier')) {
            $this->merge([
                'identifier' => strtolower((string) $this->input('identifier')),
            ]);
        }
    }
}
