{{-- What the provider delivered for the buyer's own NIN/BVN purchase (Phase 11 CP3) or Exam PIN purchase (CP4, such as the PIN
     and its serial): only on the owner's result page, never in lists or on staff pages. Every value is escaped; a result that
     cannot be read shows only a neutral note. --}}
<section class="mt-5 border-t border-navy-100 pt-4" aria-labelledby="result-heading-fields" data-result-section>
    <h2 id="result-heading-fields" class="text-base font-semibold text-navy-900">Your result</h2>
    @if ($fields === null)
        <p class="mt-2 text-sm text-navy-700" data-result-unavailable>The result for this purchase can’t be shown right now.</p>
    @else
        <dl class="mt-3 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2" data-result-fields>
            @foreach ($fields as $field)
                <div data-result-field="{{ $field['key'] }}"><dt class="text-navy-600">{{ $field['label'] }}</dt><dd class="mt-0.5 break-words font-medium text-navy-900">{{ $field['value'] }}</dd></div>
            @endforeach
        </dl>
    @endif
</section>
