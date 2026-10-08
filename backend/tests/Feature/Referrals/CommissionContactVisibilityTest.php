<?php

use App\Actions\Auth\RegisterUser;
use App\Services\Referrals\ReferralCodeIssuer;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Support/Referrals/helpers.php';

/*
 * Phase 12: who sees a referrer's name and email. The Commissions list, its
 * search and the commission page show them, and search by them, only to staff
 * with customers.view as well as referrals.view. Anyone else sees the referrer's
 * customer number, and a search by name or email finds nothing for them. The
 * Referral Links tab keeps its approved names and emails for referrals.view
 * alone. No new permission is granted anywhere.
 */

beforeEach(function () {
    cmxDrivers();
    Http::preventStrayRequests();
});

it('shows a referrals.view viewer only the referrer\'s customer number on the list and the commission page', function () {
    $referrer = cmxReferrer();
    $referrer->forceFill(['name' => 'Amaka Referrer', 'email' => 'amaka.referrer@example.com'])->save();
    [, $commission] = cmxCommission(0, $referrer);
    $this->actingAs(cmxStaffWith(['admin.access', 'referrals.view']), 'admin');

    $this->get('/admin/referrals')->assertOk()->assertSee($commission->reference)->assertSee('Customer #'.$referrer->id)
        ->assertSee('placeholder="Commission or purchase reference"', false)
        ->assertDontSee('Amaka Referrer')->assertDontSee('amaka.referrer@example.com')->assertDontSee('referrer name or email');
    $this->get(route('admin.referrals.commissions.show', $commission))->assertOk()->assertSee('Customer #'.$referrer->id)
        ->assertDontSee('Amaka Referrer')->assertDontSee('amaka.referrer@example.com')
        ->assertDontSee('href="'.route('admin.users.show', $referrer).'"', false);
});

it('finds a commission for a referrals.view viewer by its reference only, never by the referrer\'s name or email', function () {
    $referrer = cmxReferrer();
    $referrer->forceFill(['name' => 'Tunde Bello', 'email' => 'tunde.b@example.com'])->save();
    [, $commission] = cmxCommission(0, $referrer);
    $this->actingAs(cmxStaffWith(['admin.access', 'referrals.view']), 'admin');

    foreach (['Bello', 'tunde.b@example.com', 'TUNDE.B@'] as $term) {
        $this->get('/admin/referrals?q='.urlencode($term))->assertOk()->assertSee('No commissions found')->assertDontSee($commission->reference);
    }
    foreach ([$commission->reference, strtolower($commission->reference), $commission->purchase->reference] as $term) {
        $this->get('/admin/referrals?q='.urlencode($term))->assertOk()->assertSee($commission->reference);
    }
});

it('shows the referrer\'s name and email, and finds the commission by them, to staff who also have customers.view', function () {
    $referrer = cmxReferrer();
    $referrer->forceFill(['name' => 'Tunde Bello', 'email' => 'tunde.b@example.com'])->save();
    [, $commission] = cmxCommission(0, $referrer);
    $this->actingAs(cmxStaffWith(['admin.access', 'referrals.view', 'customers.view']), 'admin');

    $this->get('/admin/referrals')->assertOk()->assertSee('Tunde Bello')->assertSee('tunde.b@example.com')
        ->assertSee('placeholder="Commission or purchase reference, referrer name or email"', false);
    $this->get(route('admin.referrals.commissions.show', $commission))->assertOk()->assertSee('Tunde Bello')
        ->assertSee('tunde.b@example.com')->assertSee('href="'.route('admin.users.show', $referrer).'"', false);
    foreach (['bello', 'TUNDE.B@'] as $term) {
        $this->get('/admin/referrals?q='.urlencode($term))->assertOk()->assertSee($commission->reference);
    }
});

it('gives customers.view alone no access to the commissions', function () {
    [, $commission] = cmxCommission(0);
    $this->actingAs(cmxStaffWith(['admin.access', 'customers.view']), 'admin');

    $this->get('/admin/referrals')->assertForbidden();
    $this->get(route('admin.referrals.commissions.show', $commission))->assertForbidden();
});

it('keeps the approved names and emails on the Referral Links tab for referrals.view alone', function () {
    $referrer = cmxReferrer();
    $referrer->forceFill(['name' => 'Amina Yusuf', 'email' => 'amina@example.com'])->save();
    $code = app(ReferralCodeIssuer::class)->codeFor($referrer)->code;
    $customer = app(RegisterUser::class)->handle(['name' => 'Bisi Ade', 'email' => 'bisi@example.com', 'phone' => '08039990001', 'password' => 'Secret123'], $code);
    $this->actingAs(cmxStaffWith(['admin.access', 'referrals.view']), 'admin');

    $this->get('/admin/referrals/links')->assertOk()->assertSee('Bisi Ade')->assertSee('bisi@example.com')
        ->assertSee('Amina Yusuf')->assertSee('amina@example.com')
        ->assertDontSee('href="'.route('admin.users.show', $customer).'"', false);
});
