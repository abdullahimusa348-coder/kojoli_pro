<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Referral & Commission area, Commissions tab (referrals.view). Commissions
 * are not paid yet, so the tab is an empty state: no records, actions or
 * customer data are read here.
 */
class ReferralController extends Controller
{
    public function index(): View
    {
        return view('admin.referrals.index');
    }
}
