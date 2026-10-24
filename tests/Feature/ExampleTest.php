<?php

use Tests\TestCase;

it('returns a successful response from the homepage', function () {
    /** @var TestCase $this */
    $this->get('/')->assertStatus(200);
});
