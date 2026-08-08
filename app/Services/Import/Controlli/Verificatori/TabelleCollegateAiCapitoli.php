<?php

namespace App\Services\Import\Controlli\Verificatori;

use App\Models\ImportBatch;
use App\Models\Tabella;
use App\Services\Import\Controlli\EsitoControllo;
use App\Services\Import\Controlli\VerificatoreControllo;

/**
 * Le tabelle importate sono state collegate a un capitolo di spesa?
 *
 * Una tabella scollegata esiste e non ripartisce niente: in elenco compare come «Orfana» e il
 * motore non la incontra mai. Danea non dichiara quale spesa vada su quale tabella, quindi il
 * collegamento resta una scelta contabile dell'amministratore — ma è verificabile, e quindi non
 * gli si chiede di spuntarla a mano.
 */
final class TabelleCollegateAiCapitoli implements VerificatoreControllo
{
    public function esegui(ImportBatch $batch, array $idPerTipo): EsitoControllo
    {
        $id = $idPerTipo[Tabella::class] ?? [];

        if ($id === []) {
            return EsitoControllo::risolto('Nessuna tabella importata da questo lotto.');
        }

        $scollegate = Tabella::whereIn('id', $id)->doesntHave('conti')->count();

        if ($scollegate === 0) {
            return EsitoControllo::risolto(
                sprintf('Tutte le %d tabelle importate sono collegate a un capitolo.', count($id)),
            );
        }

        return EsitoControllo::aperto($scollegate, sprintf(
            '%d %s su %d ancora senza capitolo di spesa: con quei millesimi non si ripartisce niente.',
            $scollegate,
            $scollegate === 1 ? 'tabella' : 'tabelle',
            count($id),
        ));
    }
}
