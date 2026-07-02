# Jovem Tech Design System v3.0.0 — Novo tema do desktop

**Data:** 2026-07-02
**Versão:** 3.5.0
**Módulo:** `frontends/desktop`

## Resumo

Implementação do **Jovem Tech Design System v3.0.0** como segundo tema selecionável do painel desktop ERP. O tema convive com o tema padrão (roxo) sem conflito, usando escopo CSS por atributo `data-theme`.

## Motivação

O sistema precisava de uma identidade visual institucional alinhada à marca Jovem Tech — azul corporativo, tipografia moderna e sidebar em navy — sem descartar o tema padrão existente.

## O que foi entregue

### Identidade visual do novo tema

| Elemento | Valor |
|----------|-------|
| Primário | `#3868B0` (azul institucional) |
| Sidebar | `linear-gradient(180deg, #254F8D 0%, #1E4278 100%)` |
| Background | `#F4F8FF` (azul-branco suave) |
| Superfície | `#FFFFFF` |
| Heading | `#0F2847` (navy escuro) |
| Texto | `#1F2937` |
| Borda | `#D7E3F4` |
| Tipografia | Aptos, Segoe UI, sans-serif |

### Arquivos criados ou modificados

| Arquivo | Ação |
|---------|------|
| `public/assets/css/themes/jovem-tech.css` | Criado — 400+ linhas de overrides com escopo `[data-theme="jovem-tech"]` |
| `resources/views/layouts/app.blade.php` | Modificado — aplica `data-theme` e carrega CSS do tema via `@if` |
| `resources/views/configurations/system.blade.php` | Modificado — UI de seleção elevada para cards visuais com preview de paleta |
| `app/Http/Controllers/ConfigurationController.php` | Modificado — método `updateAppearance()` com lista de permitidos |
| `routes/web.php` | Modificado — rota `POST /configuracoes/aparencia` no grupo autenticado |

### Decisões técnicas

**CSS scoping via atributo:** `[data-theme="jovem-tech"]` garante que o tema não vaze para sessões sem preferência ativa. O arquivo de tema só é carregado quando necessário.

**Sessão sem banco:** a preferência é salva em `$request->session()->put('desktop_theme', $theme)`. Não requer migration, é imediata e é descartada ao trocar de tema ou no logout.

**`background` vs `background-color`:** `desktop.css` usa `background: linear-gradient(...)` em botões e abas. Para sobrescrever o gradiente, o tema usa `background: linear-gradient(...)` (shorthand) — não `background-color`, que é ignorado quando há gradiente na camada de imagem.

**Blade `@if` para atributos HTML:** `{{ expr }}` dentro de atributos HTML escapa `"` para `&quot;`, invalidando `data-theme`. A solução correta é `@if(session(...)) data-theme="{{ }}"@endif`.

**Sublinks no flyout colapsado:** o painel flyout do sidebar colapsado usa `background: var(--desktop-surface)` (branco). Dois seletores distintos controlam a cor:
- `.desktop-sidebar:not(.is-collapsed) .desktop-nav-sublink` → `rgba(255,255,255,0.72)` (expandido, navy)
- `.desktop-sidebar.is-collapsed .desktop-nav-group.is-open .desktop-nav-sublink` → `#1F2937` (flyout branco)

### UI de seleção de tema

A aba `Aparência` em `Configurações > Sistema` apresenta cards clicáveis com:
- Preview em miniatura da paleta do tema (sidebar + conteúdo)
- Nome e descrição
- Ícone de seleção ativa (`bi-check-circle-fill`)
- Borda e glow no card selecionado via `.is-active`
- Submit via form POST (sem AJAX)

## Como adicionar mais temas no futuro

1. Criar `public/assets/css/themes/{slug}.css` com `[data-theme="{slug}"] { ... }`
2. Adicionar `{slug}` ao array `$allowed` em `ConfigurationController::updateAppearance()`
3. Adicionar card de preview em `configurations/system.blade.php`
4. Nenhuma migration necessária

## Validação executada

- Seleção e aplicação do tema Jovem Tech em `http://127.0.0.1:8080/configuracoes/sistema?tab=aparencia`
- Sidebar em navy gradient verificada
- Botões e abas em azul institucional verificados
- Flyout do sidebar colapsado com texto legível em fundo branco
- Retorno ao tema padrão (roxo) funcional
