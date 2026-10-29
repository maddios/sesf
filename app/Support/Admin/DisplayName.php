<?php

namespace App\Support\Admin;

use App\Models\ShirtType;
use App\Models\User;

/**
 * Display names for admin lists. Missing relations read as blanks, or "Type #id" for a shirt type.
 */
final class DisplayName
{
    public static function person(?User $user): string
    {
        return $user === null ? '' : trim($user->first.' '.$user->last);
    }

    public static function shirtType(?ShirtType $type, int $typeId): string
    {
        return $type === null ? 'Type #'.$typeId : (string) $type->name;
    }
}
