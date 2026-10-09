<?php

namespace App\Exceptions\Payments;

/** The gateway cannot be used in its configured mode (missing driver or credentials, or live payments switched off). Nothing is sent anywhere. */
class GatewayNotConfigured extends GatewayException {}
