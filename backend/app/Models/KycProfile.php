<?php

namespace App\Models;

use App\Support\Kyc\KycStatus;
use Database\Factories\KycProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's KYC status (Phase 13): one row per customer, created when the
 * customer first has a KYC status. CP1 writes none. The status will be a cache of
 * the review decisions, updated in the same transaction as each decision.
 */
class KycProfile extends Model
{
    /** @use HasFactory<KycProfileFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'status' => KycStatus::class,
            'status_changed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
