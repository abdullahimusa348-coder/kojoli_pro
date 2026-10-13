<?php

namespace App\Support;

/** Money is stored as integer kobo (see docs/ARCHITECTURE.md). */
class Money
{
    public static function format(int $kobo): string
    {
        return '₦'.number_format($kobo / 100, 2);
    }
}
