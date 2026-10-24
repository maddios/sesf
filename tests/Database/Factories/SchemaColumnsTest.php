<?php

use Tests\Support\LegacyTestDatabase;
use Tests\Support\SchemaColumns;

function schemaFromSql(string $body): SchemaColumns
{
    return SchemaColumns::fromSql("CREATE TABLE `t` (\n{$body}\n) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;\n");
}

test('parses the real legacy dump', function () {
    $schema = SchemaColumns::fromFile(base_path(LegacyTestDatabase::SCHEMA_FILE));

    expect($schema->hasTable('shirt_type'))->toBeTrue()
        ->and($schema->columns('shirt_type')['name'])->toMatchArray(['type' => 'varchar', 'length' => 30, 'nullable' => false, 'charset' => 'latin1'])
        ->and($schema->columns('shirt_type')['price'])->toMatchArray(['type' => 'float', 'precision' => 6, 'scale' => 2, 'unsigned' => true])
        ->and($schema->columns('shirt_type')['id']['autoIncrement'])->toBeTrue()
        ->and($schema->hasTable('no_such_table'))->toBeFalse();
});

test('parses column flags, enums, and ignores keys and quoted keywords', function () {
    $schema = schemaFromSql(<<<'SQL'
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `note` varchar(10) DEFAULT NULL COMMENT 'NOT NULL default text',
  `count` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `body` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `status` (`status`)
SQL);

    $columns = $schema->columns('t');

    expect(array_keys($columns))->toBe(['id', 'status', 'note', 'count', 'body'])
        ->and($columns['id'])->toMatchArray(['nullable' => false, 'autoIncrement' => true])
        ->and($columns['status']['enum'])->toBe(['open', 'closed'])
        ->and($columns['note'])->toMatchArray(['nullable' => true, 'hasDefault' => true, 'length' => 10])
        ->and($columns['count'])->toMatchArray(['unsigned' => true, 'length' => null])
        ->and($columns['body'])->toMatchArray(['type' => 'longtext', 'charset' => 'utf8mb4']);
});

test('isRequired is true only for NOT NULL columns without default, auto-increment or generation', function () {
    $schema = schemaFromSql(<<<'SQL'
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `a` varchar(5) NOT NULL,
  `b` varchar(5) NOT NULL DEFAULT 'x',
  `c` varchar(5) DEFAULT NULL,
  `d` varchar(5) GENERATED ALWAYS AS (`a`) VIRTUAL
SQL);

    $required = array_keys(array_filter($schema->columns('t'), SchemaColumns::isRequired(...)));

    expect($required)->toBe(['a']);
});

test('string lengths are checked in characters for utf8 and bytes for latin1', function () {
    $schema = schemaFromSql("  `name` varchar(5) NOT NULL,\n  `u` varchar(5) CHARACTER SET utf8mb4 NOT NULL,\n  `txt` text NOT NULL");
    $name = $schema->columns('t')['name'];
    $utf8 = $schema->columns('t')['u'];

    expect(SchemaColumns::violation($name, 'abcde'))->toBeNull()
        ->and(SchemaColumns::violation($name, 'abcdef'))->toContain('exceeds varchar(5)')
        ->and(SchemaColumns::violation($name, 'é'))->toBeNull()
        ->and(SchemaColumns::violation($name, 'éééééé'))->toContain('exceeds varchar(5)')
        ->and(SchemaColumns::violation($utf8, 'ééééé'))->toBeNull()
        ->and(SchemaColumns::violation($utf8, "\u{1F600}"))->toBeNull()
        ->and(SchemaColumns::violation($schema->columns('t')['txt'], str_repeat('a', 65536)))->toContain('text limit');
});

test('integers respect signed and unsigned ranges and reject non-integers', function () {
    $schema = schemaFromSql("  `s` smallint(6) NOT NULL,\n  `u` tinyint(3) unsigned NOT NULL");
    $s = $schema->columns('t')['s'];
    $u = $schema->columns('t')['u'];

    expect(SchemaColumns::violation($s, 32767))->toBeNull()
        ->and(SchemaColumns::violation($s, '-32768'))->toBeNull()
        ->and(SchemaColumns::violation($s, 32768))->toContain('outside smallint range')
        ->and(SchemaColumns::violation($u, 255))->toBeNull()
        ->and(SchemaColumns::violation($u, -1))->toContain('unsigned')
        ->and(SchemaColumns::violation($u, 'abc'))->toBe('not an integer');
});

test('decimal magnitude follows float(M,D) and unsigned rejects negatives', function () {
    $schema = schemaFromSql('  `price` float(6,2) unsigned NOT NULL');
    $price = $schema->columns('t')['price'];

    expect(SchemaColumns::violation($price, 9999.99))->toBeNull()
        ->and(SchemaColumns::violation($price, '35.00'))->toBeNull()
        ->and(SchemaColumns::violation($price, 10000))->toContain('exceeds float(6,2)')
        ->and(SchemaColumns::violation($price, -0.01))->toContain('negative');
});

test('enum membership and NULL handling', function () {
    $schema = schemaFromSql("  `status` enum('a','b') NOT NULL,\n  `opt` varchar(3) DEFAULT NULL");
    $status = $schema->columns('t')['status'];
    $opt = $schema->columns('t')['opt'];

    expect(SchemaColumns::violation($status, 'a'))->toBeNull()
        ->and(SchemaColumns::violation($status, 'c'))->toContain('not in enum')
        ->and(SchemaColumns::violation($status, null))->toBe('NULL in NOT NULL column')
        ->and(SchemaColumns::violation($opt, null))->toBeNull();
});

test('unknown tables raise a clear error', function () {
    expect(fn () => schemaFromSql('  `a` int NOT NULL')->columns('missing'))
        ->toThrow(RuntimeException::class, 'Table [missing] is not in the schema dump.');
});
