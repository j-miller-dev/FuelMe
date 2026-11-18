<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Goal extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rate_pct_per_week' => 'float',
            'start_date' => 'date:Y-m-d',
            'event_date' => 'date:Y-m-d',
        ];
    }
}
