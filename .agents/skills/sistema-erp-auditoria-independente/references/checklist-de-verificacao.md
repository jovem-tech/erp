# Checklist de Verificação

## Verificação mínima de uma alegação

Antes de marcar qualquer item como "confirmado", fazer pelo menos um destes,
na ordem de preferência:

1. Rodar o teste automatizado que cobre o comportamento e ler o resultado.
2. Se não houver teste, reproduzir manualmente (curl, tinker, console do
   navegador, query direta no banco).
3. Se não for possível reproduzir, ler o código-fonte real do ponto de
   execução (não o comentário, não o nome da função — o corpo) e confirmar
   que o caminho alegado é de fato alcançado a partir de uma rota/ação real.

Nunca aceitar como confirmado:
- a existência de um arquivo, sem confirmar que ele é referenciado/executado
  em algum lugar (classes, middlewares e configs podem existir sem nunca
  serem chamados);
- uma regra de validação ou config, sem confirmar o valor *efetivo* em
  tempo de execução (`config('...')` pode ter um default diferente do que
  o `.env` real contém, ou o `.env` pode não ter a chave);
- uma alegação sobre dados reais (ex.: "senha forte definida"), sem checar
  o dado real (ex.: hash no banco, não o que um script *deveria* ter feito).

## Cobertura por dimensão

### Arquitetura
- a separação de responsabilidades declarada (ex.: "frontend nunca acessa
  banco direto") é verdadeira em todos os módulos, não só nos exemplos
  citados na documentação?
- existem APIs/serviços paralelos não documentados fazendo o mesmo trabalho
  que o backend central?
- caminhos de arquivo, portas e processos assumem o ambiente de produção
  real (Ubuntu VPS) ou só fazem sentido no ambiente de desenvolvimento atual?

### Segurança
- existe algum script/endpoint sem autenticação que executa ação sensível
  (reset de senha, migração de schema, dump de dados, info de
  ambiente/`phpinfo`)?
- segredos (senhas, chaves, tokens) estão fora do versionamento? E, mais
  importante: as credenciais *reais* atualmente em uso são fortes (checar o
  hash, não a intenção documentada)?
- mensagens de erro expostas ao cliente (especialmente com `APP_DEBUG=true`)
  vazam stack trace, caminho de arquivo ou variável de ambiente?
- middlewares de segurança (HTTPS, CORS, rate limit) estão de fato
  registrados no pipeline de requisição, não só escritos como classe?

### Escalabilidade / latência
- queries com `LIKE '%...%'` ou equivalentes em colunas sem índice utilizável
  — isso é um problema real na escala *atual* dos dados, ou prematuro?
- tarefas potencialmente lentas (e-mail, processamento de arquivo, chamada
  externa) rodam de forma síncrona dentro da requisição HTTP?
- se há fila configurada, existe de fato um worker (Supervisor/systemd ou
  equivalente) documentado para consumi-la em produção?

### Boas práticas / padronização
- o padrão de resposta de API (envelope, códigos de erro) é seguido em
  *todos* os controllers, ou só nos exemplos citados?
- texto visível ao usuário está em pt-BR e em UTF-8 real (cuidado com
  mojibake do tipo `Ã­`, `Ã£` — isso indica problema de codificação no
  arquivo, não só de revisão)?
- a UI quebra em algum dos breakpoints exigidos pela constituição
  (`1280/992/768/430/390/360/320px`)?
- existe path hardcoded de Windows fora de documentação/dev tooling
  explicitamente isolado?

### Documentação
- toda mudança estrutural recente tem nota correspondente em
  `documentacao/07-novas-implementacoes/`?
- o manifesto automático (`documentacao/04-governanca-ai/manifesto-do-sistema.md`)
  está sincronizado (`php scripts/php/sync-agent-docs.php`) após as mudanças?
- a documentação descreve um módulo que já foi removido/descontinuado sem
  nenhuma marcação de que isso aconteceu?

## Como reportar o resultado

- nota geral (0–10) com justificativa em 1–2 frases ancorada no achado mais
  severo, não numa média ingênua dos achados;
- tabela de achados com severidade (Crítico/Alto/Médio/Baixo), evidência
  `arquivo:linha` e recomendação objetiva;
- plano de ação faseado (o que é urgente hoje vs. o que pode esperar),
  citando explicitamente qualquer decisão de **adiar** algo e o motivo —
  "adiado por avaliação de risco" é uma conclusão legítima, "esquecido" não.
