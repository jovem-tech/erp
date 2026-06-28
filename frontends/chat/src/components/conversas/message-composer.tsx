'use client';

import { useRef, useState, type ChangeEvent, type FormEvent, type KeyboardEvent } from 'react';
import { AttachmentPreviewList } from '@/components/conversas/attachment-preview-list';

type MessageComposerProps = {
  onSend: (payload: { text: string; attachments: File[] }) => Promise<void>;
};

export function MessageComposer({ onSend }: MessageComposerProps) {
  const fileInputRef = useRef<HTMLInputElement | null>(null);
  const [text, setText] = useState('');
  const [files, setFiles] = useState<File[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (): Promise<void> => {
    const trimmed = text.trim();
    if ((!trimmed && files.length === 0) || busy) {
      return;
    }

    setBusy(true);
    setError(null);

    try {
      await onSend({ text: trimmed, attachments: files });
      setText('');
      setFiles([]);
      if (fileInputRef.current) {
        fileInputRef.current.value = '';
      }
    } catch {
      setError('Não foi possível enviar a mensagem. Tente novamente.');
    } finally {
      setBusy(false);
    }
  };

  const handleSubmit = (event: FormEvent): void => {
    event.preventDefault();
    void submit();
  };

  const handleKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>): void => {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      void submit();
    }
  };

  const handleFiles = (event: ChangeEvent<HTMLInputElement>): void => {
    const nextFiles = Array.from(event.target.files ?? []);
    if (nextFiles.length === 0) {
      return;
    }

    setFiles((current) => [...current, ...nextFiles]);
  };

  const removeFile = (index: number): void => {
    setFiles((current) => current.filter((_, currentIndex) => currentIndex !== index));
  };

  return (
    <div className="composer-shell">
      {error ? (
        <div className="notice notice--danger" style={{ margin: '0 18px 10px' }}>
          {error}
        </div>
      ) : null}

      <AttachmentPreviewList files={files} onRemove={removeFile} />

      <form className="message-composer" onSubmit={handleSubmit}>
        <button
          type="button"
          className="button button--ghost button--icon"
          onClick={() => fileInputRef.current?.click()}
          disabled={busy}
          aria-label="Adicionar anexo"
        >
          +
        </button>

        <input
          ref={fileInputRef}
          hidden
          type="file"
          multiple
          onChange={handleFiles}
          accept="image/*,audio/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.txt"
        />

        <textarea
          className="textarea"
          value={text}
          onChange={(event) => setText(event.target.value)}
          onKeyDown={handleKeyDown}
          placeholder="Digite uma resposta..."
          disabled={busy}
        />

        <button type="submit" className="button button--primary" disabled={busy || (!text.trim() && files.length === 0)}>
          {busy ? <span className="spinner" aria-hidden="true" /> : 'Enviar'}
        </button>
      </form>
    </div>
  );
}
