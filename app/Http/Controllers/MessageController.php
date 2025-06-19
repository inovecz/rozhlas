<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Message;
use App\Http\Requests\ListRequest;
use App\Http\Resources\MessageResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MessageController extends Controller
{
    public function list(ListRequest $request): AnonymousResourceCollection
    {
        $messages = Message::query()->with('contact')
            ->select('messages.*', 'contacts.name', 'contacts.surname')
            ->leftJoin('contacts', 'messages.contact_id', '=', 'contacts.id')
            ->when($request->input('search'), static function ($query, $search) {
                return $query->whereRaw('CONCAT(name, " ", surname) LIKE ?', ['%'.$search.'%'])
                    ->orWhereRaw('CONCAT(surname, " ", name) LIKE ?', ['%'.$search.'%'])
                    ->orWhere('email', 'LIKE', '%'.$search.'%')
                    ->orWhere('phone', 'LIKE', '%'.$search.'%')
                    ->orWhere('content', 'LIKE', '%'.$search.'%');
            })
            ->when($request->input('order'), static function ($query, $order) {
                foreach ($order as $item) {
                    if ($item['column'] === 'contact.fullname') {
                        $query->orderByRaw("surname COLLATE UTF8 {$item['dir']}");
                        $query->orderByRaw("name COLLATE UTF8 {$item['dir']}");
                        continue;
                    }
                    $query->orderByRaw("{$item['column']} COLLATE UTF8 {$item['dir']}");
                }
                return $query;
            })->when($request->input('filter'), static function ($query, $filter) {
                if (!empty($filter) && array_key_exists('type', $filter) && ($filter['type'] !== null)) {
                    $query->where('type', $filter['type']);
                }
                if (!empty($filter) && array_key_exists('state', $filter) && ($filter['state'] !== null)) {
                    $query->where('state', $filter['state']);
                }
                return $query;
                //})->toRawSql();
            })->paginate($request->input('length', 10));

        return MessageResource::collection($messages);
    }
}
