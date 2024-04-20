<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Location;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\ListRequest;
use App\Http\Resources\LocationResource;
use App\Http\Requests\LocationsSaveRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LocationController extends Controller
{
    public function list(ListRequest $request): AnonymousResourceCollection
    {
        $locations = Location::query()
            ->when($request->input('search'), static function ($query, $search) {
                return $query->where('name', 'like', '%'.$search.'%');
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
            });
        if ($request->input('paginate') === true) {
            $locations = $locations->paginate($request->input('length', 10));
        } else {
            $locations = $locations->get();
        }

        return LocationResource::collection($locations);
    }

    public function save(LocationsSaveRequest $request): JsonResponse
    {
        $locations = isset($request->all()[0]) ? collect($request->validated()) : collect([$request->validated()]);
        $locations->each(static function ($location) {
            Location::updateOrCreate(['id' => $location['id'] ?? null], $location);
        });

        return response()->json(['message' => 'Locations saved successfully']);
    }

    public function delete(Location $location): JsonResponse
    {
        $location->delete();
        return $this->success();
    }
}
