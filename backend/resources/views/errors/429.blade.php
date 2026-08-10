@extends('errors.layout', ['tone' => 'warning', 'code' => 429])

@section('title', 'Muitas tentativas')

@section('message')
    Recebemos requisições demais deste acesso em pouco tempo.
@endsection

@section('hint')
    Aguarde alguns instantes e recarregue a página.
@endsection
