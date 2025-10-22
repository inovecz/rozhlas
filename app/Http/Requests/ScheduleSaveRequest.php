<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
            'repeat_count' => 'nullable|integer|min:1',
            'repeat_interval_value' => 'nullable|integer|min:1',
            'repeat_interval_unit' => ['nullable', 'string', Rule::in(['minutes', 'hours', 'days', 'weekday', 'months', 'first_weekday_month', 'years'])],
            'repeat_interval_meta' => 'nullable|array',
            'repeat_interval_meta.weekday' => 'nullable|string',
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
            'repeat_count' => 'trim|escape|digit|cast:integer',
            'repeat_interval_value' => 'trim|escape|digit|cast:integer',
            'repeat_interval_unit' => 'trim|escape',
            'repeat_interval_meta.weekday' => 'trim|escape',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $isRepeating = $this->boolean('is_repeating');
            if (!$isRepeating) {
                return;
            }

            if (!$this->filled('repeat_count') || (int) $this->input('repeat_count') < 1) {
                $validator->errors()->add('repeat_count', __('Počet opakování musí být kladné číslo.'));
            }

            $unit = $this->input('repeat_interval_unit');
            $numericUnits = ['minutes', 'hours', 'days', 'months', 'years'];
            if (in_array($unit, $numericUnits, true)) {
                if (!$this->filled('repeat_interval_value') || (int) $this->input('repeat_interval_value') < 1) {
                    $validator->errors()->add('repeat_interval_value', __('Interval mezi opakováními musí být kladné číslo.'));
                }
            }

            $weekdayUnits = ['weekday', 'first_weekday_month'];
            if (in_array($unit, $weekdayUnits, true)) {
                if (!$this->filled('repeat_interval_meta.weekday')) {
                    $validator->errors()->add('repeat_interval_meta.weekday', __('Vyberte den v týdnu.'));
                }
            }
        });
    }
}
