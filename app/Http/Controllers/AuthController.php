<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        if (!$token = Auth::attempt($request->only('username', 'password'))) {
            return $this->unauthorized();
        }
        return $this->respondWithToken($token);
    }

    public function me(): JsonResponse
    {
        return $this->success(Auth::user());
    }

    public function logout(): JsonResponse
    {
        Auth::logout();
        return $this->success('Logged out successfully');
    }

    public function refresh()
    {
        return $this->respondWithToken(Auth::refresh());
    }

    protected function respondWithToken(string $token): JsonResponse
    {
        return $this->success([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => Auth::factory()->getTTL() * 60,
        ]);
    }
}
