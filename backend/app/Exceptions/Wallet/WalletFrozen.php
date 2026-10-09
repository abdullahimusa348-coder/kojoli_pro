<?php

namespace App\Exceptions\Wallet;

class WalletFrozen extends WalletException
{
    public function __construct(string $message = 'This wallet is frozen; debits are blocked.')
    {
        parent::__construct($message);
    }
}
