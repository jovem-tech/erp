@php
    $assinaturas = count($rotulos) > 0 ? $rotulos : ['Assinatura'];
    $larguraColuna = 100 / count($assinaturas);
@endphp
<table class="pdfe-assinatura">
    <tbody>
    <tr>
        @foreach ($assinaturas as $indice => $rotulo)
            @php
                $detalhe = is_array($detalhes[$indice] ?? null) ? $detalhes[$indice] : [];
                $nome = trim((string) ($detalhe['nome'] ?? ''));
                $funcao = trim((string) ($detalhe['funcao'] ?? ''));
                $assinadaEm = trim((string) ($detalhe['assinada_em'] ?? ''));
            @endphp
            <td style="width: {{ $larguraColuna }}%">
                <div class="imagem-assinatura">
                    @if (($imagens[$indice] ?? '') !== '')
                        <img src="{{ $imagens[$indice] }}" alt="">
                    @endif
                </div>
                <div class="linha">
                    <div class="identificacao">
                        @if ($nome !== '')
                            {{ $nome }}@if ($funcao !== '') - {{ $funcao }}@endif
                        @else
                            {!! $rotulo !!}
                        @endif
                    </div>
                    <div class="data-assinatura muted">
                        @if ($assinadaEm !== '')
                            Data: {{ $assinadaEm }}
                        @elseif ($linhaData)
                            Data: ____/____/________
                        @endif
                    </div>
                </div>
            </td>
        @endforeach
    </tr>
    </tbody>
</table>
