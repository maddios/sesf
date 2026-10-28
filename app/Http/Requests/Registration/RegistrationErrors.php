<?php

namespace App\Http\Requests\Registration;

use App\Services\Registration\Writer\RegistrationRejected;
use Illuminate\Http\JsonResponse;

/**
 * The legacy per-section error object the registration page reads (EventProfile::
 * validateRegistration and the Angular `response` directives):
 *
 *   {cars: {response: {<key>: [msg]}, status: 'error'},
 *    guest: {<index>: {response: {<field>: [msg]}, status: 'error'}},
 *    shirt | arrival | extras | donation | general: {response: {...}, status: 'error'}}
 *
 * Input keys are request paths (registration.guests.0.first) or "general.<key>".
 */
final class RegistrationErrors
{
    /**
     * @param  array<string, array<array-key, string>|string>  $errors  request key => message(s)
     * @return array<string, mixed>
     */
    public static function sections(array $errors): array
    {
        $out = [];

        foreach ($errors as $key => $messages) {
            $messages = is_array($messages) ? array_values($messages) : [$messages];
            [$section, $index, $field] = self::locate($key);

            if ($index !== null) {
                $out[$section][$index]['status'] = 'error';
                $out[$section][$index]['response'][$field] = array_merge($out[$section][$index]['response'][$field] ?? [], $messages);
            } else {
                $out[$section]['status'] = 'error';
                $out[$section]['response'][$field] = array_merge($out[$section]['response'][$field] ?? [], $messages);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, array<array-key, string>|string>  $errors
     */
    public static function response(array $errors): JsonResponse
    {
        return response()->json(self::sections($errors), 400);
    }

    /**
     * The admin API shape (docs/admin-conventions.md): 422 {message, errors: {key: [msg]}}.
     */
    public static function adminResponse(RegistrationRejected $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'errors' => array_map(fn (string $message): array => [$message], $e->errors()),
        ], 422);
    }

    /**
     * @return array{0: string, 1: string|null, 2: string} section, guest index (or null), inner key
     */
    public static function locate(string $key): array
    {
        if (str_starts_with($key, 'general.')) {
            return ['general', null, substr($key, 8)];
        }

        $path = str_starts_with($key, 'registration.') ? substr($key, 13) : $key;
        $parts = explode('.', $path);
        $head = $parts[0];
        $rest = implode('.', array_slice($parts, 1));

        return match ($head) {
            'cars' => ['cars', null, $rest === '' ? 'cars' : $rest],
            'guests' => count($parts) >= 3
                ? ['guest', $parts[1], implode('.', array_slice($parts, 2))]
                : ['general', null, 'guests'],
            'shirts' => ['shirt', null, $rest === '' ? 'shirts' : $rest],
            'extras', 'num_extras' => ['extras', null, $rest === '' ? $head : $rest],
            'arrival_date' => ['arrival', null, 'arrival_date'],
            'arrival_time' => ['arrival', null, 'arrival_time'],
            'donation' => ['donation', null, 'donation'],
            default => ['general', null, $path],
        };
    }
}
