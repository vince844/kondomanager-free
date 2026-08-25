<?php

namespace App\Services\Import\Controlli\Verificatori;

use App\Models\Anagrafica;
use App\Models\ImportBatch;
use App\Services\Import\Controlli\EsitoControllo;
use App\Services\Import\Controlli\VerificatoreControllo;

/**
 * Quante persone importate sono ancora senza codice fiscale?
 *
 * Non blocca niente — Kondomanager sa lavorare senza — ma è ciò che rende impossibile emettere
 * una comunicazione fiscale e riconoscere la stessa persona su due condomìni.
 */
final class CodiciFiscaliDeiSoggetti implements VerificatoreControllo
{
    public function esegui(ImportBatch $batch, array $idPerTipo): EsitoControllo
    {
        $id = $idPerTipo[Anagrafica::class] ?? [];

        if ($id === []) {
            return EsitoControllo::risolto('Nessuna persona importata da questo lotto.');
        }

        $senza = Anagrafica::whereIn('id', $id)
            ->where(fn ($q) => $q->whereNull('codice_fiscale')->orWhere('codice_fiscale', ''))
            ->count();

        if ($senza === 0) {
            return EsitoControllo::risolto(
                sprintf('Tutte le %d persone importate hanno il codice fiscale.', count($id)),
            );
        }

        return EsitoControllo::aperto($senza, sprintf(
            '%d %s su %d ancora senza codice fiscale.',
            $senza,
            $senza === 1 ? 'persona' : 'persone',
            count($id),
        ));
    }
}
