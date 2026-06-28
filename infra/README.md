# Infraestrutura

Templates e referencias para desenvolvimento em Windows/XAMPP e producao em VPS/Linux.

## Estrutura

O alvo oficial de producao deste repositorio e `Ubuntu VPS`. Qualquer ajuste de infraestrutura deve considerar Linux como referencia principal, deixando Windows restrito ao uso local.

- `windows/`: templates de Apache e configuracoes locais.
- `linux/`: templates de reverse proxy, cron e operacao em VPS.

## Regra

Todos os exemplos partem do principio de que o backend publica apenas `backend/public`.
