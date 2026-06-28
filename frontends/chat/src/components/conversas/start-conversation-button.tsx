'use client';

import { useDeferredValue, useEffect, useRef, useState, type ChangeEvent, type FormEvent } from 'react';
import { useRouter } from 'next/navigation';
import type { ChatClientSearchResult } from '@/lib/types';
import { apiSearchChatClients, apiStartConversation, ApiError } from '@/lib/api';
import { AttachmentPreviewList } from '@/components/conversas/attachment-preview-list';

type StartMode = 'client' | 'phone';

export function StartConversationButton() {
  const router = useRouter();
  const fileInputRef = useRef<HTMLInputElement | null>(null);
  const [open, setOpen] = useState(false);
  const [mode, setMode] = useState<StartMode>('client');
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<ChatClientSearchResult[]>([]);
  const [selectedClient, setSelectedClient] = useState<ChatClientSearchResult | null>(null);
  const [selectedPhone, setSelectedPhone] = useState('');
  const [telefone, setTelefone] = useState('');
  const [nome, setNome] = useState('');
  const [mensagem, setMensagem] = useState('');
  const [files, setFiles] = useState<File[]>([]);
  const [busy, setBusy] = useState(false);
  const [searching, setSearching] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const deferredQuery = useDeferredValue(query);

  useEffect(() => {
    if (!open || mode !== 'client' || !deferredQuery.trim()) {
      setResults([]);
      return;
    }

    let cancelled = false;
    setSearching(true);

    void apiSearchChatClients(deferredQuery)
      .then((nextResults) => {
        if (!cancelled) {
          setResults(nextResults);
        }
      })
      .catch((searchError) => {
        if (!cancelled) {
          setError(searchError instanceof ApiError ? searchError.message : 'Não foi possível buscar clientes.');
        }
      })
      .finally(() => {
        if (!cancelled) {
          setSearching(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [deferredQuery, mode, open]);

  const reset = (): void => {
    setQuery('');
    setResults([]);
    setSelectedClient(null);
    setSelectedPhone('');
    setTelefone('');
    setNome('');
    setMensagem('');
    setFiles([]);
    setError(null);
    if (fileInputRef.current) {
      fileInputRef.current.value = '';
    }
  };

  const handleClose = (): void => {
    if (busy) {
      return;
    }

    setOpen(false);
    reset();
  };

  const handleFileChange = (event: ChangeEvent<HTMLInputElement>): void => {
    const nextFiles = Array.from(event.target.files ?? []);
    if (nextFiles.length === 0) {
      return;
    }

    setFiles((current) => [...current, ...nextFiles]);
  };

  const handleRemoveFile = (index: number): void => {
    setFiles((current) => current.filter((_, currentIndex) => currentIndex !== index));
  };

  const handleSelectClient = (client: ChatClientSearchResult): void => {
    setSelectedClient(client);
    setSelectedPhone(client.telefone_principal ?? client.telefones[0] ?? '');
    setError(null);
  };

  const handleSubmit = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    if (busy) {
      return;
    }

    setBusy(true);
    setError(null);

    try {
      const conversation = await apiStartConversation(
        mode === 'client'
          ? {
              client_id: selectedClient?.id,
              telefone: selectedPhone || undefined,
              mensagem: mensagem.trim() || undefined,
              attachments: files,
            }
          : {
              telefone,
              nome: nome.trim() || undefined,
              mensagem: mensagem.trim() || undefined,
              attachments: files,
            }
      );

      setOpen(false);
      reset();
      router.push(`/conversas/${conversation.id}`);
    } catch (submitError) {
      setError(submitError instanceof ApiError ? submitError.message : 'Não foi possível iniciar a conversa.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <button type="button" className="button button--primary" onClick={() => setOpen(true)}>
        Nova conversa
      </button>

      {open ? (
        <div className="modal-backdrop" role="presentation" onClick={handleClose}>
          <div
            className="surface modal modal--wide"
            role="dialog"
            aria-modal="true"
            aria-label="Iniciar conversa"
            onClick={(event) => event.stopPropagation()}
          >
            <strong>Nova conversa</strong>
            <p className="muted modal__text">
              Use um cliente do ERP ou um telefone livre para abrir a thread e mandar texto, mídia ou arquivo.
            </p>

            <div className="tab-strip">
              <button
                type="button"
                className={`button button--ghost button--sm${mode === 'client' ? ' is-active' : ''}`}
                onClick={() => setMode('client')}
              >
                Cliente do ERP
              </button>
              <button
                type="button"
                className={`button button--ghost button--sm${mode === 'phone' ? ' is-active' : ''}`}
                onClick={() => setMode('phone')}
              >
                Telefone livre
              </button>
            </div>

            {error ? (
              <div className="notice notice--danger" style={{ marginTop: 12 }}>
                {error}
              </div>
            ) : null}

            <form className="form" style={{ marginTop: 14 }} onSubmit={(event) => void handleSubmit(event)}>
              {mode === 'client' ? (
                <div className="field">
                  <label className="field__label" htmlFor="start-conversation-client-search">
                    Buscar cliente
                  </label>
                  <input
                    id="start-conversation-client-search"
                    className="input"
                    type="search"
                    placeholder="Nome, documento ou telefone"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    disabled={busy}
                  />

                  {searching ? <span className="muted">Buscando clientes...</span> : null}

                  {results.length > 0 ? (
                    <div className="client-search-results">
                      {results.map((client) => (
                        <button
                          key={client.id}
                          type="button"
                          className={`client-search-result${selectedClient?.id === client.id ? ' client-search-result--active' : ''}`}
                          disabled={!client.can_start_conversation}
                          onClick={() => handleSelectClient(client)}
                        >
                          <strong>{client.nome_razao}</strong>
                          <span>{client.cpf_cnpj || 'Documento não informado'}</span>
                          <span>{client.cidade ? `${client.cidade}${client.uf ? ` / ${client.uf}` : ''}` : 'Cidade não informada'}</span>
                          <div className="client-search-result__phones">
                            {client.telefones.map((phone) => (
                              <span key={phone} className="badge">{phone}</span>
                            ))}
                          </div>
                        </button>
                      ))}
                    </div>
                  ) : null}

                  {selectedClient ? (
                    <div className="selected-client-card">
                      <strong>{selectedClient.nome_razao}</strong>
                      <p className="muted">Escolha o telefone para a conversa.</p>
                      <div className="selected-client-card__phones">
                        {selectedClient.telefones.map((phone) => (
                          <button
                            key={phone}
                            type="button"
                            className={`button button--ghost button--sm${selectedPhone === phone ? ' is-active' : ''}`}
                            onClick={() => setSelectedPhone(phone)}
                          >
                            {phone}
                          </button>
                        ))}
                      </div>
                    </div>
                  ) : null}
                </div>
              ) : (
                <>
                  <div className="field">
                    <label className="field__label" htmlFor="start-conversation-telefone">
                      Telefone (WhatsApp)
                    </label>
                    <input
                      id="start-conversation-telefone"
                      className="input"
                      type="tel"
                      placeholder="11912345678"
                      value={telefone}
                      onChange={(event) => setTelefone(event.target.value)}
                      disabled={busy}
                      required
                    />
                  </div>

                  <div className="field">
                    <label className="field__label" htmlFor="start-conversation-nome">
                      Nome (opcional)
                    </label>
                    <input
                      id="start-conversation-nome"
                      className="input"
                      type="text"
                      placeholder="Nome do contato"
                      value={nome}
                      onChange={(event) => setNome(event.target.value)}
                      disabled={busy}
                    />
                  </div>
                </>
              )}

              <div className="field">
                <label className="field__label" htmlFor="start-conversation-mensagem">
                  Mensagem inicial (opcional)
                </label>
                <textarea
                  id="start-conversation-mensagem"
                  className="textarea"
                  placeholder="Digite a primeira mensagem ou abra a conversa vazia."
                  value={mensagem}
                  onChange={(event) => setMensagem(event.target.value)}
                  disabled={busy}
                />
              </div>

              <div className="field">
                <label className="field__label">Anexos</label>
                <div className="new-conversation-upload">
                  <button
                    type="button"
                    className="button button--ghost button--sm"
                    onClick={() => fileInputRef.current?.click()}
                    disabled={busy}
                  >
                    Adicionar mídia/arquivo
                  </button>
                  <input
                    ref={fileInputRef}
                    hidden
                    type="file"
                    multiple
                    onChange={handleFileChange}
                    accept="image/*,audio/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.txt"
                  />
                </div>
                <AttachmentPreviewList files={files} onRemove={handleRemoveFile} />
              </div>

              <div className="modal__actions">
                <button type="button" className="button button--ghost" onClick={handleClose} disabled={busy}>
                  Cancelar
                </button>
                <button
                  type="submit"
                  className="button button--primary"
                  disabled={busy || (mode === 'client' ? !selectedClient || !selectedPhone : !telefone.trim())}
                >
                  {busy ? <span className="spinner" aria-hidden="true" /> : 'Abrir conversa'}
                </button>
              </div>
            </form>
          </div>
        </div>
      ) : null}
    </>
  );
}

export default StartConversationButton;
