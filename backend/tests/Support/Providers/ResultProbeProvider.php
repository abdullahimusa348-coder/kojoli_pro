<?php

namespace Tests\Support\Providers;

use App\Services\Providers\Contracts\ProviderAdapter;
use App\Services\Providers\Data\ProviderContext;
use App\Services\Providers\Data\ProviderPurchaseRequest;
use App\Services\Providers\Data\ProviderQueryRequest;
use App\Services\Providers\Data\ProviderResult;
use App\Services\Providers\Data\ProviderResultFields;
use App\Support\Providers\CredentialKey;
use App\Support\Providers\ProviderCallType;
use Illuminate\Support\Facades\Log;

/**
 * TEST-ONLY adapter used to test the result contract kit itself
 * (tests/Support/Providers/ResultContract.php) and the result field rules.
 * By default it follows every rule; each static property breaks one rule at
 * a time. Its endpoints and payloads are invented for tests and describe no
 * real provider or real result: status "delivered" means succeeded, with
 * "items" (a list of name and text) as the result and an optional "media"
 * entry that a text-only adapter drops; "rejected" means failed_definite;
 * anything else is unknown. Never registered in config/providers.php.
 */
class ResultProbeProvider implements ProviderAdapter
{
    public const API = 'https://api.result-probe.test';

    /** @var list<string> */
    public static array $services = ['nin', 'bvn'];

    public static bool $canQuery = true;

    /** Reports a delivery as succeeded without its result fields. */
    public static bool $withoutFields = false;

    /** Maps fixed text instead of the delivered items. */
    public static bool $ignoresItems = false;

    /** Puts the first delivered item into the outcome's message. */
    public static bool $itemInMessage = false;

    /** Puts the purchased number into the outcome's provider reference. */
    public static bool $numberInReference = false;

    /** Logs the provider's whole answer. */
    public static bool $logsAnswer = false;

    /** Maps the media entry into the result as one more field. */
    public static bool $mapsMedia = false;

    /** Maps the media entry into the result in short pieces, past the media checks. */
    public static bool $splitsMedia = false;

    /** Treats a server error as delivered. */
    public static bool $deliversOnServerError = false;

    /** The number of the last purchase call (to echo it back, as some answers do). */
    private static ?string $lastNumber = null;

    public static function reset(): void
    {
        self::$services = ['nin', 'bvn'];
        self::$canQuery = true;
        self::$withoutFields = false;
        self::$ignoresItems = false;
        self::$itemInMessage = false;
        self::$numberInReference = false;
        self::$logsAnswer = false;
        self::$mapsMedia = false;
        self::$splitsMedia = false;
        self::$deliversOnServerError = false;
        self::$lastNumber = null;
    }

    public function driver(): string
    {
        return 'result_probe';
    }

    public function label(): string
    {
        return 'Result probe (test only)';
    }

    public function supportedServices(): array
    {
        return self::$services;
    }

    public function credentialKeys(): array
    {
        return [CredentialKey::ApiKey];
    }

    public function apiHosts(): array
    {
        return ['api.result-probe.test'];
    }

    public function timeoutSeconds(): int
    {
        return 20;
    }

    public function purchaseIsIdempotent(): bool
    {
        return false;
    }

    public function canQuery(): bool
    {
        return self::$canQuery;
    }

    public function purchase(ProviderPurchaseRequest $request, ProviderContext $context): ProviderResult
    {
        self::$lastNumber = $request->recipient;
        $response = $context->http->send(ProviderCallType::Purchase, 'POST', self::API.'/order', $this->apiHosts(), $this->timeoutSeconds(), [
            'headers' => ['Authorization' => 'Bearer '.$context->credential(CredentialKey::ApiKey)],
            'json' => ['reference' => $request->requestReference, 'service' => $request->serviceSlug, 'number' => $request->recipient],
        ]);

        return self::map($response->status, $response->json);
    }

    public function query(ProviderQueryRequest $request, ProviderContext $context): ProviderResult
    {
        $response = $context->http->send(ProviderCallType::Query, 'GET', self::API.'/order/'.$request->requestReference, $this->apiHosts(),
            $this->timeoutSeconds(), ['headers' => ['Authorization' => 'Bearer '.$context->credential(CredentialKey::ApiKey)]], retryQuery: true);

        return self::map($response->status, $response->json);
    }

    /** @param  array<mixed>|null  $json */
    private static function map(int $status, ?array $json): ProviderResult
    {
        if (self::$logsAnswer) {
            Log::info('Result probe answer', ['answer' => $json]);
        }
        if (self::$deliversOnServerError && $status >= 500) {
            return ProviderResult::succeeded('RP-5XX', 'Delivered.', new ProviderResultFields([['key' => 'item_1', 'label' => 'Item 1', 'value' => 'probe']]));
        }
        if ($status !== 200 || $json === null) {
            return ProviderResult::unknown('http_'.$status, 'Unexpected provider response.');
        }

        return match ($json['status'] ?? null) {
            'delivered' => self::delivered($json),
            'rejected' => ProviderResult::failedDefinite($json['code'] ?? 'rejected', $json['message'] ?? null, $json['id'] ?? null),
            default => ProviderResult::unknown('undocumented_status', 'Unexpected provider status.', $json['id'] ?? null),
        };
    }

    /** @param  array<mixed>  $json */
    private static function delivered(array $json): ProviderResult
    {
        $items = is_array($json['items'] ?? null) ? array_values($json['items']) : [];
        $fields = [];
        foreach ($items as $i => $item) {
            $fields[] = ['key' => 'item_'.($i + 1), 'label' => (string) ($item['name'] ?? 'Item '.($i + 1)),
                'value' => self::$ignoresItems ? 'fixed probe text' : (string) ($item['text'] ?? '')];
        }
        $media = is_string($json['media'] ?? null) ? $json['media'] : null;
        if ($media !== null && self::$mapsMedia) {
            $fields[] = ['key' => 'media', 'label' => 'Media', 'value' => $media];
        }
        if ($media !== null && self::$splitsMedia) {
            foreach (str_split(preg_replace('/\Adata:[^,]*,/', '', $media), 150) as $i => $piece) {
                $fields[] = ['key' => 'piece_'.($i + 1), 'label' => 'Piece '.($i + 1), 'value' => $piece];
            }
        }

        $reference = self::$numberInReference ? 'RP-'.self::$lastNumber : ($json['id'] ?? null);
        $message = self::$itemInMessage ? 'Delivered: '.($fields[0]['value'] ?? '') : 'Delivered.';

        return ProviderResult::succeeded($reference, $message, self::$withoutFields || $fields === [] ? null : new ProviderResultFields($fields));
    }
}
