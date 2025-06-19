<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\SubtoneTypeEnum;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Foundation\Http\FormRequest;
use Elegant\Sanitizer\Laravel\SanitizesInput;

class LocationGroupSaveRequest extends FormRequest
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
            'is_hidden' => 'required|boolean',
            'subtone_type' => ['required', 'string', new Enum(SubtoneTypeEnum::class)],
            'subtone_data' => 'required|array',
            'subtone_data.listen' => 'required|array',
            'subtone_data.listen.*' => 'nullable|integer',
            'subtone_data.record' => 'required|array',
            'subtone_data.record.*' => 'nullable|integer',
            'init_audio_id' => 'nullable|integer|exists:files,id',
            'exit_audio_id' => 'nullable|integer|exists:files,id',
            'timing' => 'required|array',
            'timing.*.start' => 'nullable|integer',
            'timing.*.end' => 'nullable|integer',
        ];
    }

    public function filters(): array
    {
        return [
            'name' => 'trim|escape',
            'type' => 'trim|cast:boolean',
            'subtone_type' => 'trim',
            'subtone_data.listen.*' => 'trim|digit|empty_string_to_null',
            'subtone_data.record.*' => 'trim|digit|empty_string_to_null',
            'init_audio_id' => 'trim|digit',
            'exit_audio_id' => 'trim|digit',
            'timing.*.start' => 'trim|digit|empty_string_to_null',
            'timing.*.end' => 'trim|digit|empty_string_to_null',
        ];
    }
}
