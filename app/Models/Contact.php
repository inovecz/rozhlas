<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Contact extends Model
{
    use HasFactory;

    // <editor-fold desc="Region: STATE DEFINITION">
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'has_info_email_allowed' => 'boolean',
            'has_info_sms_allowed' => 'boolean',
        ];
    }
    // </editor-fold desc="Region: STATE DEFINITION">

    // <editor-fold desc="Region: RELATIONS">
    public function contactGroups(): BelongsToMany
    {
        return $this->belongsToMany(ContactGroup::class);
    }
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

    public function hasInfoEmailAllowed(): bool
    {
        return $this->has_info_email_allowed;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function hasInfoSmsAllowed(): bool
    {
        return $this->has_info_sms_allowed;
    }
    // </editor-fold desc="Region: GETTERS">

    // <editor-fold desc="Region: COMPUTED GETTERS">
    // </editor-fold desc="Region: COMPUTED GETTERS">

    // <editor-fold desc="Region: ARRAY GETTERS">
    public function getToArrayDefault(): array
    {
        $array = [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'surname' => $this->getSurname(),
            'position' => $this->getPosition(),
            'email' => $this->getEmail(),
            'has_info_email_allowed' => $this->hasInfoEmailAllowed(),
            'phone' => $this->getPhone(),
            'has_info_sms_allowed' => $this->hasInfoSmsAllowed(),
            'created_at' => $this->getCreatedAt(),
        ];

        if ($this->relationLoaded('contactGroups')) {
            $array['contact_groups'] = $this->contactGroups->map(static function (ContactGroup $contactGroup) {
                return $contactGroup->getToArrayDefault();
            });
        }

        return $array;
    }
    // </editor-fold desc="Region: ARRAY GETTERS">

    // <editor-fold desc="Region: FUNCTIONS">
    // </editor-fold desc="Region: FUNCTIONS">

    // <editor-fold desc="Region: SCOPES">
    // </editor-fold desc="Region: SCOPES">
}
