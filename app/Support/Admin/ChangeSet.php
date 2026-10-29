<?php

namespace App\Support\Admin;

/**
 * Field changes between two raw attribute snapshots, for AuditLog::record(). Sensitive fields are
 * logged by name only (AuditLog::isSensitive).
 */
final class ChangeSet
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{old: mixed, new: mixed}|string>
     */
    public static function between(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $field => $new) {
            $old = $before[$field] ?? null;
            if ($old === $new) {
                continue;
            }
            $changes[$field] = AuditLog::isSensitive((string) $field)
                ? AuditLog::REDACTED
                : ['old' => $old, 'new' => $new];
        }

        return $changes;
    }
}
