<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\FileSubtypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordingCopyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_file_id' => ['required', 'integer', 'exists:files,id'],
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'subtype' => [
                'required',
                'string',
                Rule::in(array_map(static fn (FileSubtypeEnum $case) => $case->value, FileSubtypeEnum::cases())),
            ],
            'note' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'metadata.duration' => ['nullable', 'numeric', 'min:0'],
            'metadata.source' => ['nullable', 'string'],
            'metadata.note' => ['nullable', 'string'],
        ];
    }
}
