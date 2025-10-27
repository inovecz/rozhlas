<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AudioInputRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $inputs = config('audio.inputs.items', []);
        $identifiers = array_keys(is_array($inputs) ? $inputs : []);

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
