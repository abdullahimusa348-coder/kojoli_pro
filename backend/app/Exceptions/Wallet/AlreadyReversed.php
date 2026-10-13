<?php

namespace App\Exceptions\Wallet;

class AlreadyReversed extends WalletException
{
    public function __construct(string $message = 'This transaction has already been reversed.')
    {
        parent::__construct($message);
    }
}
