<?php

namespace App\Observers;

use App\Models\Client;
use App\Models\Equipment;
use App\Models\Order;
use App\Services\Orders\OrderSearchIndexService;
use Throwable;

/**
 * Mantem `os.busca_texto` em dia quando a OS, o cliente ou o equipamento mudam.
 *
 * O leque de atualizacao e' pequeno por natureza do dado: medido neste acervo,
 * 2,81 OS por cliente (maximo 34) e 1,01 por equipamento (maximo 11) — entao
 * propagar a mudanca do cadastro para as OS ligadas custa poucas linhas.
 *
 * Falha aqui nunca derruba a operacao: indice de busca desatualizado e' um
 * incomodo, perder o salvamento do cadastro e' um defeito. Por isso o try/catch
 * — e por isso existe `os:reindexar-busca` para reconstruir quando preciso.
 */
class OrderSearchIndexObserver
{
    public function __construct(
        private readonly OrderSearchIndexService $searchIndex
    ) {
    }

    public function savedOrder(Order $order): void
    {
        $this->guard(fn () => $this->searchIndex->rebuildForOrders([(int) $order->id]));
    }

    public function savedClient(Client $client): void
    {
        $this->guard(fn () => $this->searchIndex->rebuildForClient((int) $client->id));
    }

    public function savedEquipment(Equipment $equipment): void
    {
        $this->guard(fn () => $this->searchIndex->rebuildForEquipment((int) $equipment->id));
    }

    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
