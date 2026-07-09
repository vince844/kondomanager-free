<?php

namespace App\Services\Dashboard\Widgets;

use App\Contracts\DashboardWidget;
use App\Services\Gestionale\CreditoService;
use Illuminate\Support\Collection;

class CreditiDaCompensareWidget implements DashboardWidget
{
    private ?Collection $cache = null;

    public function __construct(
        private readonly CreditoService $creditoService
    ) {}

    public function key(): string
    {
        return 'crediti_da_compensare';
    }

    public function isVisible(int $condominioId): bool
    {
        // Nascosto se non c'è nessun credito da segnalare, come suggerito
        // dal contratto (stesso principio del Radar Salute Contabile).
        return $this->crediti($condominioId)->isNotEmpty();
    }

    public function payload(int $condominioId): array
    {
        return $this->crediti($condominioId)
            ->map(fn($c) => [
                'anagrafica_id'    => $c['anagrafica_id'],
                'nome'             => $c['nome'],
                'totale_formatted' => $c['totale_formatted'],
                'url'              => route('admin.gestionale.movimenti-rate.create', [
                    'condominio'            => $condominioId,
                    'prefill_anagrafica_id' => $c['anagrafica_id'],
                ]),
            ])
            ->values()
            ->toArray();
    }

    private function crediti(int $condominioId): Collection
    {
        // Memoizzato: isVisible() e payload() vengono chiamati entrambi sulla
        // stessa istanza per la stessa richiesta (WidgetManager::getPayloads).
        return $this->cache ??= $this->creditoService->perCondominio($condominioId);
    }
}
