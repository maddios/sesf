<?php

it('defines the mail-debug channel as a daily info-level file with 7 days of retention', function () {
    $channel = config('logging.channels.mail-debug');

    expect($channel['driver'])->toBe('daily')
        ->and($channel['level'])->toBe('info')
        ->and($channel['max_files'])->toBe(7)
        ->and($channel['path'])->toEndWith('storage/logs/mail-debug.log');
});

it('keeps mail-debug out of the default stack', function () {
    expect(config('logging.channels.stack.channels'))->not->toContain('mail-debug');
});
