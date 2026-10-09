<?php

namespace App\Exceptions\Providers;

use RuntimeException;

/** The request was refused locally before anything was sent (undeclared host, non-https URL, adapter misuse). */
class ProviderRequestRefused extends RuntimeException {}
