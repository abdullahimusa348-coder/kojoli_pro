<?php

namespace App\Exceptions\Wallet;

class InsufficientFunds extends WalletException
{
    public function __construct(string $message = 'The wallet balance is not enough for this debit.')
    {
        parent::__construct($message);
    }
}
