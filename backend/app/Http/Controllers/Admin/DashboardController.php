<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DashboardMetrics;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardMetrics $metrics): View
    {
        $staff = $request->user('admin');

        return view('admin.dashboard', [
            'staff' => $staff,
            'cards' => $metrics->cardsFor($staff),
            'showRecentTransactions' => $metrics->showsRecentTransactions($staff),
            'recentTransactions' => $metrics->recentTransactions(),
        ]);
    }
}
