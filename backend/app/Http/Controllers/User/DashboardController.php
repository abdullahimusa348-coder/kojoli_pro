<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Support\Customer\CustomerDashboard;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('user.dashboard', CustomerDashboard::for($request->user()));
    }
}
