<?php

namespace App\Support\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The required show_id query parameter of the per-event admin reports and waiver lists.
 * An unknown or non-numeric id is a 422 on show_id.
 */
final class ShowParam
{
    public static function required(Request $request): int
    {
        $validator = Validator::make($request->query(), [
            'show_id' => ['required', 'integer', 'min:1', 'exists:car_show,id'],
        ]);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return (int) $request->query('show_id');
    }
}
