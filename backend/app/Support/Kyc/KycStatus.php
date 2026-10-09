<?php

namespace App\Support\Kyc;

/**
 * A customer's KYC status (Phase 13). These names are the proposal under decision
 * D9 (the exact names, and whether "more information requested" exists). CP1
 * stores them; the review workflow writes them later. Nothing reads a status yet,
 * so nothing is gated by one.
 */
enum KycStatus: string
{
    case NotStarted = 'not_started';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case MoreInfoRequested = 'more_info_requested';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::PendingReview => 'Pending review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::MoreInfoRequested => 'More information requested',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
