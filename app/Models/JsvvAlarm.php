<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;

class JsvvAlarm extends Model
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

    // <editor-fold desc="Region: BOOT">
    protected static function boot()
    {
        parent::boot();
        static::saving(static function (self $model) {
            if ($model->sequence) {
                $model->setAttribute('sequence_1', $model->sequence[0] ?? null);
                $model->setAttribute('sequence_2', $model->sequence[1] ?? null);
                $model->setAttribute('sequence_3', $model->sequence[2] ?? null);
                $model->setAttribute('sequence_4', $model->sequence[3] ?? null);
                unset($model->sequence);
            }
        });
    }
    // </editor-fold desc="Region: BOOT">

    // <editor-fold desc="Region: RELATIONS">
    public function sequence1(): HasOne
    {
        return $this->hasOne(JsvvAudio::class, 'symbol', 'sequence_1');
    }

    public function sequence2(): HasOne
    {
        return $this->hasOne(JsvvAudio::class, 'symbol', 'sequence_1');
    }

    public function sequence3(): HasOne
    {
        return $this->hasOne(JsvvAudio::class, 'symbol', 'sequence_1');
    }

    public function sequence4(): HasOne
    {
        return $this->hasOne(JsvvAudio::class, 'symbol', 'sequence_1');
    }
    // </editor-fold desc="Region: RELATIONS">

    // <editor-fold desc="Region: GETTERS">
    public function getName(): string
    {
        return $this->name;
    }

    public function getSequence(): ?string
    {
        return $this->sequence_1.$this->sequence_2.$this->sequence_3.$this->sequence_4;
    }

    public function getButton(): ?int
    {
        return $this->button;
    }

    public function getMobileButton(): ?int
    {
        return $this->mobile_button;
    }
    // </editor-fold desc="Region: GETTERS">

    // <editor-fold desc="Region: COMPUTED GETTERS">
    public function getAvailableButtons(bool $mobile = false): array
    {
        $availableButtons = array_merge(
            [($mobile ? $this->getMobileButton() : $this->getButton())],
            array_diff(
                ($mobile ? range(0, 9) : range(1, 8)),
                self::select($mobile ? 'mobile_button' : 'button')->distinct()->pluck($mobile ? 'mobile_button' : 'button')->filter()->values()->toArray()
            )
        );
        sort($availableButtons);
        return $availableButtons;
    }
    // </editor-fold desc="Region: COMPUTED GETTERS">

    // <editor-fold desc="Region: ARRAY GETTERS">
    public function getToArrayDefault(): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'sequence' => $this->getSequence(),
            'button' => $this->getButton(),
            'mobile_button' => $this->getMobileButton(),
            'available_buttons' => $this->getAvailableButtons(),
            'available_mobile_buttons' => $this->getAvailableButtons(true),
        ];
    }
    // </editor-fold desc="Region: ARRAY GETTERS">

    // <editor-fold desc="Region: FUNCTIONS">
    // </editor-fold desc="Region: FUNCTIONS">

    // <editor-fold desc="Region: SCOPES">
    // </editor-fold desc="Region: SCOPES">
}
