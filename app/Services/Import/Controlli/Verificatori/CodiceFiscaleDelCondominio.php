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

        // ⚠️ **Il messaggio nomina ENTRAMBE le conseguenze — Coda 114, 04/09/2026.**
        // Fino a quel giorno diceva soltanto «non si emettono documenti fiscali»: vero, e
        // incompleto da quando esiste la lettura delle fatture XML. Senza questo campo salta
        // anche la guardia che confronta il `CessionarioCommittente` del file con il condominio
        // aperto — cioè il controllo che intercetta «questa fattura non è di questo palazzo»,
        // che è l'errore più caro da scoprire dopo, perché si porta dietro scritture in partita
        // doppia e budget. Chi legge il rapporto deve capire che non è una formalità.
        //
        // Il caso nasce quasi solo di qui: creando un condominio a mano il campo è obbligatorio
        // (`CreateCondominioRequest`), quindi manca proprio a chi sta caricando lo storico — che
        // è anche quello che con più probabilità importerà XML subito dopo.
        return EsitoControllo::aperto(1, $cf === ''
            ? 'Il condominio non ha ancora un codice fiscale. Senza, non si emettono documenti fiscali '
                .'e non funziona il controllo che rifiuta una fattura XML intestata a un altro condominio: '
                .'i documenti verrebbero accettati senza che nessuno verifichi di chi sono.'
            : sprintf(
                'Il codice fiscale «%s» non ha la forma di undici cifre. Finché resta così non si emettono '
                .'documenti fiscali, e il controllo sull\'intestatario delle fatture XML importate non trova '
                .'niente con cui confrontarsi.',
                $cf
            ));
    }
}
