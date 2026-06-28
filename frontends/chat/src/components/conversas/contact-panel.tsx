import type { ConversationContact } from '@/lib/types';

function initials(name: string | null | undefined): string {
  if (!name) {
    return '?';
  }

  return name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('');
}

type ContactPanelProps = {
  contact: ConversationContact;
  displayId: number;
  onBack?: () => void;
};

export function ContactPanel({ contact, displayId, onBack }: ContactPanelProps) {
  const client = contact.client;
  const name = client?.nome_razao || contact.cliente_nome || contact.nome || contact.telefone || `Conversa #${displayId}`;

  const phones = [client?.telefone1, client?.telefone2, client?.telefone_contato, contact.telefone]
    .map((value) => value?.trim())
    .filter((value, index, array): value is string => Boolean(value) && array.indexOf(value) === index);

  return (
    <div>
      {onBack ? (
        <button type="button" className="button button--ghost button--sm contact-panel__back" onClick={onBack}>
          Voltar
        </button>
      ) : null}

      <div className="contact-panel__avatar">{initials(client?.nome_razao || contact.nome)}</div>
      <div className="contact-panel__name">{name}</div>
      {contact.telefone ? <div className="contact-panel__phone">{contact.telefone}</div> : null}

      <div className="contact-panel__section">
        <div className="contact-panel__label">Conversa</div>
        <p className="muted contact-panel__value">#{displayId}</p>
      </div>

      <div className="contact-panel__section">
        <div className="contact-panel__label">Vínculo ERP</div>
        <p className="muted contact-panel__value">
          {contact.cliente_id ? `Cliente #${contact.cliente_id}` : 'Contato sem cliente vinculado'}
        </p>
      </div>

      <div className="contact-panel__section">
        <div className="contact-panel__label">Documento</div>
        <p className="muted contact-panel__value">{client?.cpf_cnpj || 'Não informado'}</p>
      </div>

      <div className="contact-panel__section">
        <div className="contact-panel__label">Cidade</div>
        <p className="muted contact-panel__value">
          {client?.cidade ? `${client.cidade}${client.uf ? ` / ${client.uf}` : ''}` : 'Não informada'}
        </p>
      </div>

      <div className="contact-panel__section">
        <div className="contact-panel__label">Telefones</div>
        <div className="contact-panel__phone-list">
          {phones.length > 0 ? (
            phones.map((phone) => <span key={phone} className="badge">{phone}</span>)
          ) : (
            <p className="muted contact-panel__value">Nenhum telefone disponível.</p>
          )}
        </div>
      </div>
    </div>
  );
}
