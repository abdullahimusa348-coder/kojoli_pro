<?php

namespace App\Support\Payments;

/** What a gateway adapter can do. Phase 9 Step 1: wallet funding only. */
enum GatewayCapability: string
{
    case WalletFunding = 'wallet_funding';
}
