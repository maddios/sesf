<?php

namespace Tests\Database\Auth;

use App\Support\TrustedHosts;
use App\Support\TrustedProxies;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

class HostProxyCookieConfigTest extends TestCase
{
    use LoadsLegacySchema;

    /**
     * Sets an environment variable in every place the env repository reads it, and returns a
     * closure that restores the previous values.
     */
    private function setEnv(string $name, ?string $value): \Closure
    {
        $previous = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];

        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        } else {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        return function () use ($name, $previous): void {
            $previous[0] === false ? putenv($name) : putenv($name.'='.$previous[0]);

            if ($previous[1] === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $previous[1];
            }

            if ($previous[2] === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $previous[2];
            }
        };
    }

    /**
     * @return array<string, array{0: ?string, 1: array<int, string>|string}>
     */
    public static function proxyLists(): array
    {
        return [
            'unset trusts none' => [null, []],
            'empty trusts none' => ['', []],
            'blank entries are dropped' => [' , ', []],
            'ips and cidrs' => ['10.0.0.5, 172.16.0.0/12', ['10.0.0.5', '172.16.0.0/12']],
            'star trusts all only when set' => ['*', '*'],
        ];
    }

    /**
     * @param  array<int, string>|string  $expected
     */
    #[Test]
    #[DataProvider('proxyLists')]
    public function trusted_proxies_come_only_from_the_environment(?string $raw, array|string $expected): void
    {
        $this->assertSame($expected, TrustedProxies::fromEnv($raw));
    }

    #[Test]
    public function the_host_patterns_are_app_url_only_plus_localhost_in_testing(): void
    {
        config(['app.url' => 'https://sesf.example.org']);

        $patterns = TrustedHosts::patterns();

        $this->assertContains('^'.preg_quote('sesf.example.org').'$', $patterns);
        $this->assertContains('^localhost$', $patterns);
        $this->assertNotContains('^(.+\.)?'.preg_quote('sesf.example.org').'$', $patterns);
        $this->assertNotContains('^evil\.example$', $patterns);
    }

    #[Test]
    public function localhost_is_not_trusted_outside_local_and_testing(): void
    {
        config(['app.url' => 'https://sesf.example.org']);
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertSame(['^'.preg_quote('sesf.example.org').'$'], TrustedHosts::patterns());
    }

    #[Test]
    public function url_generation_uses_app_url_not_the_request_host(): void
    {
        // AppServiceProvider::boot forces the root URL to APP_URL, so a forged Host cannot change it.
        $this->app->instance('request', Request::create('http://evil.example/login/forgot-password'));

        $this->assertSame(rtrim((string) config('app.url'), '/').'/login/x', url('/login/x'));
    }

    #[Test]
    public function the_session_cookie_is_secure_by_default_only_in_production(): void
    {
        $restoreEnv = $this->setEnv('SESSION_SECURE_COOKIE', null);
        $restoreAppEnv = $this->setEnv('APP_ENV', 'production');

        try {
            $config = require config_path('session.php');
            $this->assertTrue($config['secure']);

            $restoreAppEnv();
            $restoreAppEnv = $this->setEnv('APP_ENV', 'local');
            $config = require config_path('session.php');
            $this->assertFalse($config['secure']);
        } finally {
            $restoreAppEnv();
            $restoreEnv();
        }
    }

    #[Test]
    public function session_secure_cookie_env_overrides_the_default(): void
    {
        $restoreEnv = $this->setEnv('SESSION_SECURE_COOKIE', 'false');
        $restoreAppEnv = $this->setEnv('APP_ENV', 'production');

        try {
            $config = require config_path('session.php');
            $this->assertFalse($config['secure']);
        } finally {
            $restoreAppEnv();
            $restoreEnv();
        }
    }
}
