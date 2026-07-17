@extends('layouts.app')

@section('styles')
    <link href="{{ asset('assets/libs/cropperjs/cropper.min.css') }}" rel="stylesheet">
@endsection

@section('content')
    @include('orders._wizard')
@endsection

@section('scripts')
    @include('orders._wizard_scripts')
@endsection
