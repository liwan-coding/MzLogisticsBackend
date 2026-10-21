<?php

return [
    'password_reset' => [
        'subject' => 'Your MoveX password reset code',
        'heading' => 'Reset your password',
        'intro' => 'Hello :name, use this code to choose a new password.',
        'expires' => 'The code expires in :minutes minutes.',
        'ignore' => 'If you did not ask for this, you can ignore this email.',
        'sent' => 'If an account exists for this email, a reset code is on its way.',
        'updated' => 'Password updated.',
        'invalid' => 'This code is invalid or has expired.',
        'locked' => 'Too many attempts. Request a new code.',
    ],
];
