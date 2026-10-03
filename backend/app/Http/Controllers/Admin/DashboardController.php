<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/** Placeholder landing page. The admin dashboard itself is Phase 3. */
class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard');
    }
}
