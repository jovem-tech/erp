# Aba Informações técnicas do equipamento

## Objetivo

Separar as informações coletadas durante a manutenção dos dados necessários no cadastro inicial do equipamento.

## Comportamento implementado

- A aba **Informações técnicas** aparece depois de **Fotos** somente ao editar um equipamento existente.
- **Coletor de hardware** e **Painel técnico** foram movidos para essa nova aba.
- A aba e os dois blocos técnicos são exibidos somente para equipamentos das famílias **Desktop** e **Notebook**.
- Ao trocar o tipo durante a edição, a aba aparece ou desaparece imediatamente; se ela estiver ativa e o tipo deixar de ser compatível, o formulário retorna para **Informações**.
- No cadastro normal e no cadastro rápido incorporado à abertura da OS, a aba e seus campos técnicos não são renderizados.
- **Estado físico** e **Observações** permanecem na aba **Informações**.

## Compatibilidade

A alteração é apenas de composição da interface. Os nomes dos campos, IDs, rotas, endpoints e scripts do coletor foram preservados, portanto o fluxo de edição e importação técnica continua usando a lógica existente.

## Segurança e desempenho

Os campos técnicos são omitidos do HTML de criação, em vez de apenas escondidos por CSS. Isso evita submissões acidentais de dados fora da etapa de manutenção e reduz o conteúdo enviado ao navegador no cadastro inicial.

## Testes

Os testes funcionais cobrem:

- ausência da aba, coletor e painel técnico no cadastro normal;
- ausência dos mesmos elementos no cadastro rápido aberto pela nova OS;
- presença da aba e dos dois blocos na edição;
- posição da aba depois de **Fotos**;
- permanência de **Estado físico** e **Observações** na área de informações gerais;
- manutenção do comportamento OEM para notebooks na edição.
