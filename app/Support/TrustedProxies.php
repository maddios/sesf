<?php

namespace App\Support;

/**
 * Reads TRUSTED_PROXIES (comma-separated IPs or CIDRs). Empty means no proxy is trusted, which is the
 * default. A bare "*" trusts every caller and must be set on purpose.
 */
final class TrustedProxies
{
    /**
     * @return array<int, string>|string
     */
    public static function fromEnv(?string $raw): array|string
    {
        $value = trim((string) $raw);

        if ($value === '*') {
            return '*';
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn (string $entry): bool => $entry !== '',
        ));
    }
}
