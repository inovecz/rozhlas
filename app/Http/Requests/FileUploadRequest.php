<?php

namespace App\Http\Requests;

use App\Enums\FileTypeEnum;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Foundation\Http\FormRequest;
use Elegant\Sanitizer\Laravel\SanitizesInput;

class FileUploadRequest extends FormRequest
{
    use SanitizesInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file',
            'type' => ['sometimes', 'nullable', 'string', new Enum(FileTypeEnum::class)],
            'name' => 'required|string',
            'metadata' => 'sometimes|nullable|array',
        ];
    }

    public function filters(): array
    {
        return [
            'type' => 'trim|escape',
            'name' => 'trim|escape',
            'metadata' => 'trim|escape',
        ];
    }
}
