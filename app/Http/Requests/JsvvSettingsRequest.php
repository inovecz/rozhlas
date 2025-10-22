<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Elegant\Sanitizer\Laravel\SanitizesInput;

class JsvvSettingsRequest extends FormRequest
{
    use SanitizesInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'locationGroupId' => 'sometimes|nullable|integer|exists:location_groups,id',
            'allowSms' => 'sometimes|required|boolean',
            'smsContacts' => 'sometimes|required_if:allowSms,true|array',
            'smsMessage' => 'sometimes|required_if:allowSms,true|string',
            'allowAlarmSms' => 'sometimes|required|boolean',
            'alarmSmsContacts' => 'sometimes|required_if:allowAlarmSms,true|array',
            'alarmSmsMessage' => 'sometimes|required_if:allowAlarmSms,true|string',
            'allowEmail' => 'sometimes|required|boolean',
            'emailContacts' => 'sometimes|required_if:allowEmail,true|array',
            'emailContacts.*' => 'email',
            'emailSubject' => 'sometimes|nullable|required_if:allowEmail,true|string',
            'emailMessage' => 'sometimes|nullable|required_if:allowEmail,true|string',
        ];
    }

    public function filters(): array
    {
        return [
            'locationGroupId' => 'trim|digit|empty_string_to_null',
            'allowSms' => 'trim|cast:boolean',
            'smsContacts' => 'trim',
            'smsContacts.*' => 'trim',
            'smsMessage' => 'trim',
            'allowAlarmSms' => 'trim|cast:boolean',
            'alarmSmsContacts' => 'trim',
            'alarmSmsContacts.*' => 'trim',
            'alarmSmsMessage' => 'trim',
            'allowEmail' => 'trim|cast:boolean',
            'emailContacts' => 'trim',
            'emailContacts.*' => 'trim',
            'emailSubject' => 'trim',
            'emailMessage' => 'trim',
        ];
    }
}
