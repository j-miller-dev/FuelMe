<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Food extends Model
{
    protected $table = 'foods';

    protected $guarded = [];

    /** Shared reference foods (user_id null) or the user's own. */
    public function visibleTo(int $userId): bool
    {
        return $this->user_id === null || $this->user_id === $userId;
    }
}
