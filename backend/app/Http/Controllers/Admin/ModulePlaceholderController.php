<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminModule;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Navigation placeholder for admin modules that are not built yet.
 * No business logic: it only says when the module is planned.
 */
class ModulePlaceholderController extends Controller
{
    public function __invoke(Request $request): View
    {
        $module = AdminModule::from($request->route()->defaults['module']);

        return view('admin.placeholder', ['module' => $module]);
    }
}
