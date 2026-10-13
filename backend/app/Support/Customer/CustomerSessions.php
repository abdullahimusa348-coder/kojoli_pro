<?php

namespace App\Support\Customer;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A customer's own signed-in browser sessions, for the Security page.
 *
 * Only works with the database session driver (the default). Raw session IDs
 * are never exposed: each session is identified by a SHA-256 reference, and
 * IP addresses are masked.
 */
class CustomerSessions
{
    public static function available(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * @return list<array{ref: string, current: bool, device: string, ip: string, last_active: Carbon}>
     */
    public static function for(User $user, string $currentId): array
    {
        if (! self::available()) {
            return [];
        }

        return self::query($user)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($row) => [
                'ref' => self::ref($row->id),
                'current' => hash_equals($row->id, $currentId),
                'device' => self::describeAgent($row->user_agent),
                'ip' => self::maskIp($row->ip_address),
                'last_active' => Carbon::createFromTimestamp($row->last_activity),
            ])
            ->sortByDesc('current')
            ->values()
            ->all();
    }

    /** Raw session ID for one of this customer's sessions, found by its reference. */
    public static function findId(User $user, string $ref): ?string
    {
        if (! self::available()) {
            return null;
        }

        foreach (self::query($user)->pluck('id') as $id) {
            if (hash_equals(self::ref($id), $ref)) {
                return $id;
            }
        }

        return null;
    }

    public static function delete(User $user, string $id): void
    {
        self::query($user)->where('id', $id)->delete();
    }

    /** Ends every other session of this customer; returns how many. */
    public static function deleteOthers(User $user, string $currentId): int
    {
        return self::available() ? self::query($user)->where('id', '!=', $currentId)->delete() : 0;
    }

    public static function ref(string $id): string
    {
        return hash('sha256', $id);
    }

    /** "102.89.34.7" -> "102.89.•••.•••"; IPv6 keeps the first two groups. */
    public static function maskIp(?string $ip): string
    {
        if ($ip === null || $ip === '') {
            return 'Unknown';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            [$a, $b] = explode('.', $ip);

            return "{$a}.{$b}.•••.•••";
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $groups = explode(':', $ip);

            return ($groups[0] ?: '0').':'.($groups[1] ?: '0').':••••:••••';
        }

        return 'Unknown';
    }

    /** Readable "Browser on System" from a user agent, without storing or showing the raw string. */
    public static function describeAgent(?string $agent): string
    {
        $agent ??= '';

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };

        $system = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return match (true) {
            $browser !== null && $system !== null => "{$browser} on {$system}",
            $browser !== null => $browser,
            $system !== null => "Browser on {$system}",
            default => 'Unknown browser',
        };
    }

    private static function query(User $user)
    {
        return DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->getKey());
    }
}
