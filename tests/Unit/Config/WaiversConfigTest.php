<?php

it('allows enough walk-in waivers per IP for a venue sharing one address', function () {
    expect(config('waivers.adhoc_creates_per_ip_per_hour'))->toBeInt()->toBeGreaterThanOrEqual(300);
});

it('keeps legacy signed waiver PDFs outside the public directory', function () {
    $dir = rtrim((string) config('waivers.legacy_public_dir'), '/');

    expect($dir)->not->toBe('')
        ->and(str_starts_with($dir.'/', rtrim(public_path(), '/').'/'))->toBeFalse();
});

it('denies /waivers/ in public/.htaccess before the front controller', function () {
    $htaccess = (string) file_get_contents(public_path('.htaccess'));
    $deny = strpos($htaccess, 'RewriteRule ^waivers(/|$) - [F,L]');

    expect($deny)->not->toBeFalse()
        ->and($deny)->toBeLessThan(strpos($htaccess, 'RewriteRule ^ index.php [L]'));
});
