<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Elegant\Sanitizer\Laravel\SanitizesInput;

class JsvvSettingsRequest extends FormRequest
{
    use SanitizesInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'locationGroupId' => 'nullable|integer|exists:location_groups,id',
        ];
    }

    public function filters(): array
    {
        return [
            'locationGroupId' => 'trim|digit|empty_string_to_null',
        ];
    }
}
