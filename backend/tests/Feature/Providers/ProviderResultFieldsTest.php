<?php

use App\Models\Provider;
use App\Services\Providers\Data\ProviderContext;
use App\Services\Providers\Data\ProviderPurchaseRequest;
use App\Services\Providers\Data\ProviderResult;
use App\Services\Providers\Data\ProviderResultFields;
use App\Services\Providers\ProviderCaller;
use App\Services\Providers\ProviderHttpClient;
use App\Support\Providers\ProviderCallType;
use App\Support\Providers\ProviderOutcome;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Tests\Support\Providers\ResultProbeProvider;

/*
 * Phase 11 CP2: the provider result contract. ProviderResultFields takes only
 * the approved shape and limits (D5) and never prints its values; a
 * ProviderResult carries fields only when it succeeded; an adapter that builds
 * an invalid set comes out of ProviderCaller as unknown, so it is never
 * stored. Every value here is a neutral test fixture generated when the test
 * runs (D1): never identity data.
 */

beforeEach(function () {
    ResultProbeProvider::reset();
    Http::preventStrayRequests();
});

/** A neutral fixture field with a random value (or the given one). */
function prfField(int $i = 1, ?string $value = null): array
{
    return ['key' => "fixture_{$i}", 'label' => "Fixture {$i}", 'value' => $value ?? 'FIXTURE-'.Str::upper(Str::random(16))];
}

/** @return list<array{key: string, label: string, value: string}> */
function prfFields(int $count): array
{
    return array_map(fn (int $i) => prfField($i), range(1, $count));
}

