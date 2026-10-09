<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Providers\SaveProviderCredentials;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Providers\ProviderCredentialsRequest;
use App\Models\Provider;
use App\Support\Providers\CredentialKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Write-only provider credentials (providers.credentials). Responses never contain values. */
class ProviderCredentialController extends Controller
{
    public function update(ProviderCredentialsRequest $request, Provider $provider, SaveProviderCredentials $save): RedirectResponse
    {
        $count = $save->handle($provider, $request->values(), $request->user('admin'));

        return redirect()->route('admin.providers.show', $provider)->withFragment('credentials')
            ->with('status', $count === 0 ? 'No credentials entered; nothing changed.' : "Credentials saved ({$count}).");
    }

    public function clear(Request $request, Provider $provider, string $key, SaveProviderCredentials $save): RedirectResponse
    {
        $credentialKey = CredentialKey::from($key);
        $cleared = $save->clear($provider, $credentialKey, $request->user('admin'));

        return redirect()->route('admin.providers.show', $provider)->withFragment('credentials')
            ->with('status', $cleared ? "{$credentialKey->label()} cleared." : "{$credentialKey->label()} was not set.");
    }
}
