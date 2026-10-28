<?php

namespace Tests\Database\Api\Registration\Write;

use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

/**
 * An administrator writing a member's registration or extras through the member endpoints (user_id) is audited;
 * the member's own writes are not.
 */
class OnBehalfAuditTest extends WriteTestCase
{
    private string $auditFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditFile = tempnam(sys_get_temp_dir(), 'sesf-audit-').'.log';
        config(['logging.channels.audit.driver' => 'single', 'logging.channels.audit.path' => $this->auditFile]);
        Log::forgetChannel('audit');

        $this->beforeApplicationDestroyed(function (): void {
            Log::forgetChannel('audit');
            @unlink($this->auditFile);
        });
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @phpstan-impure
     */
    private function auditEntries(string $action): array
    {
        $lines = is_file($this->auditFile) ? (file($this->auditFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        $entries = array_map(fn (string $line): array => (array) json_decode($line, true)['context'], $lines);

        return array_values(array_filter($entries, fn (array $e): bool => ($e['action'] ?? null) === $action));
    }

    #[Test]
    public function an_admin_saving_a_members_registration_is_audited(): void
    {
        $show = $this->show();
        $admin = $this->admin();
        $user = $this->member();
        $car = $this->car($user);

        $this->save($user, $show, $this->body($show, [$car->id]))->assertOk();
        $this->assertSame([], $this->auditEntries('registration.save_on_behalf'));

        $this->save($admin, $show, $this->body($show, [$car->id]), $user->id)->assertOk();

        $entries = $this->auditEntries('registration.save_on_behalf');
        $this->assertCount(1, $entries);
        $this->assertSame($admin->id, $entries[0]['user_id']);
        $this->assertSame([$this->storedRegistration($show, $user)?->id], $entries[0]['ids']);
        $this->assertSame($user->id, $entries[0]['extra']['user_id']);
        $this->assertSame($show->id, $entries[0]['extra']['show_id']);
    }

    #[Test]
    public function an_admin_changing_a_members_extras_is_audited(): void
    {
        $show = $this->show();
        $admin = $this->admin();
        $user = $this->member();
        $registration = $this->registration($show, $user);
        $this->enterCar($registration, $this->car($user));
        $extra = $this->extra($show, 'audit-key', '7.50');
        $extra->forceFill(['min_value' => 1, 'max_value' => 10])->save();

        $this->signIn($user)->postJson(self::EXTRA.'?extra_id=audit-key', ['num_extras' => 1])->assertOk();
        $this->assertSame([], $this->auditEntries('registration.extra_on_behalf'));

        $this->signIn($admin)->postJson(self::EXTRA.'?extra_id=audit-key&user_id='.$user->id, ['num_extras' => 2])->assertOk();

        $entries = $this->auditEntries('registration.extra_on_behalf');
        $this->assertCount(1, $entries);
        $this->assertSame($admin->id, $entries[0]['user_id']);
        $this->assertSame([$registration->id], $entries[0]['ids']);
        $this->assertSame(['user_id' => $user->id, 'extra_id' => $extra->id, 'num_extras' => 2], $entries[0]['extra']);
    }
}
