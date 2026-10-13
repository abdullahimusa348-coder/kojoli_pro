<?php

namespace App\Exceptions\Payments;

use RuntimeException;

/** A refused payment operation (invalid amount, unusable gateway, idempotency conflict, invalid status change). Message is safe to show. */
class PaymentException extends RuntimeException {}
