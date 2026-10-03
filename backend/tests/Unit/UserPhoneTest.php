<?php

use App\Models\User;

it('normalises Nigerian phone numbers', function (string $input, string $expected) {
    expect(User::normalizePhone($input))->toBe($expected);
})->with([
    ['08031234567', '08031234567'],
    ['+2348031234567', '08031234567'],
    ['234 803 123 4567', '08031234567'],
    ['0803-123-4567', '08031234567'],
]);
