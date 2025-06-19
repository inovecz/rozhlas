<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contact;
use App\Models\ContactGroup;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\ContactSaveRequest;
use App\Http\Requests\ContactGroupSaveRequest;

class ContactService extends Service
{
    public function saveContactPost(ContactSaveRequest $request, Contact $contact = null): string
    {
        $data = [
            'name' => $request->input('name'),
            'surname' => $request->input('surname'),
            'position' => $request->input('position'),
            'email' => $request->input('email'),
            'has_info_email_allowed' => $request->input('has_info_email_allowed'),
            'phone' => $request->input('phone'),
            'has_info_sms_allowed' => $request->input('has_info_sms_allowed'),
            'contact_groups' => $request->input('contact_groups'),
        ];

        return $this->saveContact($data, $contact);
    }

    /** @param  array{name: string, surname: string, position?: string, email?: string, has_info_email_allowed: bool, phone?: string, has_info_sms_allowed: bool}  $data */
    public function saveContact(array $data, Contact $contact = null): string
    {
        $contactGroups = $data['contact_groups'] ?? [];
        unset($data['contact_groups']);

        if (!$contact) {
            $contact = Contact::create($data);
        } else {
            $contact->update($data);
        }

        $contact->contactGroups()->sync($contactGroups);

        return $this->setStatus('SAVED');
    }

    public function saveContactGroupPost(ContactGroupSaveRequest $request, ContactGroup $contactGroup = null): string
    {
        $data = [
            'name' => $request->input('name'),
        ];

        return $this->saveContactGroup($data, $contactGroup);
    }

    /** @param  array{name: string}  $data */
    public function saveContactGroup(array $data, ContactGroup $contactGroup = null): string
    {
        if (!$contactGroup) {
            ContactGroup::create($data);
        } else {
            $contactGroup->update($data);
        }

        return $this->setStatus('SAVED');
    }

    public function getResponse(): JsonResponse
    {
        return match ($this->getStatus()) {
            'SAVED' => $this->setResponseMessage('response.saved'),
            default => $this->notSpecifiedError(),
        };
    }
}