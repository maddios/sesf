<?php

namespace Tests\Database\Queue;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

/**
 * The database queue uses its own table (queue_jobs), never the legacy `jobs` table that the old app drains.
 * database/sql/001_queue_tables.sql creates queue_jobs and failed_jobs; the test schema loader applies it.
 */
class QueueTablesTest extends TestCase
{
    use LoadsLegacySchema;

    #[Test]
    public function the_database_queue_uses_queue_jobs_not_the_legacy_jobs_table(): void
    {
        $this->assertSame('queue_jobs', config('queue.connections.database.table'));
        $this->assertNotSame('jobs', config('queue.connections.database.table'));
    }

    #[Test]
    public function the_sql_file_creates_the_queue_and_failed_job_tables(): void
    {
        $this->assertTrue(Schema::hasTable('queue_jobs'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));
    }
}
