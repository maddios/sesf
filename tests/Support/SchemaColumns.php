<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Column metadata parsed from the CREATE TABLE blocks of the legacy schema dump, plus a check
 * that a PHP value fits a column. Only the DDL subset the dump uses is supported: column
 * definitions, enum/set lists, char/text lengths, integer ranges, decimal/float(M,D) and
 * DEFAULT / NOT NULL / AUTO_INCREMENT / generated-column flags. Keys and constraints are skipped.
 *
 * @phpstan-type Column array{
 *     name: string,
 *     type: string,
 *     unsigned: bool,
 *     nullable: bool,
 *     hasDefault: bool,
 *     autoIncrement: bool,
 *     generated: bool,
 *     length: ?int,
 *     precision: ?int,
 *     scale: ?int,
 *     enum: ?list<string>,
 *     charset: ?string
 * }
 */
final class SchemaColumns
{
    /** Signed and unsigned ranges for the integer types (bigint is limited to PHP_INT_MAX). */
    private const INT_RANGES = [
        'tinyint' => [-128, 127, 255],
        'smallint' => [-32768, 32767, 65535],
        'mediumint' => [-8388608, 8388607, 16777215],
        'int' => [-2147483648, 2147483647, 4294967295],
        'integer' => [-2147483648, 2147483647, 4294967295],
        'bigint' => [PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MAX],
    ];

    /** Byte limits of the TEXT/BLOB family. */
    private const TEXT_BYTES = [
        'tinytext' => 255,
        'text' => 65535,
        'mediumtext' => 16777215,
        'longtext' => 4294967295,
        'tinyblob' => 255,
        'blob' => 65535,
        'mediumblob' => 16777215,
        'longblob' => 4294967295,
    ];

    private const CHAR_TYPES = ['char', 'varchar', 'binary', 'varbinary'];

    private const NUMERIC_TYPES = ['decimal', 'numeric', 'float', 'double'];

    /** @var array<string, array<string, Column>> */
    private array $tables;

    /** @param array<string, array<string, Column>> $tables */
    private function __construct(array $tables)
    {
        $this->tables = $tables;
    }

