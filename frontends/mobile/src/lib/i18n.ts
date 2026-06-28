/**
 * Internacionalização (i18n) - Mensagens Centralizadas
 * 
 * Arquivo: src/lib/i18n.ts
 * 
 * Uso:
 *   import { t } from '@/lib/i18n';
 *   const message = t('auth.invalid_credentials');
 *   const errorMsg = t('api.connection_error');
 */

export type LanguageCode = 'pt_BR' | 'en_US';

interface Messages {
  [key: string]: {
    [key: string]: string | Messages;
  };
}

const messages: Record<LanguageCode, Messages> = {
  pt_BR: {
    auth: {
      unauthorized: 'Não autenticado.',
      invalid_credentials: 'Email ou senha inválidos.',
      session_expired: 'Sua sessão expirou. Faça login novamente.',
      password_required: 'A senha é obrigatória.',
      password_min_length: 'A senha deve ter no mínimo 8 caracteres.',
      password_max_length: 'A senha deve ter no máximo 255 caracteres.',
      password_invalid_current: 'A senha atual não confere.',
      password_confirmation_mismatch: 'A confirmação de senha não confere.',
      email_invalid: 'Email inválido.',
      email_required: 'Email é obrigatório.',
      email_already_exists: 'Este email já está registrado.',
      email_not_found: 'Email não encontrado.',
      login_rate_limited: 'Muitas tentativas de login. Aguarde um pouco e tente novamente.',
      password_reset_link_sent: 'Link de redefinição de senha enviado com sucesso.',
      password_reset_failed: 'Falha ao enviar link de redefinição de senha.',
      password_reset_invalid_token: 'Token de redefinição inválido ou expirado.',
      password_reset_success: 'Senha redefinida com sucesso.',
      logout_success: 'Logout realizado com sucesso.',
      profile_updated: 'Perfil atualizado com sucesso.',
      password_updated: 'Senha alterada com sucesso. Você será desconectado e deverá fazer login novamente.',
      current_password_required: 'A senha atual é obrigatória.',
    },

    validation: {
      required: 'Este campo é obrigatório.',
      email: 'Email inválido.',
      min: 'O campo deve ter no mínimo {min} caracteres.',
      max: 'O campo deve ter no máximo {max} caracteres.',
      confirmed: 'A confirmação não confere.',
      unique: 'Este valor já está em uso.',
      exists: 'Valor inválido.',
      in: 'Valor selecionado inválido.',
      numeric: 'Deve ser numérico.',
      integer: 'Deve ser um número inteiro.',
      regex: 'Formato inválido.',
      file: 'Deve ser um arquivo.',
      image: 'Deve ser uma imagem.',
      mimes: 'Tipo de arquivo não permitido.',
      before: 'Deve ser anterior a {date}.',
      after: 'Deve ser posterior a {date}.',
    },

    api: {
      connection_error: 'Não foi possível conectar ao servidor.',
      server_error: 'Erro interno do servidor.',
      not_found: 'Recurso não encontrado.',
      method_not_allowed: 'Método HTTP não permitido.',
      too_many_requests: 'Muitas requisições. Aguarde um pouco.',
      request_timeout: 'Requisição expirou. Verifique sua conexão.',
      invalid_response: 'Resposta inválida do servidor.',
      unknown_error: 'Erro desconhecido.',
      retry_failed: 'Falha ao conectar após múltiplas tentativas.',
      network_error: 'Erro de conexão de rede.',
    },

    order: {
      not_found: 'Ordem de serviço não encontrada.',
      invalid_status: 'Status de ordem inválido.',
      invalid_flow_state: 'Estado de fluxo inválido.',
      already_started: 'Esta ordem de serviço já foi iniciada.',
      cannot_cancel: 'Não é possível cancelar esta ordem de serviço.',
      cannot_complete: 'Não é possível completar esta ordem de serviço.',
      created_success: 'Ordem de serviço criada com sucesso.',
      updated_success: 'Ordem de serviço atualizada com sucesso.',
      deleted_success: 'Ordem de serviço deletada com sucesso.',
      status_changed: 'Status da ordem alterado com sucesso.',
    },

    client: {
      not_found: 'Cliente não encontrado.',
      already_exists: 'Cliente já existe.',
      created_success: 'Cliente criado com sucesso.',
      updated_success: 'Cliente atualizado com sucesso.',
      deleted_success: 'Cliente deletado com sucesso.',
      invalid_email: 'Email do cliente inválido.',
      invalid_phone: 'Telefone do cliente inválido.',
    },

    user: {
      not_found: 'Usuário não encontrado.',
      already_exists: 'Usuário já existe.',
      created_success: 'Usuário criado com sucesso.',
      updated_success: 'Usuário atualizado com sucesso.',
      deleted_success: 'Usuário deletado com sucesso.',
      inactive: 'Usuário inativo.',
      insufficient_permissions: 'Permissões insuficientes.',
      cannot_delete_self: 'Você não pode deletar a sua própria conta.',
      cannot_deactivate_self: 'Você não pode desativar a sua própria conta.',
    },

    permission: {
      unauthorized: 'Você não tem permissão para acessar este recurso.',
      insufficient_role: 'Sua função não tem permissão para esta ação.',
      action_not_allowed: 'Esta ação não é permitida.',
    },

    notification: {
      success: 'Operação realizada com sucesso.',
      error: 'Erro ao processar a operação.',
      warning: 'Aviso.',
      info: 'Informação.',
    },
  },

  en_US: {
    auth: {
      unauthorized: 'Unauthenticated.',
      invalid_credentials: 'Email or password invalid.',
      session_expired: 'Your session has expired. Please login again.',
      password_required: 'Password is required.',
      password_min_length: 'Password must be at least 8 characters.',
      password_max_length: 'Password must not exceed 255 characters.',
      password_invalid_current: 'Current password does not match.',
      password_confirmation_mismatch: 'Password confirmation does not match.',
      email_invalid: 'Invalid email.',
      email_required: 'Email is required.',
      email_already_exists: 'This email is already registered.',
      email_not_found: 'Email not found.',
      login_rate_limited: 'Too many login attempts. Please try again later.',
      password_reset_link_sent: 'Password reset link sent successfully.',
      password_reset_failed: 'Failed to send password reset link.',
      password_reset_invalid_token: 'Password reset token is invalid or expired.',
      password_reset_success: 'Password reset successfully.',
      logout_success: 'Logged out successfully.',
      profile_updated: 'Profile updated successfully.',
      password_updated: 'Password changed successfully. You will be logged out and must login again.',
      current_password_required: 'Current password is required.',
    },

    validation: {
      required: 'This field is required.',
      email: 'Invalid email.',
      min: 'Field must have at least {min} characters.',
      max: 'Field must not exceed {max} characters.',
      confirmed: 'Confirmation does not match.',
      unique: 'This value is already in use.',
      exists: 'Invalid value.',
      in: 'Selected value is invalid.',
      numeric: 'Must be numeric.',
      integer: 'Must be an integer.',
      regex: 'Invalid format.',
      file: 'Must be a file.',
      image: 'Must be an image.',
      mimes: 'File type not allowed.',
      before: 'Must be before {date}.',
      after: 'Must be after {date}.',
    },

    api: {
      connection_error: 'Could not connect to server.',
      server_error: 'Internal server error.',
      not_found: 'Resource not found.',
      method_not_allowed: 'HTTP method not allowed.',
      too_many_requests: 'Too many requests. Please try again later.',
      request_timeout: 'Request timed out. Check your connection.',
      invalid_response: 'Invalid response from server.',
      unknown_error: 'Unknown error.',
      retry_failed: 'Failed to connect after multiple attempts.',
      network_error: 'Network connection error.',
    },

    order: {
      not_found: 'Order not found.',
      invalid_status: 'Invalid order status.',
      invalid_flow_state: 'Invalid flow state.',
      already_started: 'This order has already been started.',
      cannot_cancel: 'Cannot cancel this order.',
      cannot_complete: 'Cannot complete this order.',
      created_success: 'Order created successfully.',
      updated_success: 'Order updated successfully.',
      deleted_success: 'Order deleted successfully.',
      status_changed: 'Order status changed successfully.',
    },

    client: {
      not_found: 'Client not found.',
      already_exists: 'Client already exists.',
      created_success: 'Client created successfully.',
      updated_success: 'Client updated successfully.',
      deleted_success: 'Client deleted successfully.',
      invalid_email: 'Invalid client email.',
      invalid_phone: 'Invalid client phone.',
    },

    user: {
      not_found: 'User not found.',
      already_exists: 'User already exists.',
      created_success: 'User created successfully.',
      updated_success: 'User updated successfully.',
      deleted_success: 'User deleted successfully.',
      inactive: 'User is inactive.',
      insufficient_permissions: 'Insufficient permissions.',
      cannot_delete_self: 'You cannot delete your own account.',
      cannot_deactivate_self: 'You cannot deactivate your own account.',
    },

    permission: {
      unauthorized: 'You do not have permission to access this resource.',
      insufficient_role: 'Your role does not have permission for this action.',
      action_not_allowed: 'This action is not allowed.',
    },

    notification: {
      success: 'Operation completed successfully.',
      error: 'Error processing the operation.',
      warning: 'Warning.',
      info: 'Information.',
    },
  },
};

