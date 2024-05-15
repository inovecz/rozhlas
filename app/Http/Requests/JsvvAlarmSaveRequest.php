<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Elegant\Sanitizer\Laravel\SanitizesInput;

class JsvvAlarmSaveRequest extends FormRequest
{
    use SanitizesInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string',
            'sequence' => 'nullable|string|max:4',
            'button' => 'nullable|integer|min:1|max:8',
            'mobile_button' => 'nullable|integer|min:0|max:9',
        ];
    }

    public function filters(): array
    {
        return [
            'name' => 'trim',
            'sequence' => 'trim|empty_string_to_null',
            'button' => 'trim|empty_string_to_null',
            'mobile_button' => 'trim|empty_string_to_null',
        ];
    }
}
