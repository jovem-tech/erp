# Acessórios recebidos por ordem de serviço

## Regra de domínio

Acessórios são parte do check-in de uma ordem de serviço, não uma característica permanente do equipamento. O mesmo aparelho pode entrar com carregador em uma OS, sem carregador em outra e com itens diferentes em atendimentos futuros.

A fonte oficial passa a ser `os.acessorios`. O cadastro de equipamento deixa de aceitar, enviar ou exibir esse campo. A criação e a edição da OS disponibilizam `Acessórios recebidos nesta OS`, com limite de 2.000 caracteres e atalhos para itens frequentes.

## Migração dos registros legados

A migration `2026_07_19_000004_move_equipment_accessories_to_orders.php`:

1. cria a tabela de auditoria `equipamento_acessorios_legado`;
2. localiza a OS mais recente de cada equipamento com acessórios legados;
3. copia o valor somente quando `os.acessorios` está vazio;
4. preserva valores já registrados na OS, sem sobrescrever divergências;
5. arquiva registros sem OS e limpa o campo legado do equipamento;
6. permite rollback, restaurando o cadastro legado e removendo apenas valores que a própria migration copiou e que ainda não foram alterados.

O processamento é feito em lotes de 200 equipamentos. As OS mais recentes são buscadas em lote, evitando consultas N+1.

## Contratos e apresentação

- `POST`, `PUT` e `PATCH /api/v1/equipments` rejeitam `acessorios` para impedir gravação no agregado incorreto.
- criação e atualização de `/api/v1/orders` aceitam `acessorios` como texto opcional de até 2.000 caracteres.
- a tela da OS mostra acessórios nos dados da própria ordem, não no card do equipamento.
- o contexto do motor PDF continua usando `os.acessorios` e `os.acessorios_html`.

## Segurança e integridade

A validação existe no desktop e no backend. Isso impede que clientes alternativos contornem a regra da interface. A migration é não destrutiva: todo valor legado é auditado antes da limpeza e dados já específicos de uma OS nunca são sobrescritos.

## Operação

Após o deploy, executar `php artisan migrate --force` no backend e validar os totais da tabela de auditoria por `resultado`: `copiado_para_os`, `os_ja_possuia` e `sem_os`.
