<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Elegant\Sanitizer\Laravel\SanitizesInput;

class ListRequest extends FormRequest
{
    use SanitizesInput;

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

    public function filters(): array
    {
        return [
            'page' => 'trim|escape',
            'length' => 'trim|escape',
            'search' => 'trim|escape',
            'order' => 'trim|escape',
            'filter' => 'trim|escape',
            'deleted' => 'trim|escape',
        ];
    }
}
