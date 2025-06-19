<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\ListRequest;
use App\Http\Resources\UserResource;
use App\Http\Requests\UserSaveRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function list(ListRequest $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->when($request->input('search'), static function ($query, $search) {
                return $query->where('username', 'like', '%'.$search.'%');
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
            })->paginate($request->input('length', 10));

        return UserResource::collection($users);
    }

    public function save(UserSaveRequest $request, User $user): JsonResponse
    {
        $userService = new UserService();
        $userService->savePost($request, $user);
        return $userService->getResponse();
    }

    public function delete(User $user): JsonResponse
    {
        $user->delete();
        return $this->success();
    }
}
