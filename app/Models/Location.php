<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LocationStatusEnum;
use App\Enums\LocationTypeEnum;
use App\Models\LocationGroup;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Location extends Model
{
    use SoftDeletes;

    // <editor-fold desc="Region: STATE DEFINITION">
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'type' => LocationTypeEnum::class,
            'longitude' => 'double',
            'latitude' => 'double',
            'is_active' => 'boolean',
            'modbus_address' => 'integer',
            'bidirectional_address' => 'integer',
            'private_receiver_address' => 'integer',
            'components' => 'array',
            'status' => LocationStatusEnum::class,
        ];
    }
    // </editor-fold desc="Region: STATE DEFINITION">

    // <editor-fold desc="Region: RELATIONS">
    public function locationGroup(): BelongsTo
    {
        return $this->belongsTo(LocationGroup::class);
    }

    public function assignedLocationGroups(): BelongsToMany
    {
        return $this->belongsToMany(LocationGroup::class, 'location_location_group')->withTimestamps();
    }
    // </editor-fold desc="Region: RELATIONS">

    // <editor-fold desc="Region: GETTERS">
    public function getName(): string
    {
        return $this->name;
    }

    public function getLocationGroupId(): ?int
    {
        return $this->location_group_id;
    }

    public function getType(): LocationTypeEnum
    {
        return $this->type;
    }

    public function getLongitude(): float
    {
        return $this->longitude;
    }

    public function getLatitude(): float
    {
        return $this->latitude;
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function getModbusAddress(): ?int
    {
        return $this->modbus_address;
    }

    public function getBidirectionalAddress(): ?int
    {
        return $this->bidirectional_address;
    }

    public function getPrivateReceiverAddress(): ?int
    {
        return $this->private_receiver_address;
    }

    public function getComponents(): array
    {
        return $this->components ?? [];
    }

    public function getStatus(): LocationStatusEnum
    {
        return $this->status ?? LocationStatusEnum::OK;
    }
    // </editor-fold desc="Region: GETTERS">

    // <editor-fold desc="Region: COMPUTED GETTERS">
    // </editor-fold desc="Region: COMPUTED GETTERS">

    // <editor-fold desc="Region: ARRAY GETTERS">
    public function getToArrayDefault(): array
    {
        return [
            'id' => $this->id,
            'location_group_id' => $this->getLocationGroupId(),
            'location_group' => $this->locationGroup?->getToArray('select'),
            'name' => $this->getName(),
            'type' => $this->getType()->value,
            'longitude' => $this->getLongitude(),
            'latitude' => $this->getLatitude(),
            'is_active' => $this->isActive(),
            'modbus_address' => $this->getModbusAddress(),
            'bidirectional_address' => $this->getBidirectionalAddress(),
            'private_receiver_address' => $this->getPrivateReceiverAddress(),
            'components' => $this->getComponents(),
            'status' => $this->getStatus()->value,
            'status_label' => $this->getStatus()->label(),
            'location_group_ids' => $this->assignedLocationGroups->pluck('id')->map('intval')->values(),
            'assigned_location_groups' => $this->assignedLocationGroups->map(static fn(LocationGroup $group) => $group->getToArray('select'))->values(),
        ];
    }
    // </editor-fold desc="Region: ARRAY GETTERS">

    // <editor-fold desc="Region: FUNCTIONS">
    // </editor-fold desc="Region: FUNCTIONS">

    // <editor-fold desc="Region: SCOPES">
    // </editor-fold desc="Region: SCOPES">
}
