<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Settings\UpdateSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Models\Setting;
use App\Services\Settings\SettingsStore;
use App\Support\Enums\SystemPermission;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(Request $request, SettingsStore $store): View
    {
        // Known settings in their defined order, then any others alphabetically.
        $order = array_flip(array_keys(SettingDefinitions::all()));

        $groups = Setting::orderBy('group')->orderBy('key')->get()
            ->sortBy(fn (Setting $s) => [$order[$s->key] ?? PHP_INT_MAX, $s->key])
            ->groupBy('group')
            ->map(fn ($settings, $group) => [
                'label' => SettingDefinitions::groupLabel($group),
                // Encrypted values are never sent to the browser.
                'settings' => $settings->map(fn (Setting $s) => [
                    'model' => $s,
                    'field' => UpdateSettingsRequest::field($s->key),
                    'value' => $s->is_encrypted ? null : $store->get($s->key),
                    'has_value' => $s->getRawOriginal('value') !== null,
                ]),
            ]);

        return view('admin.settings.index', [
            'groups' => $groups,
            'canUpdate' => $request->user('admin')->can(SystemPermission::SettingsUpdate->value),
        ]);
    }

    public function update(UpdateSettingsRequest $request, UpdateSettings $update): RedirectResponse
    {
        $update->handle($request->values(), $request->user('admin'));

        return redirect()->route('admin.settings')->with('status', 'Settings saved.');
    }
}
