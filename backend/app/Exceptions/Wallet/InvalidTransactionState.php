<?php

namespace App\Exceptions\Wallet;

class InvalidTransactionState extends WalletException
{
    public function __construct(string $message = 'This transaction cannot change to that status.')
    {
        parent::__construct($message);
    }
}
