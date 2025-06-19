<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Elegant\Sanitizer\Laravel\SanitizesInput;

class UserSaveRequest extends FormRequest
{
    use SanitizesInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'nullable|integer|exists:users,id',
            'username' => 'required|string',
            'password' => 'nullable|string',
        ];
    }

    public function filters(): array
    {
        return [
            'id' => 'trim|escape|digit',
            'username' => 'trim|escape',
            'password' => 'empty_string_to_null',
        ];
    }
}
