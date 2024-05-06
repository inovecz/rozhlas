<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\SmtpTypeEnum;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Foundation\Http\FormRequest;
use Elegant\Sanitizer\Laravel\SanitizesInput;

class SmtpSettingsRequest extends FormRequest
{
    use SanitizesInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'host' => 'required|string',
            'port' => 'required|integer',
            'encryption' => ['required', 'string', new Enum(SmtpTypeEnum::class)],
            'username' => 'required|string',
            'password' => 'required|string',
            'from_address' => 'required|string',
            'from_name' => 'required|string',
        ];
    }

    public function filters(): array
    {
        return [
            'host' => 'trim|lowercase',
            'port' => 'digit',
            'encryption' => 'trim|uppercase',
            'username' => 'trim',
            'from_address' => 'trim|lowercase',
            'from_name' => 'trim',
        ];
    }
}
