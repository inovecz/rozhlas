<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TwoWayCommTypeEnum;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Foundation\Http\FormRequest;
use Elegant\Sanitizer\Laravel\SanitizesInput;

class TwoWayCommSettingsRequest extends FormRequest
{
    use SanitizesInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', new Enum(TwoWayCommTypeEnum::class)],
            'spam' => 'required|boolean',
            'nestStatusAutoUpdate' => 'required|boolean',
            'nestFirstReadTime' => 'nullable|required_if:nestStatusAutoUpdate,true|date_format:H:i',
            'nestNextReadInterval' => 'nullable|required_if:nestStatusAutoUpdate,true|integer|min:1|max:1439',
            'sensorStatusAutoUpdate' => 'required|boolean',
            'sensorFirstReadTime' => 'nullable|required_if:nestStatusAutoUpdate,true|date_format:H:i',
            'sensorNextReadInterval' => 'nullable|required_if:sensorStatusAutoUpdate,true|integer|min:1|max:1439',
        ];
    }

    public function filters(): array
    {
        return [
            'type' => 'trim',
            'spam' => 'trim|cast:bool',
            'nestStatusAutoUpdate' => 'trim|cast:bool',
            'nestFirstReadTime' => 'trim',
            'nestNextReadInterval' => 'trim',
            'sensorStatusAutoUpdate' => 'trim|cast:bool',
            'sensorFirstReadTime' => 'trim',
            'sensorNextReadInterval' => 'trim',
        ];
    }
}
