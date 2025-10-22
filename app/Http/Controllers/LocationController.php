<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Location;
use Illuminate\Http\Request;
use App\Models\LocationGroup;
use Illuminate\Http\JsonResponse;
use App\Services\LocationService;
use App\Http\Requests\ListRequest;
use App\Http\Resources\LocationResource;
use App\Http\Requests\LocationsSaveRequest;
use App\Http\Resources\LocationGroupResource;
use App\Http\Requests\LocationGroupSaveRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LocationController extends Controller
{
    public function list(ListRequest $request): AnonymousResourceCollection
    {
        $locations = Location::query()
            ->with(['locationGroup', 'assignedLocationGroups'])
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

    public function listGroups(ListRequest $request): AnonymousResourceCollection
    {
        $locationGroups = LocationGroup::query()
            ->when($request->input('search'), static function ($query, $search) {
                return $query->where('name', 'like', '%'.$search.'%');
            })
            ->when($request->input('order'), static function ($query, $order) {
                foreach ($order as $item) {
                    $query->orderBy($item['column'], $item['dir']);
                }
                return $query;
            })->paginate($request->input('length', 10));

        return LocationGroupResource::collection($locationGroups);
    }

    public function getAllGroups(Request $request): JsonResponse
    {
        $scope = $request->query('scope') ?? 'default';
        $locationGroups = LocationGroup::all()->map(static function (LocationGroup $locationGroup) use ($scope) {
            return $locationGroup->getToArray($scope);
        })->values()->toArray();
        return $this->success($locationGroups);
    }

    public function getLocationGroup(Request $request, LocationGroup $locationGroup): JsonResponse
    {
        return $this->success($locationGroup->getToArray());
    }

    public function saveLocation(LocationsSaveRequest $request): JsonResponse
    {
        $locations = isset($request->all()[0]) ? collect($request->validated()) : collect([$request->validated()]);
        $locations->each(static function ($payload) {
            unset($payload['location_group'], $payload['assigned_location_groups'], $payload['status_label'], $payload['isNew'], $payload['updated'], $payload['hash']);
            $groupIds = collect($payload['location_group_ids'] ?? [])->map('intval')->filter()->values();
            unset($payload['location_group_ids']);

            $components = collect($payload['components'] ?? [])->filter(static fn($item) => is_string($item) && $item !== '')->values();
            $payload['components'] = $components->toArray() ?: null;

            $location = Location::updateOrCreate(['id' => $payload['id'] ?? null], $payload);
            if ($groupIds->isNotEmpty()) {
                $location->assignedLocationGroups()->sync($groupIds);
            } else {
                $location->assignedLocationGroups()->detach();
            }
        });

        return response()->json(['message' => 'Locations saved successfully']);
    }

    public function saveLocationGroup(LocationGroupSaveRequest $request, LocationGroup $locationGroup = null): JsonResponse
    {
        $locationService = new LocationService();
        $locationService->saveLocationGroupPost($request, $locationGroup);
        return $locationService->getResponse();
    }

    public function deleteLocation(Location $location): JsonResponse
    {
        $location->delete();
        return $this->success();
    }

    public function deleteLocationGroup(LocationGroup $locationGroup): JsonResponse
    {
        Location::where('location_group_id', $locationGroup->getId())->update(['location_group_id' => null]);
        $locationGroup->delete();
        return $this->success();
    }
}
