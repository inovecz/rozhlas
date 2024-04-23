<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Log;
use App\Http\Requests\ListRequest;
use App\Http\Resources\LogResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LogController extends Controller
{
    public function list(ListRequest $request): AnonymousResourceCollection
    {
        $tasks = Log::query()
            ->when($request->input('search'), static function ($query, $search) {
                return $query->where('title', 'like', '%'.$search.'%');
            })
            ->when($request->input('order'), static function ($query, $order) {
                foreach ($order as $item) {
                    $query->orderBy($item['column'], $item['dir']);
                }
                return $query;
            })->when($request->input('filter'), static function ($query, $filter) {
                if (!empty($filter)) {
                    foreach ($filter as $item) {
                        $query->where($item['column'], $item['value']);
                    }
                }
                return $query;
            })->when($request->input('archived') === true, static function ($query) {
                return $query->whereNotNull('processed_at');
            })->when($request->input('archived') === false, static function ($query) {
                return $query->whereNull('processed_at');
            })
            ->paginate($request->input('length', 10));

        return LogResource::collection($tasks);
    }
}
