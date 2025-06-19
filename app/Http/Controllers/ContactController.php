<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ContactGroup;
use Illuminate\Http\Request;
use App\Services\ContactService;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\ListRequest;
use App\Http\Resources\ContactResource;
use App\Http\Requests\ContactSaveRequest;
use App\Http\Resources\ContactGroupResource;
use App\Http\Requests\ContactGroupSaveRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ContactController extends Controller
{
    public function list(ListRequest $request): AnonymousResourceCollection
    {
        $users = Contact::query()->with('contactGroups')
            ->when($request->input('search'), static function ($query, $search) {
                return $query->whereRaw('CONCAT(name, " ", surname) LIKE ?', ['%'.$search.'%'])
                    ->orWhereRaw('CONCAT(surname, " ", name) LIKE ?', ['%'.$search.'%'])
                    ->orWhere('email', 'LIKE', '%'.$search.'%')
                    ->orWhere('phone', 'LIKE', '%'.$search.'%');
            })
            ->when($request->input('order'), static function ($query, $order) {
                foreach ($order as $item) {
                    $query->orderBy($item['column'], $item['dir']);
                }
                return $query;
            })->when($request->input('filter'), static function ($query, $filter) {
                if (!empty($filter) && array_key_exists('contact_group', $filter) && ($filter['contact_group'] !== null)) {
                    $query->whereHas('contactGroups', static function ($query) use ($filter) {
                        $query->where('contact_group_id', $filter['contact_group']);
                    });
                }
                return $query;
            })->paginate($request->input('length', 10));

        return ContactResource::collection($users);
    }

    public function getAllContacts(Request $request): JsonResponse
    {
        $scope = $request->query('scope') ?? 'default';
        $contacts = Contact::all()->map(static function (Contact $contact) use ($scope) {
            return $contact->getToArray($scope);
        })->values()->toArray();
        return $this->success($contacts);
    }

    public function listGroups(ListRequest $request): AnonymousResourceCollection
    {
        $users = ContactGroup::query()->with('contacts')
            ->when($request->input('search'), static function ($query, $search) {
                return $query->where('name', 'LIKE', '%'.$search.'%');
            })
            ->when($request->input('order'), static function ($query, $order) {
                foreach ($order as $item) {
                    $query->orderBy($item['column'], $item['dir']);
                }
                return $query;
            })->paginate($request->input('length', 10));

        return ContactGroupResource::collection($users);
    }

    public function getAllGroups(Request $request): JsonResponse
    {
        $scope = $request->query('scope') ?? 'default';
        $contactGroups = ContactGroup::all()->map(static function (ContactGroup $contactGroup) use ($scope) {
            return $contactGroup->getToArray($scope);
        })->values()->toArray();
        return $this->success($contactGroups);
    }

    public function saveContact(ContactSaveRequest $request, Contact $contact = null): JsonResponse
    {
        $contactService = new ContactService();
        $contactService->saveContactPost($request, $contact);
        return $contactService->getResponse();
    }

    public function saveContactGroup(ContactGroupSaveRequest $request, ContactGroup $contactGroup = null): JsonResponse
    {
        $contactService = new ContactService();
        $contactService->saveContactGroupPost($request, $contactGroup);
        return $contactService->getResponse();
    }

    public function deleteContact(Contact $contact): JsonResponse
    {
        $contact->delete();
        return $this->success();
    }

    public function deleteContactGroup(ContactGroup $contactGroup): JsonResponse
    {
        $contactGroup->contacts()->detach();
        $contactGroup->delete();
        return $this->success();
    }
}
