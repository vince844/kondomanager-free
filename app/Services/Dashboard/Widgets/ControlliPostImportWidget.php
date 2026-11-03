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
 * ## Non sparisce quando il lavoro è finito
 *
 * ⚠️ Fino alla 1.11.0-beta.5 compariva **solo** se c'era qualcosa di aperto, e spariva da solo
 * all'ultima voce chiusa. Sembrava giusto — un condominio migrato mesi fa non deve portarsi in
 * giro un riquadro inutile — e portava via con sé l'unica strada verso il **rapporto**, che è il
 * documento che si allega al passaggio di consegne e si archivia. Chi chiudeva i controlli non
 * aveva più modo di tornarci.
 *
 * Ora resta finché il condominio ha un'importazione, ma **cambia faccia**: da richiamo giallo con
 * il lavoro da fare a riga neutra con dentro il rapporto. Un cruscotto che dice «c'è del lavoro»
 * e un cruscotto che dice «questo condominio è arrivato da un'importazione» sono due cose diverse,
 * e la seconda non scade.
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

    /** @var array<int, ImportBatch|null> */
    private array $lotti = [];

    public function __construct(private readonly ControlliPostImport $controlli) {}

    public function key(): string
    {
        return 'controlli_post_import';
    }

    public function isVisible(int $condominioId): bool
    {
        return $this->ultimoLotto($condominioId) !== null;
    }

    public function payload(int $condominioId): array
    {
        $aperte = $this->aperte($condominioId);
        $lotto = $this->ultimoLotto($condominioId);

        // ⚠️ **Le voci si chiudono in due modi diversi, e il cruscotto non lo diceva.**
        //
        // Quelle *verificabili* hanno una query che risponde da sola — «le tabelle sono collegate
        // a un capitolo?» — e spariscono da qui appena il dato è a posto, senza che nessuno debba
        // confermare. Le altre no: il confronto è con qualcosa che sta fuori da Kondomanager, e
        // restano finché l'amministratore non dichiara di averle guardate.
        //
        // Senza questo conteggio la card poteva sembrare bloccata: uno sistema il problema, torna
        // sul cruscotto, e ne trova ancora tre — perché quelle tre non erano di quel tipo.
        $verificabili = count(array_filter($aperte, fn (array $v) => $v['verificabile'] ?? false));

        return [
            'totale' => count($aperte),
            'da_sole' => $verificabili,
            'da_confermare' => count($aperte) - $verificabili,
            // Il rapporto dell'ultima importazione, raggiungibile per sempre: è la ricevuta di
            // cosa è entrato e da quale riga di quale file.
            'lotto' => $lotto === null ? null : [
                'uuid' => $lotto->uuid,
                'quando' => $lotto->completato_at?->format('d/m/Y'),
                'url_rapporto' => route('import.rapporto', $lotto->uuid),
                'url_esito' => route('import.esito', $lotto->uuid),
            ],
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
     * L'ultima importazione che ha lasciato un rapporto su questo condominio.
     *
     * È anche la guardia economica del widget: un condominio mai importato — il caso normale —
     * costa una query indicizzata su `import_batches.condominio_id` e si esce.
     */
    private function ultimoLotto(int $condominioId): ?ImportBatch
    {
        if (array_key_exists($condominioId, $this->lotti)) {
            return $this->lotti[$condominioId];
        }

        return $this->lotti[$condominioId] = ImportBatch::query()
            ->where('condominio_id', $condominioId)
            ->whereIn('stato', [ImportBatch::STATO_COMPLETATO, ImportBatch::STATO_PARZIALE])
            ->whereNotNull('rapporto')
            ->latest('completato_at')
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aperte(int $condominioId): array
    {
        if (isset($this->cache[$condominioId])) {
            return $this->cache[$condominioId];
        }

        if ($this->ultimoLotto($condominioId) === null) {
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
