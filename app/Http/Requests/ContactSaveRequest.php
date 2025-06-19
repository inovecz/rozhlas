<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Elegant\Sanitizer\Laravel\SanitizesInput;

class ContactSaveRequest extends FormRequest
{
    use SanitizesInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:191',
            'surname' => 'required|string|max:191',
            'position' => 'nullable|string|max:191',
            'email' => 'nullable|email|max:191',
            'has_info_email_allowed' => 'required|boolean',
            'phone' => 'nullable|string|max:191',
            'has_info_sms_allowed' => 'required|boolean',
            'contact_groups' => 'nullable|array',
            'contact_groups.*' => 'integer|exists:contact_groups,id',
        ];
    }

    public function filters(): array
    {
        return [
            'name' => 'trim',
            'surname' => 'trim',
            'position' => 'trim|empty_string_to_null',
            'email' => 'trim|lowercase|empty_string_to_null',
            'phone' => 'trim|empty_string_to_null',
            'contact_groups.*' => 'trim|digit',
        ];
    }
}
