<?php

namespace App\Exceptions\Payments;

use RuntimeException;

/** A gateway call failed (network, timeout, rejected or invalid response). The message is safe to store and show to staff; it never contains secrets. */
class GatewayException extends RuntimeException {}
