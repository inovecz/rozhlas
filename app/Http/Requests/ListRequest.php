<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => 'sometimes|numeric',
            'length' => 'sometimes|numeric',
            'search' => 'sometimes|nullable',
            'order' => 'sometimes|array',
            'order.*.column' => 'required_with:order|string',
            'order.*.dir' => 'required_with:order|string|in:asc,desc',
            'filter' => 'sometimes|array',
        ];
    }
}
