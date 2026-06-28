<?php

namespace App\Support;

/**
 * Helper para mensagens localizadas
 * 
 * Arquivo: app/Support/MessageTranslator.php
 * 
 * Uso:
 *   MessageTranslator::auth('invalid_credentials');    // "Email ou senha inválidos."
 *   MessageTranslator::api('connection_error');        // "Não foi possível conectar ao servidor."
 *   MessageTranslator::validation('required');         // "O campo ... é obrigatório."
 */
class MessageTranslator
{
    /**
     * Obter mensagem de autenticação
     */
    public static function auth(string $key): string
    {
        return __("messages.auth.{$key}");
    }

    /**
     * Obter mensagem de validação
     */
    public static function validation(string $key, array $replace = []): string
    {
        return __("messages.validation.{$key}", $replace);
    }

    /**
     * Obter mensagem de API
     */
    public static function api(string $key): string
    {
        return __("messages.api.{$key}");
    }

    /**
     * Obter mensagem de Ordem de Serviço
     */
    public static function order(string $key): string
    {
        return __("messages.order.{$key}");
    }

    /**
     * Obter mensagem de Cliente
     */
    public static function client(string $key): string
    {
        return __("messages.client.{$key}");
    }

    /**
     * Obter mensagem de Usuário
     */
    public static function user(string $key): string
    {
        return __("messages.user.{$key}");
    }

    /**
     * Obter mensagem de Permissão
     */
    public static function permission(string $key): string
    {
        return __("messages.permission.{$key}");
    }

    /**
     * Obter mensagem de Notificação
     */
    public static function notification(string $key): string
    {
        return __("messages.notification.{$key}");
    }
}
