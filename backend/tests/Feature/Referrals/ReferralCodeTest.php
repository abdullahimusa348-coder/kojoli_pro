<?php

use App\Models\ReferralCode;
use App\Models\User;
use App\Services\Referrals\ReferralCodeIssuer;
use App\Support\Enums\UserType;
use App\Support\Referrals\ReferralCodes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * Phase 12 CP3: referral codes. The format (8 characters from the 32-symbol
 * alphabet without 0, O, 1 and I), case-insensitive input with no look-alike
 * mapping, and the issuer: one permanent code per eligible customer, the
 * same one on every visit and after an API User becomes eligible again, a
 * new random code after a collision with another customer's (up to the
 * retry limit), and the stored code when the same customer's requests race.
 * The database's unique keys decide; nothing else creates codes.
 */

/** An issuer whose "random" codes come from $codes in order; $before(n) runs before attempt n (another request at work). */
function rctIssuer(array $codes, ?Closure $before = null): ReferralCodeIssuer
{
    return new class($codes, $before) extends ReferralCodeIssuer
    {
        public int $tries = 0;

        public function __construct(private array $codes, private ?Closure $before) {}

        protected function newCode(): string
        {
            $this->tries++;
            if ($this->before !== null) {
                ($this->before)($this->tries);
            }

            return array_shift($this->codes) ?? throw new LogicException('No more test codes.');
        }
    };
}

describe('format', function () {
    it('uses 32 upper-case letters and digits, without 0, O, 1 or I', function () {
        $alphabet = str_split(ReferralCodes::ALPHABET);

        expect($alphabet)->toHaveCount(32)
            ->and(array_unique($alphabet))->toHaveCount(32)
            ->and(preg_match('/\A[A-Z2-9]+\z/', ReferralCodes::ALPHABET))->toBe(1)
            ->and(array_intersect($alphabet, ['0', 'O', '1', 'I']))->toBe([])
            ->and(array_values(array_diff([...range('A', 'Z'), ...range('2', '9')], $alphabet)))->toBe(['I', 'O'])
            ->and(ReferralCodes::LENGTH)->toBe(8);
    });

    it('generates well-formed 8-character codes that use the whole alphabet', function () {
        $codes = array_map(fn () => ReferralCodes::generate(), range(1, 400));

        foreach ($codes as $code) {
            expect($code)->toHaveLength(8)->and(ReferralCodes::isWellFormed($code))->toBeTrue($code);
        }
        // 3,200 symbols drawn from 32: every symbol shows up (the chance of one missing is about 1 in 10^42).
        expect(count(array_unique(str_split(implode('', $codes)))))->toBe(32);
    });

    it('reads typed codes case-insensitively: trimmed and upper-cased only, with no look-alike mapping', function (string $typed, ?string $read) {
        $code = ReferralCodes::normalise($typed);

        expect(ReferralCodes::isWellFormed($code) ? $code : null)->toBe($read);
    })->with([
        'lower case' => ['abcd2345', 'ABCD2345'],
        'mixed case with spaces' => ['  aBcD2345 ', 'ABCD2345'],
        'zero is not O' => ['ABCD0345', null],
        'O is not zero' => ['ABCDO345', null],
        'one is not I' => ['ABCD1345', null],
        'I is not one' => ['ABCDI345', null],
        'seven characters' => ['ABCD234', null],
        'nine characters' => ['ABCD23456', null],
        'a dash' => ['ABCD-345', null],
        'a space inside' => ['ABCD 345', null],
        'empty' => ['', null],
    ]);
});

