@extends('layouts.app')

@section('content')
    @include('orders._wizard')
@endsection

@section('scripts')
    @include('orders._wizard_scripts')
@endsection
