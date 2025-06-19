<?php

namespace App\Http\Requests;

use App\Enums\FileTypeEnum;
use App\Enums\FileSubtypeEnum;
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
            'subtype' => ['sometimes', 'nullable', 'string', new Enum(FileSubtypeEnum::class)],
            'name' => 'required|string',
            'extension' => 'nullable|string',
            'metadata' => 'sometimes|nullable|array',
        ];
    }

    public function filters(): array
    {
        return [
            'type' => 'trim|escape',
            'subtype' => 'trim|escape',
            'name' => 'trim|escape',
            'extension' => 'trim|escape',
            'metadata' => 'trim|escape',
        ];
    }
}
