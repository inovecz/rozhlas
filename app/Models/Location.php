<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LocationTypeEnum;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    public function getType(): LocationTypeEnum
    {
        return $this->type;
    }

    public function getLongitude(): double
    {
        return $this->longitude;
    }

    public function getLatitude(): double
    {
        return $this->latitude;
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }
    // </editor-fold desc="Region: GETTERS">

    // <editor-fold desc="Region: COMPUTED GETTERS">
    // </editor-fold desc="Region: COMPUTED GETTERS">

    // <editor-fold desc="Region: ARRAY GETTERS">
    public function getToArrayDefault(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'longitude' => $this->longitude,
            'latitude' => $this->latitude,
            'is_active' => $this->is_active,
        ];
    }
    // </editor-fold desc="Region: ARRAY GETTERS">

    // <editor-fold desc="Region: FUNCTIONS">
    // </editor-fold desc="Region: FUNCTIONS">

    // <editor-fold desc="Region: SCOPES">
    // </editor-fold desc="Region: SCOPES">
}
