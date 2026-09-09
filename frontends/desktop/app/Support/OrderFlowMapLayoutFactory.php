<?php

namespace App\Support;

use App\Services\DesktopOrderStatusFlowService;

/**
 * Resolve a geometria do Mapa da OS para quem NAO tem o catalogo de status a
 * mao.
 *
 * A pagina cheia do mapa (`orders/map.blade.php`) ja recebe
 * `status_disponiveis` dentro do payload da OS e monta o layout no controller,
 * sem custo extra. Ja o modal "Alterar status" — que embute o mesmo mapa na
 * aba "Mapa de status" — e incluido por cinco telas diferentes
 * (`show`, `index`, `closure`, `edit`, `documents-center`), e nem todas tem um
 * catalogo de status na view. Em vez de obrigar as cinco a buscar e repassar a
 * mesma coisa, o partial pede o layout aqui.
 *
 * Memoizado por request: as cinco telas incluem o partial uma vez cada, mas o
 * mesmo request nunca deve bater duas vezes na API pelo mesmo catalogo.
 */
class OrderFlowMapLayoutFactory
{
    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    public function __construct(
        private readonly DesktopOrderStatusFlowService $statusFlowService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function current(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        // Falha da API nunca pode derrubar a tela que embute o modal: sem
        // catalogo o mapa renderiza vazio e as outras abas seguem normais.
        try {
            $catalog = $this->statusFlowService->statusCatalog();
        } catch (\Throwable $exception) {
            report($exception);
            $catalog = [];
        }

        return $this->memo = OrderFlowMapLayout::build($catalog);
    }
}
