<?php

namespace App\Exceptions\Wallet;

use RuntimeException;

/** Base class for refused wallet operations. Messages are safe to show to staff and customers. */
class WalletException extends RuntimeException {}
