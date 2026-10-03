<?php

namespace App\Exceptions\Wallet;

class BalanceLimitExceeded extends WalletException
{
    public function __construct(string $message = 'This credit would take the wallet above the maximum allowed balance.')
    {
        parent::__construct($message);
    }
}
