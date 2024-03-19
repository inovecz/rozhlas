<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        if (!Auth::attempt($request->only('username', 'password'))) {
            return $this->error('Invalid credentials');
        }
        return $this->success([
            'user' => Auth::user()?->only('id', 'username'),
        ]);
    }

    public function logout(): JsonResponse
    {
        Auth::logout();
        return $this->success('Logged out successfully');
    }
}
