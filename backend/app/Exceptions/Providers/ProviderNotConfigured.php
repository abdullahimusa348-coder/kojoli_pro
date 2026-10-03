<?php

namespace App\Exceptions\Providers;

use RuntimeException;

/** The provider has no installed adapter or lacks a credential its adapter needs. Nothing is sent anywhere. */
class ProviderNotConfigured extends RuntimeException {}
