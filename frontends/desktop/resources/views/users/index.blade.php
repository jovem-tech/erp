@extends('layouts.app')

@section('content')
    @include('users._index-content')
@endsection

@push('modals')
    @include('users._index-modals')
@endpush

@section('scripts')
    @include('users._index-scripts')
@endsection
