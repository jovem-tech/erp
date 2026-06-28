<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIntegrationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'whatsapp_enabled' => ['nullable', 'boolean'],
            'whatsapp_direct_provider' => ['nullable', 'string', 'in:menuia,evolution,api_whats_local,api_whats_linux,webhook'],
            'whatsapp_bulk_provider' => ['nullable', 'string', 'in:meta_oficial,menuia,evolution,api_whats_local,api_whats_linux,webhook'],
            'whatsapp_test_phone' => ['nullable', 'string', 'max:30'],
            'whatsapp_menuia_url' => ['nullable', 'string', 'max:255'],
            'whatsapp_menuia_appkey' => ['nullable', 'string', 'max:255'],
            'whatsapp_menuia_authkey' => ['nullable', 'string', 'max:255'],
            'whatsapp_webhook_token' => ['nullable', 'string', 'max:255'],
            'whatsapp_evolution_url' => ['nullable', 'string', 'max:255'],
            'whatsapp_evolution_apikey' => ['nullable', 'string', 'max:255'],
            'whatsapp_evolution_instance' => ['nullable', 'string', 'max:120'],
            'whatsapp_evolution_timeout' => ['nullable', 'integer', 'min:5', 'max:120'],
            'whatsapp_evolution_sync_avatar' => ['nullable', 'boolean'],
            'whatsapp_local_node_url' => ['nullable', 'string', 'max:255'],
            'whatsapp_local_node_token' => ['nullable', 'string', 'max:255'],
            'whatsapp_local_node_origin' => ['nullable', 'string', 'max:255'],
            'whatsapp_local_node_timeout' => ['nullable', 'integer', 'min:5', 'max:120'],
            'whatsapp_linux_node_url' => ['nullable', 'string', 'max:255'],
            'whatsapp_linux_node_token' => ['nullable', 'string', 'max:255'],
            'whatsapp_linux_node_origin' => ['nullable', 'string', 'max:255'],
            'whatsapp_linux_node_timeout' => ['nullable', 'integer', 'min:5', 'max:120'],
            'whatsapp_webhook_url' => ['nullable', 'string', 'max:255'],
            'whatsapp_webhook_method' => ['nullable', 'string', 'in:GET,POST,PUT,PATCH,DELETE'],
            'whatsapp_webhook_headers' => ['nullable', 'string'],
            'whatsapp_webhook_payload' => ['nullable', 'string'],

            'pagamentos_mercadopago_enabled' => ['nullable', 'boolean'],
            'pagamentos_mercadopago_access_token' => ['nullable', 'string', 'max:255'],
            'pagamentos_mercadopago_public_key' => ['nullable', 'string', 'max:255'],
            'pagamentos_asaas_enabled' => ['nullable', 'boolean'],
            'pagamentos_asaas_base_url' => ['nullable', 'string', 'max:255'],
            'pagamentos_asaas_api_key' => ['nullable', 'string', 'max:255'],
            'pagamentos_asaas_billing_type_default' => ['nullable', 'string', 'in:PIX,BOLETO,CREDIT_CARD'],

            'smtp_host' => ['nullable', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_crypto' => ['nullable', 'string', 'in:auto,tls,ssl,none'],
            'smtp_timeout' => ['nullable', 'integer', 'min:5', 'max:120'],
            'smtp_user' => ['nullable', 'string', 'max:255'],
            'smtp_pass' => ['nullable', 'string', 'max:255'],
            'smtp_from_email' => ['nullable', 'string', 'email', 'max:255'],
            'smtp_from_name' => ['nullable', 'string', 'max:255'],

            'portal_google_client_id' => ['nullable', 'string', 'max:255'],
            'portal_google_client_secret' => ['nullable', 'string', 'max:255'],
        ];
    }
}
