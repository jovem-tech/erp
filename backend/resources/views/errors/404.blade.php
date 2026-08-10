@extends('errors.layout', ['tone' => 'primary', 'code' => 404])

@section('title', 'Página não encontrada')

@section('message')
    O endereço acessado não existe ou o link não é mais válido.
@endsection

@section('hint')
    Se você chegou aqui por um link enviado pela assistência, confira se ele foi copiado
    por inteiro ou solicite um novo envio.
@endsection
