<?php

declare(strict_types=1);

namespace App\Models;

class Contact extends Model
{
    // <editor-fold desc="Region: STATE DEFINITION">
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            //
        ];
    }
    // </editor-fold desc="Region: STATE DEFINITION">

    // <editor-fold desc="Region: RELATIONS">
    // </editor-fold desc="Region: RELATIONS">

    // <editor-fold desc="Region: GETTERS">
    public function getName(): string
    {
        return $this->name;
    }

    public function getSurname(): string
    {
        return $this->surname;
    }

    public function getPosition(): ?string
    {
        return $this->position;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }
    // </editor-fold desc="Region: GETTERS">

    // <editor-fold desc="Region: COMPUTED GETTERS">
    // </editor-fold desc="Region: COMPUTED GETTERS">

    // <editor-fold desc="Region: ARRAY GETTERS">
    public function getToArrayDefault(): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'surname' => $this->getSurname(),
            'position' => $this->getPosition(),
            'email' => $this->getEmail(),
            'phone' => $this->getPhone(),
        ];
    }
    // </editor-fold desc="Region: ARRAY GETTERS">

    // <editor-fold desc="Region: FUNCTIONS">
    // </editor-fold desc="Region: FUNCTIONS">

    // <editor-fold desc="Region: SCOPES">
    // </editor-fold desc="Region: SCOPES">
}
