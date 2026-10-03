<?php

namespace App\Exceptions\Wallet;

class InvalidAmount extends WalletException
{
    public function __construct(string $message = 'The amount must be a whole number of kobo from 1 up to the allowed maximum.')
    {
        parent::__construct($message);
    }
}
