<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeightLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'local_date' => 'date:Y-m-d',
            'kg' => 'float',
        ];
    }
}
