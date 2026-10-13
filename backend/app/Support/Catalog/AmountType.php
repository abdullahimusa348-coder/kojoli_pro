<?php

namespace App\Support\Catalog;

/** Whether a plan is a fixed bundle (e.g. 1GB) or a customer-entered amount (e.g. airtime). */
enum AmountType: string
{
    case Fixed = 'fixed';
    case Variable = 'variable';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed',
            self::Variable => 'Variable amount',
        };
    }
}
