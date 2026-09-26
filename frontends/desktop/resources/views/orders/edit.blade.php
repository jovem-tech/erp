@extends('layouts.app')

@section('content')
    @include('orders._wizard')
@endsection

@push('modals')
    @include('orders._status_modal')
    @include('orders._cancel_closure_modal')
@endpush

@section('scripts')
    @include('orders._wizard_scripts')
@endsection
