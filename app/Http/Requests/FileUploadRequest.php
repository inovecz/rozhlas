<?php

namespace App\Http\Requests;

use App\Enums\FileTypeEnum;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Foundation\Http\FormRequest;

class FileUploadRequest extends FormRequest
{
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
}
