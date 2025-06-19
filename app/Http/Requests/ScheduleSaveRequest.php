<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Elegant\Sanitizer\Laravel\SanitizesInput;

class ScheduleSaveRequest extends FormRequest
{
    use SanitizesInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'scheduled_at' => 'required|date',
            'is_repeating' => 'required|boolean',
            'intro_id' => 'nullable|integer|exists:files,id',
            'opening_id' => 'nullable|integer|exists:files,id',
            'common_ids' => 'required|array',
            'common_ids.*' => 'nullable|integer|exists:files,id',
            'outro_id' => 'nullable|integer|exists:files,id',
            'closing_id' => 'nullable|integer|exists:files,id',
        ];
    }

    public function filters(): array
    {
        return [
            'title' => 'trim|escape',
            'scheduled_at' => 'trim|escape',
            'is_repeating' => 'trim|escape|cast:boolean',
            'intro_id' => 'trim|escape|digit|cast:integer',
            'opening_id' => 'trim|escape|digit|cast:integer',
            'common_ids.*' => 'trim|escape|digit|cast:integer',
            'outro_id' => 'trim|escape|digit|cast:integer',
            'closing_id' => 'trim|escape|digit|cast:integer',
        ];
    }
}
