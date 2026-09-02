{{--
    Bloco de pessoa do DANFSe: tomador/adquirente, destinatario e
    intermediario da operacao. Os tres tem a mesma forma no Anexo I da NT-008 —
    muda so' o titulo e a presenca do indicador municipal, que o destinatario
    nao tem (item 2.1.5).

    Quando a pessoa nao foi identificada na NFS-e, o bloco inteiro vira uma
    linha unica (item 2.3.1 e nota 2); o espaco liberado ja' foi devolvido as
    informacoes complementares por DanfseLayout::alturas().
--}}
@if ($pessoa['suprimido'])
    <table class="bloco">
        <tr class="h23"><td colspan="4" class="linha-unica">{{ $pessoa['aviso'] }}</td></tr>
    </table>
@else
    <table class="bloco">
        <tr class="h63">
            <td class="titulo-bloco" style="width:25%">{{ $titulo }}</td>
            <td style="width:25%">{!! $campo('CNPJ / CPF / NIF', $pessoa['documento']) !!}</td>
            <td style="width:25%">
                @if ($comIm)
                    {!! $campo('Indicador Municipal (Inscrição)', $pessoa['im']) !!}
                @endif
            </td>
            <td style="width:25%">{!! $campo('Telefone', $pessoa['fone']) !!}</td>
        </tr>
        <tr class="h63">
            <td colspan="2">{!! $campo('Nome / Nome Empresarial', $pessoa['nome']) !!}</td>
            <td>{!! $campo('Município / Sigla UF', $pessoa['municipio']) !!}</td>
            <td>{!! $campo('Código IBGE / CEP', $pessoa['ibge_cep']) !!}</td>
        </tr>
        <tr class="h63">
            <td colspan="2">{!! $campo('Endereço', $pessoa['endereco']) !!}</td>
            <td colspan="2">{!! $campo('E-mail', $pessoa['email']) !!}</td>
        </tr>
    </table>
@endif
