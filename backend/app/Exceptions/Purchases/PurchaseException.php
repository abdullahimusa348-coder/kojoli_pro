<?php

namespace App\Exceptions\Purchases;

use RuntimeException;

/** A refused purchase operation (invalid status change, broken invariant, refused request). Message is safe to show. */
class PurchaseException extends RuntimeException {}