/** The refusal message for $fields, or null when they are accepted. */
function prfRefusal(array $fields): ?string
{
    try {
        new ProviderResultFields($fields);
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return null;
}

/** What dump() / dd() would print (Symfony VarDumper, no colours). */
function prfDumped(mixed $value): string
{
    $dumper = new CliDumper;
    $dumper->setColors(false);

    return (string) $dumper->dump((new VarCloner)->cloneVar($value), true);
}

/** Every way a value could be printed by mistake: debug output, exports, dumps, casts and JSON. */
function prfPrinted(object $value): string
{
    ob_start();
    var_dump($value);
    $dumped = ob_get_clean();

    return implode("\n", [print_r($value, true), var_export($value, true), $dumped, prfDumped($value), json_encode($value),
        json_encode((array) $value), var_export((array) $value, true), json_encode(get_object_vars($value))]);
}

describe('shape and limits', function () {
    it('accepts 1 to 50 text fields with unique keys, keeping their order and exact text', function () {
        $one = [prfField()];
        $fifty = prfFields(50);
        $edges = [
            ['key' => 'a', 'label' => str_repeat('L', 100), 'value' => ''],
            ['key' => 'a'.str_repeat('b', 49), 'label' => str_repeat('é', 100), 'value' => trim(str_repeat('fixture ', 125))],
            ['key' => 'fixture_9', 'label' => 'Fixture — ü', 'value' => str_repeat('ü', 1000)],
            ['key' => 'z0_', 'label' => 'Metadata', 'value' => 'metadata: none. Data: 5 entries, '.str_repeat('A', 199)],
        ];

        expect((new ProviderResultFields($one))->all())->toBe($one)
            ->and(count(new ProviderResultFields($one)))->toBe(1)
            ->and((new ProviderResultFields($fifty))->all())->toBe($fifty)
            ->and((new ProviderResultFields($fifty))->count())->toBe(50)
            ->and((new ProviderResultFields($edges))->all())->toBe($edges)
            ->and((new ProviderResultFields([['value' => 'v', 'label' => 'l', 'key' => 'k']]))->all())->toBe([['key' => 'k', 'label' => 'l', 'value' => 'v']]);
    });

    it('refuses a set that is not a list of 1 to 50 fields', function (Closure $fields) {
        expect(prfRefusal($fields()))->toBe('A result has 1 to 50 fields.');
    })->with([
        'no fields' => [fn () => []],
        '51 fields' => [fn () => prfFields(51)],
        'keyed, not a list' => [fn () => ['first' => prfField()]],
        'a list with a gap' => [fn () => [0 => prfField(1), 2 => prfField(2)]],
    ]);

    it('refuses a field without exactly a key, a label and a value', function (mixed $field) {
        expect(prfRefusal([prfField(1), $field]))->toBe('Result field 2 needs exactly a key, a label and a value.');
    })->with([
        'no value' => [['key' => 'fixture_2', 'label' => 'Fixture 2']],
        'an extra entry' => [['key' => 'fixture_2', 'label' => 'Fixture 2', 'value' => 'x', 'type' => 'text']],
        'a renamed entry' => [['Key' => 'fixture_2', 'label' => 'Fixture 2', 'value' => 'x']],
        'not an array' => ['fixture_2'],
        'a list' => [['fixture_2', 'Fixture 2', 'x']],
        'null' => [null],
    ]);

    it('refuses invalid keys', function (mixed $key) {
        expect(prfRefusal([prfField(1), ['key' => $key, 'label' => 'Fixture 2', 'value' => 'x']]))->toBe('Result field 2 has an invalid key.');
    })->with([
        'uppercase' => ['Fixture_2'],
        'starts with a digit' => ['2fixture'],
        'starts with an underscore' => ['_fixture'],
        'a dash' => ['fixture-2'],
        'a space' => ['fixture 2'],
        'a dot' => ['fixture.2'],
        'empty' => [''],
        '51 characters' => ['a'.str_repeat('b', 50)],
        'a trailing newline' => ["fixture_2\n"],
        'not ASCII' => ['fïxture'],
        'a number' => [2],
        'null' => [null],
    ]);

    it('refuses a repeated key', function () {
        expect(prfRefusal([prfField(1), prfField(2), ['key' => 'fixture_1', 'label' => 'Again', 'value' => 'x']]))->toBe('Result field 3 repeats a key.');
    });

    it('refuses labels that are empty, blank, too long, not text or carry control characters', function (mixed $label) {
        expect(prfRefusal([prfField(1), ['key' => 'fixture_2', 'label' => $label, 'value' => 'x']]))
            ->toBe('Result field 2 needs a label of 1 to 100 characters.');
    })->with([
        'empty' => [''],
        'blank' => ['   '],
        '101 characters' => [str_repeat('L', 101)],
        '101 multibyte characters' => [str_repeat('é', 101)],
        'a newline' => ["Fixture\n2"],
        'a tab' => ["Fixture\t2"],
        'a NUL byte' => ["Fixture\x002"],
        'an escape sequence' => ["\x1b[31mFixture"],
        'a DEL character' => ["Fixture\x7f"],
        'a C1 control character' => ["Fixture\u{0085}2"],
        'invalid UTF-8' => ["Fixture \xC3\x28"],
        'a number' => [2],
        'null' => [null],
    ]);

    it('refuses values that are too long, not text or carry control characters', function (mixed $value) {
        expect(prfRefusal([prfField(1), ['key' => 'fixture_2', 'label' => 'Fixture 2', 'value' => $value]]))
            ->toBe('Result field 2 needs a text value of up to 1000 characters.');
    })->with([
        '1,001 characters' => [str_repeat('v ', 500).'v'],
        '1,001 multibyte characters' => [str_repeat('ü', 1001)],
        'a newline' => ["fixture\nvalue"],
        'a carriage return' => ["fixture\rvalue"],
        'a tab' => ["fixture\tvalue"],
        'a NUL byte' => ["fixture\x00value"],
        'a DEL character' => ["fixture\x7fvalue"],
        'a C1 control character' => ["fixture\u{009B}value"],
        'invalid UTF-8' => ["fixture \xFF value"],
        'a number' => [12345],
        'an array' => [['fixture']],
        'null' => [null],
    ]);

    it('refuses media: data URIs and base64-like payloads, anywhere in a value', function (Closure $value) {
        expect(prfRefusal([prfField(1), ['key' => 'fixture_2', 'label' => 'Fixture 2', 'value' => $value()]]))
            ->toBe('Result field 2 looks like media, which is never stored.');
    })->with([
        'an image data URI' => [fn () => 'data:image/png;base64,'.base64_encode(random_bytes(30))],
        'an uppercase data URI' => [fn () => 'DATA:text/plain,fixture'],
        'a bare data URI in text' => [fn () => 'see data:,fixture'],
        'a data URI with parameters' => [fn () => 'data:application/pdf;name=fixture.pdf;base64,AAAA'],
        'raw base64 (200 characters)' => [fn () => base64_encode(random_bytes(150))],
        'URL-safe base64' => [fn () => strtr(base64_encode(random_bytes(300)), '+/', '-_')],
        'base64 inside text' => [fn () => 'fixture '.base64_encode(random_bytes(300)).' end'],
        'a 200-character run' => [fn () => str_repeat('A', 200)],
    ]);

    it('names the failing field by position only, never by its text', function () {
        $marker = 'FIXTURE-'.Str::upper(Str::random(20));
        $broken = [
            [['key' => 'fixture_1', 'label' => 'Fixture 1', 'value' => $marker."\n"]],
            [['key' => 'fixture_1', 'label' => $marker.str_repeat('x', 100), 'value' => 'x']],
            [['key' => strtolower($marker).'-', 'label' => 'Fixture 1', 'value' => 'x']],
            [['key' => 'fixture_1', 'label' => 'Fixture 1', 'value' => 'data:,'.$marker]],
            [['key' => 'fixture_1', 'label' => 'Fixture 1', 'value' => str_repeat($marker, 60)]],
        ];

        foreach ($broken as $fields) {
            try {
                new ProviderResultFields($fields);
                $this->fail('The fields were accepted.');
            } catch (InvalidArgumentException $e) {
                expect($e->getMessage())->toStartWith('Result field 1 ')
                    ->not->toContain($marker)->not->toContain(strtolower($marker))
                    ->and($e->getTraceAsString())->not->toContain($marker)
                    ->and(json_encode($e->getTrace()))->not->toContain($marker);
            }
        }
    });
});

describe('never printable', function () {
    it('shows only the field count in debug output, exports, dumps, casts and JSON', function () {
        $fields = new ProviderResultFields(prfFields(3));
        $values = array_column($fields->all(), 'value');
        $printed = prfPrinted($fields);

        expect(json_encode($fields))->toBe('{"fields":3}')
            ->and(print_r($fields, true))->toContain('[fields] => 3')
            ->and(get_object_vars($fields))->toBe([]);
        foreach ($values as $value) {
            expect($printed)->not->toContain($value);
        }
    });

    it('cannot be serialised or cloned', function () {
        $fields = new ProviderResultFields(prfFields(2));

        expect(fn () => serialize($fields))->toThrow(LogicException::class, 'Provider result fields are never serialised.')
            ->and(fn () => serialize(ProviderResult::succeeded('FX-1', 'Delivered.', $fields)))->toThrow(LogicException::class)
            ->and(fn () => clone $fields)->toThrow(Error::class);
    });

    it('carries result fields only on a succeeded outcome, shown as a count', function () {
        $fields = new ProviderResultFields(prfFields(2));
        $values = array_column($fields->all(), 'value');
        $result = ProviderResult::succeeded('FX-1', 'Delivered.', $fields);
        $printed = prfPrinted($result);

        expect($result->fields)->toBe($fields)
            ->and(ProviderResult::succeeded('FX-2')->fields)->toBeNull()
            ->and(ProviderResult::failedDefinite('declined')->fields)->toBeNull()
            ->and(ProviderResult::unknown('pending')->fields)->toBeNull()
            ->and(json_encode($result))->toBe('{"outcome":"succeeded","providerReference":"FX-1","message":"Delivered.","errorCode":null,"fields":2}')
            ->and(json_encode(ProviderResult::unknown('pending', 'Still processing.')))
            ->toBe('{"outcome":"unknown","providerReference":null,"message":"Still processing.","errorCode":"pending","fields":null}');
        foreach ($values as $value) {
            expect($printed)->not->toContain($value);
        }

        // Even past the named constructors, an outcome other than succeeded refuses fields.
        foreach ([ProviderOutcome::Unknown, ProviderOutcome::FailedDefinite] as $outcome) {
            $bare = (new ReflectionClass(ProviderResult::class))->newInstanceWithoutConstructor();
            expect(fn () => (new ReflectionMethod(ProviderResult::class, '__construct'))->invoke($bare, $outcome, null, null, null, $fields))
                ->toThrow(LogicException::class, 'Only a succeeded outcome carries result fields.');
        }
    });
});

describe('invalid results from an adapter', function () {
    it('becomes unknown through ProviderCaller, so it is never stored, logging only the error class', function (Closure $items) {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        });
        $marker = 'FIXTURE-'.Str::upper(Str::random(20));
        Http::fake(['https://api.result-probe.test/*' => Http::response(['status' => 'delivered', 'id' => 'RP-1', 'items' => $items($marker)])]);
        $provider = (new Provider)->forceFill(['code' => 'result-probe', 'name' => 'Result probe', 'settings' => []]);
        $context = new ProviderContext($provider, ['api_key' => 'result-probe-test-key'], app(ProviderHttpClient::class));

        $result = app(ProviderCaller::class)->purchase(new ResultProbeProvider,
            new ProviderPurchaseRequest('PRA-FIXTURE', 'nin', null, 'FIXTURE-PLAN', (string) random_int(10_000_000_000, 99_999_999_999), 15_000, null, 'nin'),
            $context);

        expect($result->outcome)->toBe(ProviderOutcome::Unknown)
            ->and($result->errorCode)->toBe('adapter_error')
            ->and($result->fields)->toBeNull()
            ->and($logged)->toBe(['Provider adapter error {"driver":"result_probe","call":"purchase","exception":"InvalidArgumentException"}'])
            ->and(json_encode($result))->not->toContain($marker);
    })->with([
        'a control character' => [fn (string $marker) => [['name' => 'Fixture 1', 'text' => $marker."\x00"]]],
        'a value over 1,000 characters' => [fn (string $marker) => [['name' => 'Fixture 1', 'text' => $marker.str_repeat(' x', 500)]]],
        'an empty label' => [fn (string $marker) => [['name' => ' ', 'text' => $marker]]],
        'media' => [fn (string $marker) => [['name' => 'Fixture 1', 'text' => 'data:image/png;base64,'.base64_encode($marker)]]],
        'more than 50 fields' => [fn (string $marker) => array_fill(0, 51, ['name' => 'Fixture', 'text' => $marker])],
    ]);
});

