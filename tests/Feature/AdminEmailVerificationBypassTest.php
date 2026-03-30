<?php

use App\Models\User;

test('admin accounts are treated as verified even without email_verified_at', function () {
    $user = (new User)->forceFill([
        'role' => 'admin',
        'email_verified_at' => null,
    ]);

    expect($user->hasVerifiedEmail())->toBeTrue();
});

test('regular users still require email verification', function () {
    $user = (new User)->forceFill([
        'role' => 'user',
        'email_verified_at' => null,
    ]);

    expect($user->hasVerifiedEmail())->toBeFalse();
});