describe('issuing', function () {
    it('gives an eligible customer one permanent code, the same one every time', function (UserType $type) {
        $customer = User::factory()->ofType($type)->create();

        $first = app(ReferralCodeIssuer::class)->codeFor($customer);
        $again = app(ReferralCodeIssuer::class)->codeFor($customer);

        expect(ReferralCodes::isWellFormed($first->code))->toBeTrue()
            ->and($again->id)->toBe($first->id)->and($again->code)->toBe($first->code)
            ->and(ReferralCode::where('user_id', $customer->id)->count())->toBe(1);
    })->with([UserType::Subscriber, UserType::Vendor, UserType::Affiliate]);

    it('never gives an API User a code, and gives back the same code once an API User is eligible again', function () {
        $api = User::factory()->ofType(UserType::ApiUser)->create();
        expect(fn () => app(ReferralCodeIssuer::class)->codeFor($api))
            ->toThrow(LogicException::class, 'A referral code is created only for a Subscriber, Vendor or Affiliate.')
            ->and(ReferralCode::count())->toBe(0);

        $customer = User::factory()->create();
        $code = app(ReferralCodeIssuer::class)->codeFor($customer)->code;
        $customer->forceFill(['user_type' => UserType::ApiUser])->save();
        $customer->forceFill(['user_type' => UserType::Vendor])->save();

        expect(app(ReferralCodeIssuer::class)->codeFor($customer->fresh())->code)->toBe($code)
            ->and(ReferralCode::count())->toBe(1);
    });

    it('replaces a new code that is already another customer\'s, and gives up after the retries', function () {
        $taken = app(ReferralCodeIssuer::class)->codeFor(User::factory()->create())->code;

        $issuer = rctIssuer([$taken, $taken, 'BCDFGH23']);
        $customer = User::factory()->create();
        expect($issuer->codeFor($customer)->code)->toBe('BCDFGH23')->and($issuer->tries)->toBe(3);

        $issuer = rctIssuer(array_fill(0, ReferralCodeIssuer::RETRIES + 1, $taken));
        $unlucky = User::factory()->create();
        expect(fn () => $issuer->codeFor($unlucky))->toThrow(RuntimeException::class, 'Could not create a unique referral code. Please try again.')
            ->and($issuer->tries)->toBe(ReferralCodeIssuer::RETRIES + 1)
            ->and(ReferralCode::where('user_id', $unlucky->id)->exists())->toBeFalse()
            ->and(ReferralCode::count())->toBe(2);
    });

    it('returns the code the same customer\'s other request stored first when their requests race', function () {
        $customer = User::factory()->create();
        // The customer's other request stores their code between this request's check and its insert.
        $issuer = rctIssuer(['CCDDEEFF'], fn (int $try) => $try === 1
            ? (new ReferralCode)->forceFill(['user_id' => $customer->id, 'code' => 'GGHHJJKK'])->save() : null);

        expect($issuer->codeFor($customer)->code)->toBe('GGHHJJKK')
            ->and($issuer->tries)->toBe(1)
            ->and(ReferralCode::where('user_id', $customer->id)->pluck('code')->all())->toBe(['GGHHJJKK']);
    });

    it('lets the database refuse a second code for a customer and a code used twice', function () {
        $first = User::factory()->create();
        $code = app(ReferralCodeIssuer::class)->codeFor($first)->code;
        $second = User::factory()->create();

        expect(fn () => (new ReferralCode)->forceFill(['user_id' => $first->id, 'code' => 'ZZZZ2222'])->save())->toThrow(UniqueConstraintViolationException::class)
            ->and(fn () => (new ReferralCode)->forceFill(['user_id' => $second->id, 'code' => $code])->save())->toThrow(UniqueConstraintViolationException::class)
            ->and(ReferralCode::count())->toBe(1);
    });

    it('never lets a code be changed or removed', function () {
        $code = app(ReferralCodeIssuer::class)->codeFor(User::factory()->create());
        $stored = $code->code;

        expect(fn () => $code->forceFill(['code' => 'ZZZZ2222'])->save())->toThrow(LogicException::class, 'Referral codes never change.')
            ->and(fn () => $code->delete())->toThrow(LogicException::class, 'Referral codes are never deleted.')
            ->and(ReferralCode::sole()->code)->toBe($stored);
    });

    it('creates codes only in the issuer, never by hand or in bulk', function () {
        $sources = collect([app_path(), base_path('routes'), resource_path(), database_path('seeders'), database_path('factories')])
            ->flatMap(fn (string $dir) => File::allFiles($dir))
            ->mapWithKeys(fn ($file) => [Str::after($file->getPathname(), base_path().'/') => $file->getContents()]);

        expect($sources->filter(fn (string $code) => preg_match('/new\s+ReferralCode\b|ReferralCode::(?:create|firstOrCreate|updateOrCreate|make|forceCreate)\s*\(/', $code) === 1)->keys()->all())
            ->toBe(['app/Services/Referrals/ReferralCodeIssuer.php']);
    });
});
