<?php

use App\Actions\Admin\Kyc\SaveKycRequirement;
use App\Support\Enums\SystemRole;
use App\Support\Enums\UserStatus;
use App\Support\Enums\UserType;
use App\Support\Kyc\KycIdentityNumber;
use App\Support\Kyc\KycStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/Kyc/helpers.php';

/*
 * Phase 13 CP2b-2a: the customer's read-only KYC pages, /kyc and /kyc/submissions/{reference}. A customer sees the requirements that
 * apply to them, their own status and their own submissions, each with its masks. These tests cover access and the disabled-account
 * rule, which requirements are listed, owner scoping and 404s, the statuses, that a decision's reason is never shown, that no full
 * number, ciphertext, lookup hash, form key or phone fingerprint reaches a page or a query, the headers, and that the pages have no
 * form, no write route and no ledger call. Identity numbers are synthetic (tests/Support/Kyc/helpers.php).
 */

beforeEach(fn () => kycSeed());

it('sends a guest to the login page', function () {
    $this->get(route('kyc'))->assertRedirect(route('login'));
    $this->get(route('kyc.submissions.show', 'KYC-'.strtoupper((string) Str::ulid())))->assertRedirect(route('login'));
});

it('refuses a disabled customer like every other customer page', function () {
    $customer = kycCustomer();
    $customer->forceFill(['status' => UserStatus::Disabled])->save();

    $this->actingAs($customer)->get(route('kyc'))->assertRedirect(route('login'));
});

it('says that no verification is required when no requirement applies to the customer', function () {
    $this->actingAs(kycCustomer())->get(route('kyc'))
        ->assertOk()
        ->assertSee('data-kyc-required="none"', false)
        ->assertSee('No verification is required for your account.')
        ->assertSee('Not started')
        ->assertSee('No verification details have been sent yet.');
});

it('lists only the requirements that are on for the customer\'s type', function () {
    kycEnable('bvn', ['subscriber']);
    kycEnable('nin', ['vendor']);
    $phone = kycRequirement('phone');
    app(SaveKycRequirement::class)->handle($phone, $phone->label, $phone->description, false, ['subscriber'],
        'Kept off, so the customer page must not list it', kycStaff(), SaveKycRequirement::fingerprint($phone));

    $this->actingAs(kycCustomer(UserType::Subscriber))->get(route('kyc'))
        ->assertOk()
        ->assertSee('data-kyc-requirement="bvn"', false)
        ->assertDontSee('data-kyc-requirement="nin"', false)
        ->assertDontSee('data-kyc-requirement="phone"', false)
        ->assertSee('Sending verification details is not available yet.');

    $this->actingAs(kycCustomer(UserType::Vendor))->get(route('kyc'))
        ->assertOk()
        ->assertSee('data-kyc-requirement="nin"', false)
        ->assertDontSee('data-kyc-requirement="bvn"', false);
});

it('shows each customer their own status and submissions, and no one else\'s', function () {
    kycEnable('bvn');
    $mine = kycCustomer();
    $theirs = kycCustomer();
    $mySubmission = kycOpen($mine, ['bvn' => KYC_TEST_BVN]);
    $theirSubmission = kycOpen($theirs, ['bvn' => '44444444444']);

    $this->actingAs($mine)->get(route('kyc'))
        ->assertOk()
        ->assertSee('data-kyc-status="pending_review"', false)
        ->assertSee('Pending review')
        ->assertSee('href="'.route('kyc.submissions.show', $mySubmission->reference).'"', false)
        ->assertSee($mySubmission->reference)
        ->assertDontSee($theirSubmission->reference)
        ->assertDontSee('44444444444')
        ->assertDontSee(KYC_TEST_BVN);
});

it('shows a submission to its owner as masks, and never the full number', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();
    $submission = kycOpen($customer);

    $this->actingAs($customer)->get(route('kyc.submissions.show', $submission->reference))
        ->assertOk()
        ->assertSee($submission->reference)
        ->assertSee('Numbers sent (masked)')
        ->assertSee(KycIdentityNumber::mask(KYC_TEST_BVN))
        ->assertSee(KycIdentityNumber::mask(KYC_TEST_NIN))
        ->assertSee('Pending review')
        ->assertDontSee(KYC_TEST_BVN)
        ->assertDontSee(KYC_TEST_NIN)
        ->assertDontSee('ciphertext')
        ->assertDontSee('lookup_hash')
        ->assertDontSee('form_key');
});

it('shows a rejected submission as rejected and never shows the reason', function () {
    kycEnable('bvn');
    $customer = kycCustomer();
    $submission = kycOpen($customer, ['bvn' => KYC_TEST_BVN]);
    kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Rejected, 'The details do not match our records.');

    $this->actingAs($customer)->get(route('kyc'))
        ->assertOk()
        ->assertSee('data-kyc-status="rejected"', false)
        ->assertSee('Rejected')
        ->assertDontSee('The details do not match our records.');

    $this->actingAs($customer)->get(route('kyc.submissions.show', $submission->reference))
        ->assertOk()
        ->assertSee('data-kyc-submission-status="rejected"', false)
        ->assertDontSee('The details do not match our records.');
});

