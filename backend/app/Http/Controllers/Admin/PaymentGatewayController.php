<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Payments\SaveGateway;
use App\Actions\Admin\Payments\SaveGatewayCredentials;
use App\Actions\Admin\Payments\SetGatewayMode;
use App\Actions\Admin\Payments\SetGatewayStatus;
use App\Exceptions\Payments\PaymentException;
use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use App\Services\Payments\GatewayRegistry;
use App\Support\Payments\GatewayMode;
use App\Support\Payments\GatewayStatus;
use App\Support\Payments\PaymentLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Payment gateways (payments.view). Records, status, mode and priority need
 * payments.gateways; credentials need payments.credentials. Endpoints are
 * fixed by the adapter in code and are never editable here.
 */
class PaymentGatewayController extends Controller
{
    public function __construct(private GatewayRegistry $registry) {}

    public function index(): View
    {
        $gateways = PaymentGateway::with('credentials')->withCount('payments')->orderBy('priority')->get();

        return view('admin.payments.gateways.index', [
            'gateways' => $gateways,
            'problems' => $gateways->mapWithKeys(fn ($g) => [$g->id => $this->registry->configurationProblem($g)]),
            'liveEnabled' => PaymentLimits::liveEnabled(),
            'drivers' => $this->registry->adapters(),
        ]);
    }

    public function create(): View
    {
        return view('admin.payments.gateways.create', ['drivers' => $this->registry->adapters()]);
    }

    public function store(Request $request, SaveGateway $save): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('payment_gateways', 'code')],
            'driver' => ['required', 'string', Rule::in(array_keys($this->registry->adapters()))],
            'wallet_funding' => ['boolean'],
        ]);

        try {
            $gateway = $save->create($data, $request->user('admin'));
        } catch (PaymentException $e) {
            return back()->withErrors(['driver' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.payments.gateways.show', $gateway)->with('status', 'Gateway created (inactive, sandbox). Add its sandbox credentials next.');
    }

    public function show(PaymentGateway $gateway): View
    {
        $gateway->load('credentials.updatedBy');
        $adapter = $this->registry->adapterFor($gateway);

        return view('admin.payments.gateways.show', [
            'gateway' => $gateway,
            'adapter' => $adapter,
            'problem' => $this->registry->configurationProblem($gateway),
            'liveProblem' => $this->registry->configurationProblem($gateway, GatewayMode::Live),
            'liveEnabled' => PaymentLimits::liveEnabled(),
            'statuses' => GatewayStatus::cases(),
            'modes' => GatewayMode::cases(),
            'credentialChanges' => $gateway->credentialChanges()->with('changedBy')->latest('id')->limit(50)->get(),
        ]);
    }

    public function update(Request $request, PaymentGateway $gateway, SaveGateway $save): RedirectResponse
    {
        $rules = $this->registry->adapterFor($gateway)?->settingsRules() ?? [];
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'wallet_funding' => ['boolean'],
            'settings' => ['nullable', 'array'],
            ...collect($rules)->mapWithKeys(fn ($r, $key) => ["settings.{$key}" => $r])->all(),
        ]);
        $save->update($gateway, $data, $request->user('admin'));

        return back()->with('status', 'Gateway details saved.');
    }

    public function updateStatus(Request $request, PaymentGateway $gateway, SetGatewayStatus $set): RedirectResponse
    {
        $status = GatewayStatus::from($request->validate(['status' => ['required', Rule::enum(GatewayStatus::class)]])['status']);
        $set->handle($gateway, $status, $request->user('admin'));

        return back()->with('status', 'Gateway status set to '.$status->label().'.');
    }

    public function updateMode(Request $request, PaymentGateway $gateway, SetGatewayMode $set): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::enum(GatewayMode::class)],
            'confirm_live' => [Rule::requiredIf($request->input('mode') === GatewayMode::Live->value), 'accepted_if:mode,live'],
        ], ['confirm_live.required' => 'Confirm that this gateway should take real payments.', 'confirm_live.accepted_if' => 'Confirm that this gateway should take real payments.']);

        try {
            $set->handle($gateway, GatewayMode::from($data['mode']), $request->user('admin'));
        } catch (PaymentException $e) {
            return back()->withErrors(['mode' => $e->getMessage()]);
        }

        return back()->with('status', 'Gateway mode set to '.GatewayMode::from($data['mode'])->label().'.');
    }

    public function move(Request $request, PaymentGateway $gateway, SaveGateway $save): RedirectResponse
    {
        $direction = $request->validate(['direction' => ['required', 'in:up,down']])['direction'];
        $moved = $save->move($gateway, $direction, $request->user('admin'));

        return back()->with('status', $moved ? 'Gateway order updated.' : 'This gateway is already '.($direction === 'up' ? 'first.' : 'last.'));
    }

    public function updateCredentials(Request $request, PaymentGateway $gateway, string $mode, SaveGatewayCredentials $save): RedirectResponse
    {
        $keys = $this->registry->adapterFor($gateway)?->credentialKeys() ?? [];
        $values = $request->validate([
            'credentials' => ['nullable', 'array:'.implode(',', $keys)],
            'credentials.*' => ['nullable', 'string', 'max:2000'],
        ])['credentials'] ?? [];

        try {
            $count = $save->handle($gateway, GatewayMode::from($mode), $values, $request->user('admin'));
        } catch (PaymentException $e) {
            return back()->withErrors(['credentials' => $e->getMessage()]);
        }

        return back()->with('status', $count === 0 ? 'No credential values entered; nothing changed.' : "{$count} ".str('credential')->plural($count).' saved.');
    }

    public function clearCredential(Request $request, PaymentGateway $gateway, string $mode, string $key, SaveGatewayCredentials $save): RedirectResponse
    {
        try {
            $cleared = $save->clear($gateway, GatewayMode::from($mode), $key, $request->user('admin'));
        } catch (PaymentException $e) {
            return back()->withErrors(['credentials' => $e->getMessage()]);
        }

        return back()->with('status', $cleared ? 'Credential cleared.' : 'That credential was not set.');
    }
}
