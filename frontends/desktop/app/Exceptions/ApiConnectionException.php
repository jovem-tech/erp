<?php

namespace App\Exceptions;

/**
 * Falha de transporte ao falar com o backend central: DNS, recusa de conexao,
 * TLS ou estouro de timeout — o oposto de uma resposta HTTP de erro, que chega
 * como ApiRequestException com statusCode preenchido.
 *
 * Existe como subclasse (e nao como excecao irma) porque todo o desktop ja
 * trata `catch (ApiRequestException)`: herdar mantem esse tratamento intacto e,
 * ao mesmo tempo, deixa o laco de retentativa distinguir "nao consegui falar
 * com o backend" de "o backend respondeu e recusou" — as duas unicas situacoes
 * em que reexecutar faz sentido, e por motivos diferentes.
 */
class ApiConnectionException extends ApiRequestException
{
}
