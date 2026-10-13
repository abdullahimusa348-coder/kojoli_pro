@extends('layouts.admin')

@section('title', $submission->reference.' · KYC review · Admin · '.config('app.name'))
@section('heading', 'KYC review')

@php($card = 'mt-6 max-w-4xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6')
@php($control = 'block w-full rounded-lg border px-3 py-2 text-navy-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')
@php($refusal = $errors->first('refusal') ?: $errors->first('form_key'))

@section('page')
    <a href="{{ route('admin.kyc.index') }}" class="text-sm text-brand-700 hover:underline">← Back to the review queue</a>
    @if (session('status'))
        <x-alert class="mt-4">{{ session('status') }}</x-alert>
    @endif

    <section class="{{ $card }}" aria-labelledby="submission-heading" data-kyc-submission="{{ $submission->reference }}">
        <h2 id="submission-heading" class="break-all font-mono text-base font-semibold text-navy-900">{{ $submission->reference }}</h2>
        <p class="mt-0.5 text-sm text-navy-600">{{ $submission->kind->label() }} · {{ $submission->user_type->label() }} · sent {{ $submission->submitted_at?->format('j M Y, H:i') }}</p>
        <dl class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
            <div><dt class="text-navy-600">Customer</dt><dd class="mt-0.5 min-w-0 text-navy-900" data-kyc-customer>
                @if ($canViewCustomers)
                    <span class="break-words font-medium">{{ $submission->user->name }}</span>
                    <span class="block break-all text-xs text-navy-500">{{ $submission->user->email }} · Customer #{{ $submission->user_id }}</span>
                @else
                    <span class="break-words font-medium">Customer #{{ $submission->user_id }}</span>
                @endif
            </dd></div>
            <div><dt class="text-navy-600">Status</dt><dd class="mt-0.5 text-navy-900" data-kyc-state="{{ $open ? 'open' : 'decided' }}">
                @if ($open)
                    Waiting for a decision.
                @else
                    This submission is no longer waiting for review.
                    @if ($submission->review)
                        Decision: {{ $submission->review->decision->label() }}.
                    @endif
                @endif
            </dd></div>
        </dl>
    </section>

    <section class="{{ $card }}" aria-labelledby="requirements-heading" data-kyc-requirements>
        <h2 id="requirements-heading" class="text-base font-semibold text-navy-900">Requirements</h2>
        <p class="mt-0.5 text-sm text-navy-600">As they were when the customer sent the submission. Identity values are shown masked only.</p>
        <ul class="mt-4 divide-y divide-navy-100" role="list">
            @forelse ($submission->requirements as $requirement)
                <li class="flex flex-col gap-1 py-3 text-sm sm:flex-row sm:items-center sm:justify-between" data-kyc-requirement="{{ $requirement->requirement_key }}">
                    <div class="min-w-0">
                        <p class="break-words font-medium text-navy-900">{{ $requirement->label }}</p>
                        <p class="text-xs text-navy-500">{{ $requirement->requirement_type->label() }}</p>
                    </div>
                    <div class="break-words text-navy-800">
                        @if (in_array($requirement->requirement_type, [\App\Support\Kyc\KycRequirementType::Bvn, \App\Support\Kyc\KycRequirementType::Nin], true))
                            <span data-identity-mask>{{ $identityMasks[$requirement->requirement_key] ?? 'No value on this submission' }}</span>
                        @elseif ($requirement->requirement_type === \App\Support\Kyc\KycRequirementType::Phone)
                            <span data-kyc-phone="{{ $phoneMatch }}">{{ $phoneMatch === 'matches' ? 'Still matches the number sent.' : 'Has changed since it was sent.' }}</span>
                        @else
                            <span>No upload: uploads are not available in this version.</span>
                        @endif
                    </div>
                </li>
            @empty
                <li class="py-3 text-sm text-navy-500">This submission asked for nothing.</li>
            @endforelse
        </ul>
        @if ($phoneMatch === null)
            <p class="mt-3 text-sm text-navy-600" data-kyc-phone="not-requested">No phone number was asked for.</p>
        @endif
    </section>

    <section class="{{ $card }}" aria-labelledby="decision-heading" data-kyc-decision>
        <h2 id="decision-heading" class="text-base font-semibold text-navy-900">Decision</h2>
        @if ($refusal)
            <p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert" data-kyc-refusal>{{ $refusal }}</p>
        @endif

        @if (! $open)
            <p class="mt-2 text-sm text-navy-600">No decision can be recorded for this submission now.</p>
        @elseif (! $canDecide)
            <p class="mt-2 text-sm text-navy-600">Only staff with permission to decide KYC submissions can record a decision here.</p>
        @else
            <p class="mt-2 text-sm text-navy-600">
                An approval is refused unless every requirement is a BVN or a NIN, each with its identity value, and the phone number has not changed.
                A decision is recorded once and cannot be changed.
            </p>
            <form method="POST" action="{{ route('admin.kyc.submissions.decide', $submission) }}" class="mt-5 space-y-5" novalidate data-kyc-decision-form>
                @csrf
                <input type="hidden" name="form_key" value="{{ $formKey }}">

                <fieldset>
                    <legend class="text-sm font-medium text-navy-700">Decision</legend>
                    <div class="mt-2 space-y-2">
                        @foreach ($decisions as $decision)
                            <label class="flex items-center gap-2 text-sm text-navy-800">
                                <input type="radio" name="decision" value="{{ $decision->value }}" class="border-navy-300 text-brand-600 focus:ring-brand-500" @checked(old('decision') === $decision->value)>
                                <span>{{ $decision->label() }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('decision')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </fieldset>

                <div>
                    <label for="decision_reason" class="mb-1 block text-sm font-medium text-navy-700">Reason</label>
                    <textarea id="decision_reason" name="decision_reason" rows="3" @class([$control, 'border-red-400' => $errors->has('decision_reason'), 'border-navy-200' => ! $errors->has('decision_reason')])
                              @if ($errors->has('decision_reason')) aria-invalid="true" aria-describedby="decision-reason-error" @endif></textarea>
                    <p class="mt-1 text-xs text-navy-500">Approval: optional, up to 500 characters. Rejection or more information: 10 to 500 characters, with at least one letter. Do not enter a BVN, NIN or phone number. The reason is shown to the customer.</p>
                    @error('decision_reason')<p id="decision-reason-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="flex items-start gap-2 text-sm text-navy-800">
                        <input type="checkbox" name="confirm" value="1" class="mt-0.5 rounded border-navy-300 text-brand-600 focus:ring-brand-500"
                               @if ($errors->has('confirm')) aria-invalid="true" aria-describedby="decision-confirm-error" @endif>
                        <span>I confirm: record this decision. It cannot be changed.</span>
                    </label>
                    @error('confirm')<p id="decision-confirm-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg bg-navy-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-navy-800 sm:w-auto">Record decision</button>
                </div>
            </form>
        @endif
    </section>
@endsection
