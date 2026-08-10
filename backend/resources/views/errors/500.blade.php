{{-- Sem $exception->getMessage() aqui: a mensagem de uma falha interna pode expor detalhes do sistema. --}}
@extends('errors.layout', ['tone' => 'danger', 'code' => 500])

@section('title', 'Falha ao carregar a página')

@section('message')
    Ocorreu um erro inesperado no servidor. A equipe já foi notificada pelo registro de erros.
@endsection

@section('hint')
    Tente novamente em alguns minutos. Se o problema continuar, avise a assistência.
@endsection