describe('provider response logging', function () {
    it('redacts identity-result keys, omits long values and masks long numbers, whatever their JSON type', function () {
        $long = 'FIXTURE-'.str_repeat('x', 193);
        $answer = ['status' => 'error', 'code' => 'E42', 'amount' => 15000, 'note' => 'ok', 'edge' => str_repeat('y', 200),
            'date_of_birth' => 'FIXTURE-1', 'dob' => 'FIXTURE-2', 'birth_place' => 'FIXTURE-3', 'gender' => 'FIXTURE-4', 'sex' => 'FIXTURE-5',
            'photo' => 'FIXTURE-6', 'image_url' => 'FIXTURE-7', 'picture' => 'FIXTURE-8', 'base64' => 'FIXTURE-9', 'residence_state' => 'FIXTURE-10',
            'nationality' => 'FIXTURE-11', 'marital_status' => 'FIXTURE-12', 'religion' => 'FIXTURE-13', 'tracking_id' => 'FIXTURE-14',
            'document_no' => 'FIXTURE-15', 'slip' => 'FIXTURE-16', 'serial_number' => 'FIXTURE-17', 'id_number' => 12345678901, 'ratio' => 12345678.5,
            'blob' => $long, 'nested' => ['DateOfBirth' => 'FIXTURE-18', 'Gender' => 'FIXTURE-19', 'items' => [$long, 'short', 'ref 12345678901']]];
        $expected = ['status' => 'error', 'code' => 'E42', 'amount' => 15000, 'note' => 'ok', 'edge' => str_repeat('y', 200),
            ...array_fill_keys(['date_of_birth', 'dob', 'birth_place', 'gender', 'sex', 'photo', 'image_url', 'picture', 'base64', 'residence_state',
                'nationality', 'marital_status', 'religion', 'tracking_id', 'document_no', 'slip', 'serial_number'], '[redacted]'),
            'id_number' => '[number]', 'ratio' => '[number].5', 'blob' => '[omitted]',
            'nested' => ['DateOfBirth' => '[redacted]', 'Gender' => '[redacted]', 'items' => ['[omitted]', 'short', 'ref [number]']]];

        expect(ProviderHttpClient::redact($answer))->toBe($expected);

        Log::spy();
        Http::fake(['*' => Http::response($answer, 500)]);
        app(ProviderHttpClient::class)->send(ProviderCallType::Purchase, 'POST', 'https://api.result-probe.test/order', ['api.result-probe.test'], 20,
            ['json' => ['reference' => 'PRA-FIXTURE']]);

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($expected, $long) {
            $logged = json_encode($context);

            return $context['detail'] === $expected && ! str_contains($logged, 'FIXTURE-') && ! str_contains($logged, $long)
                && ! str_contains($logged, '12345678901');
        });
    });
});
