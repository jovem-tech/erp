<?php

/**
 * Mensagens de Erro e Notificação Centralizadas
 * 
 * Arquivo: resources/lang/pt_BR/messages.php
 * 
 * Uso no backend:
 *   __('messages.auth.invalid_credentials')
 *   __('messages.auth.session_expired')
 * 
 * Uso em responses API:
 *   return $this->error(__('messages.auth.invalid_credentials'), 401, 'AUTH_INVALID_CREDENTIALS');
 */

return [
    'auth' => [
        'unauthorized' => 'Não autenticado.',
        'invalid_credentials' => 'Email ou senha inválidos.',
        'session_expired' => 'Sua sessão expirou. Faça login novamente.',
        'password_required' => 'A senha é obrigatória.',
        'password_min_length' => 'A senha deve ter no mínimo 8 caracteres.',
        'password_max_length' => 'A senha deve ter no máximo 255 caracteres.',
        'password_invalid_current' => 'A senha atual não confere.',
        'password_confirmation_mismatch' => 'A confirmação de senha não confere.',
        'email_invalid' => 'Email inválido.',
        'email_required' => 'Email é obrigatório.',
        'email_already_exists' => 'Este email já está registrado.',
        'email_not_found' => 'Email não encontrado.',
        'login_rate_limited' => 'Muitas tentativas de login. Aguarde um pouco e tente novamente.',
        'password_reset_link_sent' => 'Link de redefinição de senha enviado com sucesso.',
        'password_reset_failed' => 'Falha ao enviar link de redefinição de senha.',
        'password_reset_invalid_token' => 'Token de redefinição inválido ou expirado.',
        'password_reset_success' => 'Senha redefinida com sucesso.',
        'logout_success' => 'Logout realizado com sucesso.',
        'profile_updated' => 'Perfil atualizado com sucesso.',
        'password_updated' => 'Senha alterada com sucesso. Faça login novamente.',
        'current_password_required' => 'A senha atual é obrigatória.',
        'requires_relogin' => 'Você será desconectado e deverá fazer login novamente.',
    ],

    'validation' => [
        'required' => 'O campo :attribute é obrigatório.',
        'email' => 'O campo :attribute deve ser um email válido.',
        'min' => 'O campo :attribute deve ter no mínimo :min caracteres.',
        'max' => 'O campo :attribute deve ter no máximo :max caracteres.',
        'confirmed' => 'A confirmação de :attribute não confere.',
        'unique' => 'O valor de :attribute já está em uso.',
        'exists' => 'O valor selecionado para :attribute é inválido.',
        'in' => 'O valor selecionado para :attribute é inválido.',
        'numeric' => 'O campo :attribute deve ser numérico.',
        'integer' => 'O campo :attribute deve ser um número inteiro.',
        'regex' => 'O campo :attribute está em formato inválido.',
        'file' => 'O campo :attribute deve ser um arquivo.',
        'image' => 'O campo :attribute deve ser uma imagem.',
        'mimes' => 'O campo :attribute deve ser um arquivo do tipo: :values.',
        'before' => 'O campo :attribute deve ser anterior a :date.',
        'after' => 'O campo :attribute deve ser posterior a :date.',
    ],

    'api' => [
        'connection_error' => 'Não foi possível conectar ao servidor.',
        'server_error' => 'Erro interno do servidor.',
        'not_found' => 'Recurso não encontrado.',
        'method_not_allowed' => 'Método HTTP não permitido.',
        'too_many_requests' => 'Muitas requisições. Aguarde um pouco.',
        'request_timeout' => 'Requisição expirou. Verifique sua conexão.',
        'invalid_response' => 'Resposta inválida do servidor.',
        'unknown_error' => 'Erro desconhecido.',
        'retry_failed' => 'Falha ao conectar após múltiplas tentativas.',
        'network_error' => 'Erro de conexão de rede.',
    ],

    'order' => [
        'not_found' => 'Ordem de serviço não encontrada.',
        'invalid_status' => 'Status de ordem inválido.',
        'invalid_flow_state' => 'Estado de fluxo inválido.',
        'already_started' => 'Esta ordem de serviço já foi iniciada.',
        'cannot_cancel' => 'Não é possível cancelar esta ordem de serviço.',
        'cannot_complete' => 'Não é possível completar esta ordem de serviço.',
        'created_success' => 'Ordem de serviço criada com sucesso.',
        'updated_success' => 'Ordem de serviço atualizada com sucesso.',
        'deleted_success' => 'Ordem de serviço deletada com sucesso.',
        'status_changed' => 'Status da ordem alterado com sucesso.',
    ],

    'client' => [
        'not_found' => 'Cliente não encontrado.',
        'already_exists' => 'Cliente já existe.',
        'created_success' => 'Cliente criado com sucesso.',
        'updated_success' => 'Cliente atualizado com sucesso.',
        'deleted_success' => 'Cliente deletado com sucesso.',
        'invalid_email' => 'Email do cliente inválido.',
        'invalid_phone' => 'Telefone do cliente inválido.',
    ],

    'user' => [
        'not_found' => 'Usuário não encontrado.',
        'already_exists' => 'Usuário já existe.',
        'created_success' => 'Usuário criado com sucesso.',
        'updated_success' => 'Usuário atualizado com sucesso.',
        'deleted_success' => 'Usuário deletado com sucesso.',
        'inactive' => 'Usuário inativo.',
        'insufficient_permissions' => 'Permissões insuficientes.',
        'cannot_delete_self' => 'Você não pode deletar a sua própria conta.',
        'cannot_deactivate_self' => 'Você não pode desativar a sua própria conta.',
    ],

    'permission' => [
        'unauthorized' => 'Você não tem permissão para acessar este recurso.',
        'insufficient_role' => 'Sua função não tem permissão para esta ação.',
        'action_not_allowed' => 'Esta ação não é permitida.',
    ],

    'notification' => [
        'success' => 'Operação realizada com sucesso.',
        'error' => 'Erro ao processar a operação.',
        'warning' => 'Aviso.',
        'info' => 'Informação.',
    ],
];
