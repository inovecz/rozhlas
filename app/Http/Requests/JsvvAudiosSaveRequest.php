<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\JsvvAudioTypeEnum;
use App\Enums\JsvvAudioGroupEnum;
use App\Enums\JsvvAudioSourceEnum;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Foundation\Http\FormRequest;
use Elegant\Sanitizer\Laravel\SanitizesInput;

class JsvvAudiosSaveRequest extends FormRequest
{
    use SanitizesInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            '*.symbol' => 'required|string|size:1|exists:jsvv_audio,symbol',
            '*.name' => 'required|string',
            '*.type' => ['required', 'string', new Enum(JsvvAudioTypeEnum::class)],
            '*.group' => ['required', 'string', new Enum(JsvvAudioGroupEnum::class)],
            '*.source' => ['nullable', 'string', new Enum(JsvvAudioSourceEnum::class)],
            '*.file_id' => 'nullable|integer|exists:files,id',
        ];
    }

    public function filters(): array
    {
        return [
            '*.symbol' => 'trim',
            '*.name' => 'trim',
            '*.type' => 'trim|uppercase',
            '*.group' => 'trim|uppercase',
            '*.source' => 'trim|uppercase',
            '*.file_id' => 'trim|digit',
        ];
    }
}
