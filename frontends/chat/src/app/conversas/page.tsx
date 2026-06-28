export default function ConversasIndexPage() {
  return (
    <>
      <div className="chat-column chat-column--thread chat-thread-placeholder">
        <div className="empty-state" style={{ maxWidth: 380 }}>
          <strong>Selecione uma conversa</strong>
          <p>No celular, toque em uma thread da lista. No desktop, a conversa abre ao lado.</p>
        </div>
      </div>

      <div className="chat-column chat-column--contact chat-contact-placeholder">
        <div className="empty-state">
          <strong>Perfil do contato</strong>
          <p>Abra uma conversa para ver o contexto do cliente do ERP e os telefones vinculados.</p>
        </div>
      </div>
    </>
  );
}
