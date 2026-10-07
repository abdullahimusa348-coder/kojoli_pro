@extends('layouts.admin')

@section('title', 'Commissions · Referral & Commission · Admin · '.config('app.name'))
@section('heading', 'Referral & Commission')

@section('page')
    @include('admin.referrals.tabs')
    <p class="mt-3 max-w-3xl text-sm text-navy-600">Referral commissions credited to referrers for their referred customers' successful purchases of the qualifying services.</p>

    <div class="mt-6 rounded-2xl bg-white px-5 py-12 text-center shadow-sm ring-1 ring-navy-100" data-commissions-empty>
        <p class="text-sm font-medium text-navy-800">No commissions yet</p>
        <p class="mx-auto mt-1 max-w-md text-sm text-navy-500">Referral commissions are not being paid yet. Rates and caps can be prepared in the Rates &amp; caps tab.</p>
    </div>
@endsection
