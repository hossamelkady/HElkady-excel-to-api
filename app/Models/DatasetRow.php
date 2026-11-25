<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DatasetRow extends Model
{
    public $timestamps = false;

    protected $fillable = ['dataset_id', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
