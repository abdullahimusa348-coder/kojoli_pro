<?php

namespace App\Exceptions\Wallet;

class IdempotencyConflict extends WalletException
{
    public function __construct(string $message = 'This request key was already used for a different operation.')
    {
        parent::__construct($message);
    }
}
