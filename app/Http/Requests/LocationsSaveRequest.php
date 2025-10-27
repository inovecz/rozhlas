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
                '*.location_group_id' => 'nullable|numeric|exists:location_groups,id',
                '*.name' => 'required|string',
                '*.type' => ['required', 'string', new Enum(LocationTypeEnum::class)],
                '*.longitude' => 'required|numeric',
                '*.latitude' => 'required|numeric',
                '*.is_active' => 'sometimes|boolean',
                '*.modbus_address' => 'nullable|integer|min:0|max:65535',
                '*.bidirectional_address' => 'nullable|integer|min:0|max:65535',
                '*.private_receiver_address' => 'nullable|integer|min:0|max:65535',
                '*.components' => 'nullable|array',
                '*.components.*' => 'string',
                '*.status' => 'nullable|string|in:OK,WARNING,ERROR,UNKNOWN',
                '*.location_group_ids' => 'nullable|array',
                '*.location_group_ids.*' => 'integer|exists:location_groups,id',
            ];
        }
        return [
            'id' => 'nullable|numeric|exists:locations,id',
            'location_group_id' => 'nullable|numeric|exists:location_groups,id',
            'name' => 'required|string',
            'type' => ['required', 'string', new Enum(LocationTypeEnum::class)],
            'longitude' => 'required|numeric',
            'latitude' => 'required|numeric',
            'is_active' => 'sometimes|boolean',
            'modbus_address' => 'nullable|integer|min:0|max:65535',
            'bidirectional_address' => 'nullable|integer|min:0|max:65535',
            'private_receiver_address' => 'nullable|integer|min:0|max:65535',
            'components' => 'nullable|array',
            'components.*' => 'string',
            'status' => 'nullable|string|in:OK,WARNING,ERROR,UNKNOWN',
            'location_group_ids' => 'nullable|array',
            'location_group_ids.*' => 'integer|exists:location_groups,id',
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
                '*.modbus_address' => 'trim|digit',
                '*.bidirectional_address' => 'trim|digit',
                '*.private_receiver_address' => 'trim|digit',
                '*.components.*' => 'trim|escape',
                '*.status' => 'trim|uppercase',
                '*.location_group_ids.*' => 'trim|digit',
            ];
        }
        return [
            'id' => 'trim|escape|digit',
            'name' => 'trim|escape',
            'type' => 'trim|uppercase',
            'longitude' => 'trim|digit',
            'latitude' => 'trim|digit',
            'is_active' => 'trim|cast:boolean',
            'modbus_address' => 'trim|digit',
            'bidirectional_address' => 'trim|digit',
            'private_receiver_address' => 'trim|digit',
            'components.*' => 'trim|escape',
            'status' => 'trim|uppercase',
            'location_group_ids.*' => 'trim|digit',
        ];
    }
}
