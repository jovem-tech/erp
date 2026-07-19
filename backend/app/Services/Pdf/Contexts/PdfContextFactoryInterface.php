<?php

namespace App\Services\Pdf\Contexts;

interface PdfContextFactoryInterface
{
    /**
     * Monta o DocumentContext completo ANTES do render (nenhuma query
     * acontece durante a renderização dos blocos).
     *
     * @param array<string, mixed> $subject  entidade(s)-alvo, ex.: ['order' => Order]
     * @param array<string, mixed> $options  extras do fluxo chamador (ex.: link de aprovação)
     * @return array<string, mixed> contexto aninhado (empresa.*, documento.*, os.*, coleções...)
     */
    public function build(array $subject, array $options = []): array;
}