let currentLanguage: LanguageCode = 'pt_BR';

/**
 * Definir idioma atual
 */
export function setLanguage(lang: LanguageCode): void {
  if (messages[lang]) {
    currentLanguage = lang;
  }
}

/**
 * Obter idioma atual
 */
export function getLanguage(): LanguageCode {
  return currentLanguage;
}

/**
 * Traduzir uma chave de mensagem
 * 
 * Exemplo:
 *   t('auth.invalid_credentials')  // "Email ou senha inválidos."
 *   t('auth.login_rate_limited')   // "Muitas tentativas de login..."
 *   t('api.connection_error')      // "Não foi possível conectar ao servidor."
 * 
 * Com parâmetros:
 *   t('validation.min', { min: 8 })  // "O campo deve ter no mínimo 8 caracteres."
 */
export function t(key: string, params: Record<string, string | number> = {}): string {
  const keys = key.split('.');
  let value: any = messages[currentLanguage];

  for (const k of keys) {
    if (typeof value === 'object' && value !== null && k in value) {
      value = value[k];
    } else {
      // Chave não encontrada, retornar a chave como fallback
      return key;
    }
  }

  if (typeof value !== 'string') {
    return key;
  }

  // Substituir parâmetros na mensagem
  let result = value;
  for (const [param, paramValue] of Object.entries(params)) {
    result = result.replace(`{${param}}`, String(paramValue));
  }

  return result;
}

/**
 * Obter mensagens para um módulo inteiro
 * 
 * Exemplo:
 *   const authMessages = getMessages('auth');
 *   console.log(authMessages.invalid_credentials);
 */
export function getMessages(module: string): Record<string, string> {
  const moduleMessages = messages[currentLanguage][module];
  if (typeof moduleMessages === 'object' && moduleMessages !== null) {
    return moduleMessages as Record<string, string>;
  }
  return {};
}

/**
 * Verificar se uma chave de mensagem existe
 */
export function hasMessage(key: string): boolean {
  const keys = key.split('.');
  let value: any = messages[currentLanguage];

  for (const k of keys) {
    if (typeof value === 'object' && value !== null && k in value) {
      value = value[k];
    } else {
      return false;
    }
  }

  return typeof value === 'string';
}
