<?php

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Support\LegacyTestDatabase;
use Tests\Support\SchemaColumns;

/*
 * Every factory in database/factories is built against the legacy DDL. Definitions that create
 * parent rows (nested factories or closures that call create()) are run with create() 25 times;
 * the rest with make() 200 times. The transaction from LoadsLegacySchema rolls everything back.
 */

test('every factory builds attributes that fit the legacy schema', function () {
    $schema = SchemaColumns::fromFile(base_path(LegacyTestDatabase::SCHEMA_FILE));
    $connection = DB::connection(LegacyTestDatabase::CONNECTION);
    $root = base_path('database/factories');

    $factoryClasses = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! str_ends_with($file->getFilename(), 'Factory.php')) {
            continue;
        }
        $relative = Str::after($file->getPathname(), $root.DIRECTORY_SEPARATOR);
        $factoryClasses[] = 'Database\\Factories\\'.str_replace(['/', '.php'], ['\\', ''], $relative);
    }
    sort($factoryClasses);

    expect($factoryClasses)->not->toBeEmpty();

    $violations = [];
    $checked = [];

    foreach ($factoryClasses as $factoryClass) {
        $short = Str::afterLast($factoryClass, '\\');
        $checked[] = $factoryClass;

        // Laravel resolves the model from $model or from the factory name; modelName() does both.
        $factory = $factoryClass::new();
        /** @var class-string<Model> $modelClass */
        $modelClass = (new ReflectionMethod($factoryClass, 'modelName'))->invoke($factory);
        $table = (new $modelClass)->getTable();

        // Model::factory() must resolve back to this class, or the factory is orphaned.
        $resolved = in_array(HasFactory::class, class_uses_recursive($modelClass), true)
            ? (new ReflectionMethod($modelClass, 'factory'))->invoke(null)
            : null;

        if (! $resolved instanceof $factoryClass) {
            $violations[] = "{$short}: {$modelClass}::factory() does not resolve to this factory";

            continue;
        }

        $rows = [];
        $mode = 'make x200';

        try {
            // Detection pass: does one make() insert parent rows?
            $connection->flushQueryLog();
            $connection->enableQueryLog();
            $factory->make();
            $inserted = array_filter(
                $connection->getQueryLog(),
                static fn (array $query): bool => stripos(ltrim($query['query']), 'insert') === 0,
            );
            $connection->disableQueryLog();

            if ($inserted !== []) {
                $mode = 'create x25';
                for ($i = 0; $i < 25; $i++) {
                    $rows[] = $factory->create()->getAttributes();
                }
            } else {
                for ($i = 0; $i < 200; $i++) {
                    $rows[] = $factory->make()->getAttributes();
                }
            }
        } catch (LogicException $e) {
            // CarBodyFactory: lookup rows come from the fixture, so check those instead.
            $mode = 'fixture rows';
            $rows = $modelClass::query()->get()->map(fn (Model $m): array => $m->getAttributes())->all();
        } catch (Throwable $e) {
            $violations[] = "{$short}: building rows failed: ".Str::limit($e->getMessage(), 200);

            continue;
        } finally {
            $connection->disableQueryLog();
        }

        if (! $schema->hasTable($table)) {
            $violations[] = "{$short}: table [{$table}] is not in the schema dump";

            continue;
        }

        $columns = $schema->columns($table);
        $found = [];

        foreach ($rows as $row) {
            foreach ($columns as $name => $column) {
                if (SchemaColumns::isRequired($column) && ! array_key_exists($name, $row)) {
                    $found[$name]['missing'] = ($found[$name]['missing'] ?? 0) + 1;
                }
            }

            foreach ($row as $name => $value) {
                if (! isset($columns[$name])) {
                    $found[$name]['unknown column'] = ($found[$name]['unknown column'] ?? 0) + 1;

                    continue;
                }

                $problem = SchemaColumns::violation($columns[$name], $value);
                if ($problem !== null) {
                    $found[$name][$problem] = ($found[$name][$problem] ?? 0) + 1;
                    $found[$name]['example'] ??= Str::limit(var_export($value, true), 60);
                }
            }
        }

        foreach ($found as $name => $problems) {
            $example = $problems['example'] ?? null;
            unset($problems['example']);
            foreach ($problems as $problem => $count) {
                $suffix = $example !== null ? " e.g. {$example}" : '';
                $violations[] = "{$short}.{$name} [{$table}]: {$problem} ({$count} of ".count($rows)." rows, {$mode}){$suffix}";
            }
        }
    }

    Assert::assertSame(
        count($factoryClasses),
        count($checked),
        'Every discovered factory must be checked.',
    );

    Assert::assertEmpty(
        $violations,
        'Factory values do not fit the legacy schema ('.count($factoryClasses)." factories checked):\n".implode("\n", $violations),
    );
});
