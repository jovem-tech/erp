@extends('errors.layout', ['tone' => 'warning', 'code' => 419])

@section('title', 'Sessão da página expirou')

@section('message')
    A página ficou aberta tempo demais e o envio não pôde ser concluído com segurança.
@endsection

@section('hint')
    Atualize a página e envie sua resposta novamente. Nenhuma decisão foi registrada.
@endsection
