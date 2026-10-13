<?php

namespace App\Support\Payments;

enum WebhookOutcome: string
{
    case Processed = 'processed';
    case InvalidSignature = 'invalid_signature';
    case Malformed = 'malformed';
    case UnknownPayment = 'unknown_payment';
    case NotConfigured = 'not_configured';

    public function label(): string
    {
        return match ($this) {
            self::Processed => 'Processed',
            self::InvalidSignature => 'Invalid signature',
            self::Malformed => 'Malformed',
            self::UnknownPayment => 'Unknown payment',
            self::NotConfigured => 'Gateway not configured',
        };
    }
}
