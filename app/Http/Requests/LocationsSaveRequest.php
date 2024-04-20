<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\LocationTypeEnum;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Foundation\Http\FormRequest;
use Elegant\Sanitizer\Laravel\SanitizesInput;

class LocationsSaveRequest extends FormRequest
{
    use SanitizesInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isArray = isset($this->request->all()[0]);
        if ($isArray) {
            return [
                '*.id' => 'nullable|numeric|exists:locations,id',
                '*.name' => 'required|string',
                '*.type' => ['required', 'string', new Enum(LocationTypeEnum::class)],
                '*.longitude' => 'required|numeric',
                '*.latitude' => 'required|numeric',
                '*.is_active' => 'sometimes|boolean',
            ];
        }
        return [
            'id' => 'nullable|numeric|exists:locations,id',
            'name' => 'required|string',
            'type' => ['required', 'string', new Enum(LocationTypeEnum::class)],
            'longitude' => 'required|numeric',
            'latitude' => 'required|numeric',
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function filters(): array
    {
        $isArray = isset($this->request->all()[0]);
        if ($isArray) {
            return [
                '*.id' => 'trim|escape|digit',
                '*.name' => 'trim|escape',
                '*.type' => 'trim|uppercase',
                '*.longitude' => 'trim|digit',
                '*.latitude' => 'trim|digit',
                '*.is_active' => 'trim|cast:boolean',
            ];
        }
        return [
            'id' => 'trim|escape|digit',
            'name' => 'trim|escape',
            'type' => 'trim|uppercase',
            'longitude' => 'trim|digit',
            'latitude' => 'trim|digit',
            'is_active' => 'trim|cast:boolean',
        ];
    }
}