it('shows an approved submission as approved', function () {
    kycEnable('bvn');
    $customer = kycCustomer();
    $submission = kycOpen($customer, ['bvn' => KYC_TEST_BVN]);
    kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved);

    $this->actingAs($customer)->get(route('kyc'))
        ->assertOk()
        ->assertSee('data-kyc-status="approved"', false)
        ->assertSee('data-kyc-submission-status="approved"', false);
});

it('gives another customer\'s submission, an unknown reference and a malformed one a 404', function () {
    kycEnable('bvn');
    $owner = kycCustomer();
    $submission = kycOpen($owner, ['bvn' => KYC_TEST_BVN]);
    $stranger = kycCustomer();

    $this->actingAs($stranger)->get(route('kyc.submissions.show', $submission->reference))->assertNotFound();
    $this->actingAs($stranger)->get(route('kyc.submissions.show', 'KYC-'.strtoupper((string) Str::ulid())))->assertNotFound();
    $this->actingAs($stranger)->get('/kyc/submissions/KYC-not-a-reference')->assertNotFound();
    $this->actingAs($owner)->get(route('kyc.submissions.show', $submission->reference))->assertOk();
});

it('sends both pages no-store and private', function () {
    kycEnable('bvn');
    $customer = kycCustomer();
    $submission = kycOpen($customer, ['bvn' => KYC_TEST_BVN]);

    $index = $this->actingAs($customer)->get(route('kyc'))->assertOk();
    expect($index->headers->get('Cache-Control'))->toBe('no-store, private');

    $page = $this->actingAs($customer)->get(route('kyc.submissions.show', $submission->reference))->assertOk();
    expect($page->headers->get('Cache-Control'))->toBe('no-store, private');
});

it('has no form, no field for a number, a consent or a correction, and accepts no write', function () {
    kycEnable('bvn');
    $customer = kycCustomer();
    $submission = kycOpen($customer, ['bvn' => KYC_TEST_BVN]);

    foreach ([route('kyc'), route('kyc.submissions.show', $submission->reference)] as $url) {
        $html = $this->actingAs($customer)->get($url)->assertOk()->getContent();

        foreach (['name="bvn"', 'name="nin"', 'name="identity_number"', 'name="consent"', 'name="confirmation"', 'name="form_key"', 'name="correction"'] as $field) {
            expect($html)->not->toContain($field);
        }
    }

    $this->actingAs($customer)->post(route('kyc'))->assertStatus(405);
    $this->actingAs($customer)->post(route('kyc.submissions.show', $submission->reference))->assertStatus(405);
});

it('registers no route for the customer pages that accepts anything but a GET', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with((string) $route->getName(), 'kyc'));

    expect($routes->map(fn ($route) => $route->getName())->sort()->values()->all())->toBe(['kyc', 'kyc.submissions.show'])
        ->and($routes->flatMap(fn ($route) => $route->methods())->unique()->sort()->values()->all())->toBe(['GET', 'HEAD']);
});

it('keeps the customer pages to reads: no ledger call and no write to a model or the database', function () {
    $files = [app_path('Http/Controllers/User/KycController.php'), app_path('Services/Kyc/KycCustomerOverview.php'),
        ...collect(File::files(resource_path('views/user/kyc')))->map(fn ($file) => $file->getPathname())->all()];

    expect(count($files))->toBeGreaterThanOrEqual(4);
    $writes = '/KycSubmissionLedger|->save\(|::create\(|forceFill\(|->update\(|->delete\(|->insert\(|->fill\(|DB::/';
    expect(collect($files)->filter(fn (string $file) => preg_match($writes, File::get($file)) === 1)->values()->all())->toBe([]);
});

it('never queries the purchase identity or result tables, nor loads a ciphertext, lookup hash, form key, fingerprint or reason', function () {
    kycEnable('bvn');
    $customer = kycCustomer();
    $submission = kycOpen($customer, ['bvn' => KYC_TEST_BVN]);
    kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Rejected, 'The details do not match our records.');
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $this->actingAs($customer)->get(route('kyc'))->assertOk();
    $this->actingAs($customer)->get(route('kyc.submissions.show', $submission->reference))->assertOk();

    expect(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'purchase_identity') || str_contains($sql, 'purchase_results'))->values()->all())->toBe([])
        ->and(collect($queries)->filter(fn (string $sql) => preg_match('/ciphertext|lookup_hash|form_key|phone_fingerprint|reason/', $sql) === 1)->values()->all())->toBe([]);
});
