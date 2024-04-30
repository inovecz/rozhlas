<?php

namespace App\Models;

use App\Models\Traits\ArrayableTrait;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, ArrayableTrait;

    // <editor-fold desc="Region: STATE DEFINITION">
    protected $guarded = ['id', 'created_at', 'updated_at'];
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
    // </editor-fold desc="Region: STATE DEFINITION">

    // <editor-fold desc="Region: GETTERS">
    public function getUsername(): string
    {
        return $this->username;
    }
    // </editor-fold desc="Region: GETTERS">

    // <editor-fold desc="Region: ARRAY GETTERS">
    public function getToArrayDefault(): array
    {
        return [
            'id' => $this->getId(),
            'username' => $this->getUsername(),
            'created_at' => $this->created_at->toDateTimeString(),
        ];
    }
    // </editor-fold desc="Region: ARRAY GETTERS">

    // <editor-fold desc="Region: JWT">
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'user_id' => $this->getId(),
            'username' => $this->getUsername(),
        ];
    }
    // </editor-fold desc="Region: JWT">
}