    public static function fromFile(string $path): self
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException("Cannot read schema dump at [{$path}].");
        }

        return self::fromSql($sql);
    }

    public static function fromSql(string $sql): self
    {
        preg_match_all('/^CREATE TABLE `([^`]+)` \((.*?)^\) ([^;]*);/ms', $sql, $blocks, PREG_SET_ORDER);

        $tables = [];
        foreach ($blocks as $block) {
            [, $table, $body, $options] = $block;

            if (! preg_match('/DEFAULT CHARSET=(\w+)/i', $options, $charset)) {
                throw new RuntimeException("Table [{$table}] has no DEFAULT CHARSET.");
            }

            $columns = [];
            foreach (preg_split('/\R/', $body) ?: [] as $line) {
                $line = rtrim(trim($line), ',');
                if (! str_starts_with($line, '`')) {
                    continue; // PRIMARY KEY, UNIQUE KEY, CONSTRAINT ... lines
                }
                if (! preg_match('/^`([^`]+)`\s+(.*)$/', $line, $match)) {
                    throw new RuntimeException("Cannot parse column line in [{$table}]: {$line}");
                }
                $columns[$match[1]] = self::parseColumn($match[1], $match[2], strtolower($charset[1]));
            }

            $tables[$table] = $columns;
        }

        if ($tables === []) {
            throw new RuntimeException('No CREATE TABLE blocks found in the schema dump.');
        }

        return new self($tables);
    }

    public function hasTable(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    /** @return array<string, Column> */
    public function columns(string $table): array
    {
        if (! $this->hasTable($table)) {
            throw new RuntimeException("Table [{$table}] is not in the schema dump.");
        }

        return $this->tables[$table];
    }

    /**
     * A column the database requires a value for on insert: NOT NULL, no default, not
     * auto-increment, not generated.
     *
     * @param  Column  $column
     */
    public static function isRequired(array $column): bool
    {
        return ! $column['nullable'] && ! $column['hasDefault'] && ! $column['autoIncrement'] && ! $column['generated'];
    }

    /**
     * Returns a description of why $value does not fit $column, or null when it fits (or when
     * the value is a kind this check does not model, such as a date object).
     *
     * @param  Column  $column
     */
    public static function violation(array $column, mixed $value): ?string
    {
        if ($value === null) {
            return self::isRequired($column) ? 'NULL in NOT NULL column' : null;
        }

        if (is_bool($value)) {
            $value = $value ? 1 : 0;
        }

        if ($value instanceof \DateTimeInterface || is_array($value)) {
            return null;
        }

        if (is_object($value) && ! $value instanceof \Stringable) {
            return null;
        }

        $type = $column['type'];

        if ($type === 'enum' || $type === 'set') {
            return self::enumViolation($column, (string) $value);
        }

        if (in_array($type, self::CHAR_TYPES, true) || array_key_exists($type, self::TEXT_BYTES)) {
            return self::stringViolation($column, (string) $value);
        }

        if (array_key_exists($type, self::INT_RANGES)) {
            return self::integerViolation($column, $value);
        }

        if (in_array($type, self::NUMERIC_TYPES, true)) {
            return self::decimalViolation($column, $value);
        }

        return null;
    }

    /** @return Column */
    private static function parseColumn(string $name, string $definition, string $tableCharset): array
    {
        $enum = null;

        if (preg_match('/^(enum|set)\((.*?)\)(?=\s|$)/i', $definition, $match)) {
            $type = strtolower($match[1]);
            preg_match_all("/'((?:[^']|'')*)'/", $match[2], $values);
            $enum = array_map(static fn (string $v): string => str_replace("''", "'", $v), $values[1]);
            $rest = substr($definition, strlen($match[0]));
            $args = [];
        } elseif (preg_match('/^(\w+)(?:\(([\d,\s]+)\))?/', $definition, $match)) {
            $type = strtolower($match[1]);
            $args = isset($match[2]) ? array_map('intval', explode(',', $match[2])) : [];
            $rest = substr($definition, strlen($match[0]));
        } else {
            throw new RuntimeException("Cannot parse column [{$name}]: {$definition}");
        }

        // Quoted literals (COMMENT, DEFAULT strings) must not look like keywords.
        $code = strtoupper((string) preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/", "''", $rest));

        $charset = null;
        if (preg_match('/CHARACTER SET (\w+)/', $code, $cs)) {
            $charset = strtolower($cs[1]);
        } elseif (in_array($type, self::CHAR_TYPES, true) || in_array($type, ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'enum', 'set'], true)) {
            $charset = $tableCharset;
        }

        $isNumeric = in_array($type, self::NUMERIC_TYPES, true);

        return [
            'name' => $name,
            'type' => $type,
            'unsigned' => (bool) preg_match('/\bUNSIGNED\b/', $code),
            'nullable' => ! str_contains($code, 'NOT NULL'),
            'hasDefault' => (bool) preg_match('/\bDEFAULT\b/', $code),
            'autoIncrement' => str_contains($code, 'AUTO_INCREMENT'),
            'generated' => (bool) preg_match('/GENERATED ALWAYS|\bAS \(/', $code),
            'length' => in_array($type, self::CHAR_TYPES, true) ? ($args[0] ?? null) : null,
            'precision' => $isNumeric ? ($args[0] ?? null) : null,
            'scale' => $isNumeric ? ($args[1] ?? 0) : null,
            'enum' => $enum,
            'charset' => $charset,
        ];
    }

    /** @param Column $column */
    private static function enumViolation(array $column, string $value): ?string
    {
        $allowed = $column['enum'] ?? [];
        $parts = $column['type'] === 'set' ? explode(',', $value) : [$value];

        foreach ($parts as $part) {
            if (! in_array($part, $allowed, true)) {
                return 'value not in '.$column['type'].'('.implode(',', $allowed).')';
            }
        }

        return null;
    }

    /** @param Column $column */
    private static function stringViolation(array $column, string $value): ?string
    {
        $type = $column['type'];

        if (array_key_exists($type, self::TEXT_BYTES)) {
            $bytes = strlen($value);

            return $bytes > self::TEXT_BYTES[$type] ? "{$bytes} bytes exceeds {$type} limit ".self::TEXT_BYTES[$type] : null;
        }

        // latin1 stores one byte per character, so non-ASCII UTF-8 input is measured in bytes.
        // utf8 (utf8mb3) cannot store 4-byte characters at all.
        $charset = $column['charset'] ?? 'latin1';
        if (in_array($charset, ['latin1', 'ascii', 'binary'], true)) {
            $length = strlen($value);
        } else {
            if ($charset === 'utf8' && preg_match('/[\x{10000}-\x{10FFFF}]/u', $value)) {
                return "4-byte character not storable in {$charset}";
            }
            $length = mb_strlen($value, 'UTF-8');
        }

        $max = (int) $column['length'];

        return $length > $max ? "length {$length} exceeds {$type}({$max})" : null;
    }

    /** @param Column $column */
    private static function integerViolation(array $column, mixed $value): ?string
    {
        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value)) {
            return 'not an integer';
        }

        [$signedMin, $signedMax, $unsignedMax] = self::INT_RANGES[$column['type']];

        if ($column['unsigned']) {
            return $value < 0 || $value > $unsignedMax ? "{$value} outside unsigned {$column['type']} range" : null;
        }

        return $value < $signedMin || $value > $signedMax ? "{$value} outside {$column['type']} range" : null;
    }

    /** @param Column $column */
    private static function decimalViolation(array $column, mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return 'not numeric';
        }

        $number = (float) $value;

        if ($column['unsigned'] && $number < 0) {
            return "{$value} is negative in an unsigned column";
        }

        if ($column['precision'] !== null) {
            $scale = (int) $column['scale'];
            $max = 10 ** ($column['precision'] - $scale) - 10 ** -$scale;

            if (abs($number) > $max + 1e-9) {
                return "{$value} exceeds {$column['type']}({$column['precision']},{$scale}) magnitude ".$max;
            }
        }

        return null;
    }
}
