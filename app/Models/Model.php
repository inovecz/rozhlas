<?php

namespace App\Models;

use Carbon\Carbon;

class Model extends \Illuminate\Database\Eloquent\Model
{
    public function getId(): int
    {
        $primaryKey = $this->primaryKey;
        return $this->$primaryKey;
    }

    public function getCreatedAt(): Carbon
    {
        return $this->created_at;
    }

    public function getUpdatedAt(): Carbon
    {
        return $this->updated_at;
    }

    public function getDeletedAt(): ?Carbon
    {
        return method_exists($this, 'trashed') ? $this->deleted_at : null;
    }
}