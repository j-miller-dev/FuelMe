<?php

namespace App\Support;

use App\Models\User;

/** Single-user app: registration is open only until the first account exists (and only if enabled). */
final class Registration
{
    public static function open(): bool
    {
        return (bool) config('fuel.registration_enabled') && ! User::query()->exists();
    }
}
