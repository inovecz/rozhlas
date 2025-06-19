<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\UserSaveRequest;
use Illuminate\Database\Eloquent\Builder;

class UserService extends Service
{
    public function savePost(UserSaveRequest $request, User $user = null): string
    {
        $username = $request->input('username');

        if (User::where('username', $username)->when($user, fn(Builder $builder) => $builder->whereNot('id', $user->id))->exists()) {
            return $this->setStatus('USERNAME_EXISTS');
        }

        $data = [
            'username' => $username,
        ];

        if ($request->input('password')) {
            $data['password'] = $request->input('password');
        }

        return $this->save($data, $user);
    }

    /** @param  array{username: string, password?: string}  $data */
    public function save(array $data, User $user = null): string
    {
        if (!$user) {
            User::create($data);
        } else {
            $user->update($data);
        }

        return $this->setStatus('SAVED');
    }

    public function getResponse(): JsonResponse
    {
        return match ($this->getStatus()) {
            'SAVED' => $this->setResponseMessage('response.saved'),
            'USERNAME_EXISTS' => $this->setResponseMessage('response.username_exists', 400),
            default => $this->notSpecifiedError(),
        };
    }
}