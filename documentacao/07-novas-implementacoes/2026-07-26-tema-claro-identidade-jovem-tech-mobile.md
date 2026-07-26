# Tema claro e identidade Jovem Tech no PWA mobile

- data: `2026-07-26`;
- versão: `5.19.1.0`;
- módulo: `frontends/mobile`;
- natureza: correção visual e consolidação do design system.

## Resultado

O tema claro do PWA passa a aplicar fundo claro em toda a janela, inclusive nas
áreas externas aos cartões. Os antigos realces verdes foram substituídos pelo
azul institucional Jovem Tech e a tipografia foi alinhada à pilha já adotada
pelo design system da marca.

## Arquitetura e decisões

- o seletor `html[data-theme='light']` controla o fundo raiz;
- `[data-theme='light'] body` sobrescreve o gradiente escuro que antes era fixo;
- os canais RGB de marca foram centralizados nos tokens `--accent-rgb` e
  `--accent-strong-rgb`, evitando duplicação de cores em bordas, focos e
  superfícies;
- o azul principal `#3868B0`, o fundo `#F4F8FF` e a tipografia
  `Aptos, Segoe UI, system-ui, sans-serif` reutilizam o Jovem Tech Design System
  já existente no ERP;
- o azul claro `#63B3ED` preserva contraste dos destaques no tema escuro;
- o favicon e as miniaturas documentais sem foto acompanham a mesma identidade.

## Segurança, performance e escalabilidade

- nenhuma fonte, imagem ou folha de estilo externa foi adicionada; não há nova
  dependência de CDN, alteração de CSP ou exposição de dados;
- a troca é resolvida por variáveis CSS e não adiciona requisições de rede,
  JavaScript de renderização ou custo relevante de memória;
- novos componentes podem consumir os mesmos tokens, mantendo a paleta
  consistente sem repetir valores;
- cores semânticas de sucesso, alerta e erro continuam independentes da cor de
  marca para não perder significado operacional.

## Validação

- `pnpm test`: 16 arquivos e 88 testes aprovados;
- `pnpm lint`: aprovado;
- `pnpm build`: build Next.js de produção aprovado;
- validação visual em navegador real no viewport `390 x 844`, sem fundo escuro
  residual e sem overflow horizontal;
- teste automatizado confirma a aplicação do tema claro, persistência da
  preferência e atualização da cor do navegador para `#F4F8FF`.
