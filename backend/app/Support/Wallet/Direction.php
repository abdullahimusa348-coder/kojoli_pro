<?php

namespace App\Support\Wallet;

enum Direction: string
{
    case Credit = 'credit';
    case Debit = 'debit';

    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Credit',
            self::Debit => 'Debit',
        };
    }

    public function opposite(): self
    {
        return $this === self::Credit ? self::Debit : self::Credit;
    }
}
