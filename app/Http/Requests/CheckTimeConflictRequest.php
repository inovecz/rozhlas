<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Elegant\Sanitizer\Laravel\SanitizesInput;

class CheckTimeConflictRequest extends FormRequest
{
    use SanitizesInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'datetime' => 'required|date_format:Y-m-d H:i',
            'duration' => 'nullable|integer|min:0',
            'schedule_id' => 'nullable|integer|exists:schedules,id',
        ];
    }

    public function filters(): array
    {
        return [
            'datetime' => 'trim',
            'duration' => 'trim|digit',
            'schedule_id' => 'trim|digit',
        ];
    }
}
