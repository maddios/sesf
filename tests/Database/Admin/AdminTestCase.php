<?php

namespace Tests\Database\Admin;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

/**
 * Shared setup for admin-api tests:
 *  - a random client IP per test, so per-IP limiters do not leak between tests;
 *  - the audit channel writes to a temp directory, read back with auditEntries();
 *  - actors for the authorization matrix.
 */
abstract class AdminTestCase extends TestCase
{
    use LoadsLegacySchema;

    protected const JSON = ['Accept' => 'application/json'];

    protected string $auditDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withServerVariables(['REMOTE_ADDR' => '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254)]);

        $this->auditDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sesf-audit-'.bin2hex(random_bytes(6));
        mkdir($this->auditDir, 0755, true);
        config(['logging.channels.audit.path' => $this->auditDir.'/audit.log']);
        Log::forgetChannel('audit');

        $this->beforeApplicationDestroyed(function (): void {
            Log::forgetChannel('audit');
            foreach (glob($this->auditDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->auditDir);
        });
    }

    /**
     * Signs in as $user for the next request. The session is flushed first: AuthenticateSession
     * logs out a session whose stored password hash belongs to another user, which would happen
     * whenever one test switches between users.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->flushSession();

        return parent::actingAs($user, $guard);
    }

    protected function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** null = anonymous. */
    protected function actor(string $who): ?User
    {
        return match ($who) {
            'anonymous' => null,
            'member' => User::factory()->create(),
            'judge' => User::factory()->judge()->create(),
            'admin' => $this->admin(),
            default => throw new \InvalidArgumentException($who),
        };
    }

    /**
     * Decoded audit entries written during the test (the JSON "context" of each line).
     *
     * @return list<array<string, mixed>>
     */
    protected function auditEntries(): array
    {
        $entries = [];
        foreach (glob($this->auditDir.'/*.log') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $decoded = json_decode($line, true);
                $this->assertIsArray($decoded, 'Each audit line must be one JSON object.');
                $entries[] = $decoded['context'];
            }
        }

        return $entries;
    }

    /** Raw audit log text, for "contains no secret" assertions. */
    protected function auditText(): string
    {
        $text = '';
        foreach (glob($this->auditDir.'/*.log') ?: [] as $file) {
            $text .= (string) file_get_contents($file);
        }

        return $text;
    }
}
