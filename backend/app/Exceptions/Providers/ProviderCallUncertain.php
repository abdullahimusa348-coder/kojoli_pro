<?php

namespace App\Exceptions\Providers;

use RuntimeException;

/**
 * The provider call may or may not have reached the provider (timeout,
 * connection failure). Its outcome is unknown and must never be treated as
 * a failure. The message is safe to store; it never contains secrets.
 */
class ProviderCallUncertain extends RuntimeException {}
