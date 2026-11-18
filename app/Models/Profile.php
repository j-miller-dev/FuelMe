<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Profile extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'dob' => 'date:Y-m-d',
            'height_cm' => 'float',
            'body_fat_pct' => 'float',
            'protein_g_per_kg_override' => 'float',
            'rollover_hour' => 'integer',
            'disclaimer_ack_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
