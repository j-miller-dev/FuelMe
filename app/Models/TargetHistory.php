<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TargetHistory extends Model
{
    protected $table = 'target_history';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'plan_json' => 'array',
        ];
    }
}
