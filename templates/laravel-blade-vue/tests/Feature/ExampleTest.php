<?php

it('returns a successful response', function () {
    $this->withoutVite();
    $this->get('/')->assertStatus(200);
});
