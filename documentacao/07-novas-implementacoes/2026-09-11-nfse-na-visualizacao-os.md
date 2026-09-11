# NFS-e vinculada na visualização da OS

## Contexto

Uma NFS-e emitida já podia ser consultada na tela fiscal e seus XML/PDF eram
catalogados como documentos, mas a visualização da própria Ordem de Serviço não
mostrava o registro fiscal. Isso obrigava o operador a sair da OS e pesquisar a
nota novamente, mesmo com o vínculo `documentos_fiscais.os_id` já existente.

## Implementação

- `OrderController::show()` consulta o endpoint fiscal existente com `os_id`,
  `tipo=nfse` e estados `emitido,cancelado`;
- o card **Documentos** exibe número, série, chave, data de emissão, valor,
  situação e os atalhos para XML, PDF ou DANFSe;
- a seção não aparece quando a OS não possui nota emitida ou cancelada;
- o histórico cancelado é preservado na OS porque o documento continua a
  existir perante o fisco e pode ter uma nota substituta;
- nenhuma nova rota ou alteração de contrato da API foi necessária.

## Segurança e arquitetura

O frontend não acessa o banco. A consulta continua passando pelo backend
central e só é feita quando a sessão possui `fiscal:visualizar`; os downloads
permanecem mediados por endpoints autenticados. A ação **Emitir nota fiscal**
passa a exigir, também na renderização do desktop, `fiscal:criar`, sem substituir
a autorização definitiva do backend.

## Performance e escalabilidade

A consulta adicional ocorre apenas na página de detalhe da OS e usa o índice
existente `idx_docfiscal_os_tipo (os_id, tipo)`. O resultado é limitado a 100
registros — margem suficiente para substituições/cancelamentos sem consulta não
limitada — e não adiciona chamadas externas nem processamento de arquivo.

## Validação

- teste funcional da tela da OS com NFS-e emitida, incluindo filtro enviado à
  API e atalhos para os arquivos;
- teste negativo garantindo que a API fiscal não é consultada e que dados/ações
  fiscais não são exibidos sem permissão;
- regressão da suíte fiscal e da renderização Blade do desktop.
