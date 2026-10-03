<?php

namespace App\Support\Catalog;

/** Validity group of a plan (e.g. data bundles). Plan attribute, not a separate service. */
enum ValidityPeriod: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
            self::Other => 'Other',
        };
    }
}
