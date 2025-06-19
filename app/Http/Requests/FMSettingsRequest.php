<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Elegant\Sanitizer\Laravel\SanitizesInput;

class FMSettingsRequest extends FormRequest
{
    use SanitizesInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'frequency' => 'required|numeric',
        ];
    }

    public function filters(): array
    {
        return [
            'frequency' => 'trim|cast:float',
        ];
    }
}
