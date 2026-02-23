<?php

test('the application returns a successful response', function () {
    $this->get('/')->assertOk();
});
