<?php

namespace App\Services\Dashboard\Widgets;

use App\Contracts\DashboardWidget;
use App\Models\ImportBatch;
use App\Services\Import\Controlli\ControlliPostImport;
use App\Services\Import\Controlli\StatoControllo;

/**
 * «Da controllare dopo l'importazione», sul cruscotto del condominio.
 *
 * Senza questo richiamo la lista si raggiunge **solo** dalla schermata di esito — cioè da una
 * pagina che l'amministratore vede una volta e poi non ritrova più, che è esattamente il problema
 * che la lista esisteva per risolvere.
 *
 * ## La guardia economica viene prima
 *
 * `isVisible()` fa prima una query indicizzata su `import_batches.condominio_id`: un condominio
 * mai importato — il caso normale — costa una riga e si esce. Solo se un lotto c'è si pagano i
 * verificatori. Il risultato è memoizzato perché `WidgetManager` chiama `isVisible()` e
 * `payload()` sulla stessa istanza.
 */
class ControlliPostImportWidget implements DashboardWidget
{
    /** @var array<int, list<array<string, mixed>>> */
    private array $cache = [];

    public function __construct(private readonly ControlliPostImport $controlli) {}

    public function key(): string
    {
        return 'controlli_post_import';
    }

    public function isVisible(int $condominioId): bool
    {
        return $this->aperte($condominioId) !== [];
    }

    public function payload(int $condominioId): array
    {
        $aperte = $this->aperte($condominioId);

        return [
            'totale' => count($aperte),
            // Tre e non tutte: il cruscotto dice che c'è del lavoro e dove andarlo a leggere,
            // non lo elenca. Un cruscotto che diventa una lista smette di essere un cruscotto.
            'prime' => array_map(fn (array $v) => [
                'titolo' => $v['titolo'],
                'url' => $v['destinazione']['url'] ?? null,
            ], array_slice($aperte, 0, 3)),
            'url' => route('admin.gestionale.controlli-import.index', $condominioId),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aperte(int $condominioId): array
    {
        if (isset($this->cache[$condominioId])) {
            return $this->cache[$condominioId];
        }

        $haLotti = ImportBatch::query()
            ->where('condominio_id', $condominioId)
            ->whereIn('stato', [ImportBatch::STATO_COMPLETATO, ImportBatch::STATO_PARZIALE])
            ->whereNotNull('rapporto')
            ->exists();

        if (! $haLotti) {
            return $this->cache[$condominioId] = [];
        }

        $condominio = \App\Models\Condominio::find($condominioId);

        $aperte = $condominio === null ? [] : array_values(array_filter(
            $this->controlli->perCondominio($condominio),
            fn (array $v) => $v['stato'] === StatoControllo::Aperto->value,
        ));

        return $this->cache[$condominioId] = $aperte;
    }
}
