@extends('errors.layout', ['tone' => 'warning', 'code' => 410])

@section('title', 'Este link expirou')

@section('message')
    {{-- Mensagem definida no abort(410) dos controllers publicos: e escrita para o cliente ler. --}}
    {{ trim((string) ($exception?->getMessage() ?? '')) !== ''
        ? $exception->getMessage()
        : 'Este link expirou ou foi revogado. Solicite um novo envio à assistência.' }}
@endsection

@section('hint')
    Entre em contato com a assistência pelo mesmo canal em que recebeu esta mensagem
    para receber um link atualizado.
@endsection
