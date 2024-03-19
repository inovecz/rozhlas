<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

abstract class Controller
{
    public function unauthorized(Collection|array|string|null $data = null): JsonResponse
    {
        if (is_string($data)) {
            $data = ['message' => $data];
        }
        if ($data instanceof Collection) {
            $data = $data->toArray();
        }
        return response()->json($data ?? ['message' => 'auth.token_expired'], 401);
    }

    public function permissionDenied(Collection|array|string|null $data = null): JsonResponse
    {
        if (is_string($data)) {
            $data = ['message' => $data];
        }
        if ($data instanceof Collection) {
            $data = $data->toArray();
        }
        return response()->json($data ?? ['message' => 'global.permission_denied'], 403);
    }

    public function success(Collection|array|string|null $data = null): JsonResponse
    {
        if (is_string($data)) {
            $data = ['message' => $data];
        }
        if ($data instanceof Collection) {
            $data = $data->toArray();
        }
        return response()->json($data ?? ['message' => 'OK']);
    }

    public function error(Collection|array|string|null $data = null): JsonResponse
    {
        if (is_string($data)) {
            $data = ['message' => $data];
        }
        if ($data instanceof Collection) {
            $data = $data->toArray();
        }
        return response()->json($data ?? ['message' => 'global.not_specified_error'], 400);
    }

    public function notFound(Collection|array|string|null $data = null): JsonResponse
    {
        if (is_string($data)) {
            $data = ['message' => $data];
        }
        if ($data instanceof Collection) {
            $data = $data->toArray();
        }
        return response()->json($data ?? ['message' => 'global.not_found'], 404);
    }
}
