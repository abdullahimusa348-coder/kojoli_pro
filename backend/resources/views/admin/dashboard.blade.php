@extends('layouts.admin')

@section('title', 'Admin · '.config('app.name'))

@section('page')
    <h1 class="text-2xl font-semibold text-navy-900">Admin area</h1>
    <p class="mt-2 text-navy-700">Signed in as {{ $staff->name }} ({{ $staff->roleLabels() }}). The admin dashboard is built in Phase 3.</p>
@endsection
