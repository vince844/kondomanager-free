<?php

namespace App\Services\Import\Controlli\Verificatori;

use App\Models\Condominio;
use App\Models\ImportBatch;
use App\Services\Import\Controlli\EsitoControllo;
use App\Services\Import\Controlli\VerificatoreControllo;

/**
 * Il condominio ha un codice fiscale, e ha la forma giusta?
 *
 * Serve due codici — mancante e malformato — perché la domanda è la stessa e la risposta pure:
 * o adesso c'è un CF di undici cifre, o non c'è.
 */
final class CodiceFiscaleDelCondominio implements VerificatoreControllo
{
    public function esegui(ImportBatch $batch, array $idPerTipo): EsitoControllo
    {
        $condominio = $batch->condominio_id === null
            ? null
            : Condominio::find($batch->condominio_id);

        if ($condominio === null) {
            return EsitoControllo::risolto('Nessun condominio da controllare.');
        }

        $cf = trim((string) $condominio->codice_fiscale);

        if (preg_match('/^\d{11}$/', $cf) === 1) {
            return EsitoControllo::risolto('Il codice fiscale del condominio è a posto.');
        }

        return EsitoControllo::aperto(1, $cf === ''
            ? 'Il condominio non ha ancora un codice fiscale: senza, non si emettono documenti fiscali.'
            : sprintf('Il codice fiscale «%s» non ha la forma di undici cifre.', $cf));
    }
}
