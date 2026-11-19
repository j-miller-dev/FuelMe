<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FoodLog extends Model
{
    public const SLOTS = ['breakfast', 'lunch', 'dinner', 'snack'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'local_date' => 'date:Y-m-d',
            'logged_at' => 'datetime',
            'grams' => 'float', 'kcal' => 'float', 'protein' => 'float', 'carbs' => 'float', 'fat' => 'float', 'fibre' => 'float',
        ];
    }

    /** @return array<string,mixed> the JSON the front end renders */
    public function toPayload(): array
    {
        return [
            'id' => $this->id, 'local_date' => $this->local_date->toDateString(), 'meal_slot' => $this->meal_slot,
            'food_id' => $this->food_id, 'name' => $this->name, 'grams' => $this->grams, 'serving_label' => $this->serving_label,
            'kcal' => $this->kcal, 'protein' => $this->protein, 'carbs' => $this->carbs, 'fat' => $this->fat, 'fibre' => $this->fibre,
            'source' => $this->source,
        ];
    }
}
