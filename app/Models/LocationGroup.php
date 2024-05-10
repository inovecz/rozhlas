<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class LocationGroup extends Model
{
    // <editor-fold desc="Region: STATE DEFINITION">
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'is_hidden' => 'boolean',
            'subtone_data' => 'array',
            'timing' => 'array',
        ];
    }
    // </editor-fold desc="Region: STATE DEFINITION">

    // <editor-fold desc="Region: RELATIONS">
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
    // </editor-fold desc="Region: RELATIONS">

    // <editor-fold desc="Region: GETTERS">
    public function getName(): string
    {
        return $this->name;
    }

    public function isHidden(): bool
    {
        return $this->is_hidden;
    }

    public function getSubtoneData(): array
    {
        return $this->subtone_data;
    }

    public function getTiming(): ?array
    {
        return $this->timing;
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
            'is_hidden' => $this->isHidden(),
            'subtone_data' => $this->getSubtoneData(),
            'timing' => $this->getTiming(),
            'locations_count' => $this->locations()->count(),
        ];

        if ($this->relationLoaded('locations')) {
            $array['locations'] = $this->locations->map(static function (Location $location) {
                return $location->getToArrayDefault();
            });
        }

        return $array;
    }

    public function getToArraySelect(): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
        ];
    }
    // </editor-fold desc="Region: ARRAY GETTERS">

    // <editor-fold desc="Region: FUNCTIONS">
    // </editor-fold desc="Region: FUNCTIONS">

    // <editor-fold desc="Region: SCOPES">
    // </editor-fold desc="Region: SCOPES">
}
