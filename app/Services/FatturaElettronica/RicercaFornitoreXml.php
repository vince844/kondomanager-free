<?php

namespace App\Services\FatturaElettronica;

use App\Models\Fornitore;
use Illuminate\Support\Collection;

/**
 * Aggancia un `CedentePrestatore` letto da un XML a un fornitore **già esistente** in
 * anagrafica — decisione 3 di apertura della beta.14
 * (`docs/lettura_xml_fatture_passive.md`).
 *
 * ⚠️ **Solo aggancio, mai creazione.** La creazione automatica dell'anagrafica è un pezzo
 * a sé, previsto per la beta.15 (`docs/lettura_xml_fatture_passive.md`, sezione 2): qui si
 * cerca soltanto, e un risultato vuoto è un esito legittimo quanto uno pieno.
 *
 * ⚠️ **Mai per somiglianza sulla ragione sociale.** Solo identificativi fiscali esatti —
 * partita IVA o codice fiscale — perché un fornitore sbagliato imputato in silenzio è
 * l'errore più costoso: il costo finisce sul capitolo sbagliato e nessuno se ne accorge
 * finché non arriva il rendiconto. «Ditta Pulizia De Filippo» e «Pulizie De Filippo S.r.l.»
 * sono due entità diverse anche se il nome fa pensare al contrario.
 *
 * ⚠️ **`partita_iva` non è `UNIQUE`** — solo un indice normale
 * (`2025_11_29_064537_create_fornitori_table.php:18`) — e in Demo KM un fornitore compare
 * su più studi. Più di un risultato è quindi un esito reale, non un errore di query: chi
 * chiama decide cosa farne (D4 insegna lo stesso principio sui duplicati di fattura — questo
 * servizio non decide, restituisce).
 *
 * ⚠️ **Solo `IdPaese=IT` cerca per partita IVA.** Trovato dalla revisione avversariale
 * della beta.14: il paese non è nel confronto, solo le cifre. `partita_iva` in
 * `fornitori` è una colonna solo-Italia (nessun prefisso paese, verificato sulla
 * migrazione), quindi un `IdCodice` estero che coincide numericamente con una partita
 * IVA italiana andrebbe ad agganciare un fornitore che non c'entra — un errore
 * silenzioso, della stessa classe che la ricerca-solo-per-identificativi-esatti di
 * questa classe esiste apposta per evitare. Un cedente estero resta cercabile per
 * codice fiscale, se il file lo dichiara.
 */
final class RicercaFornitoreXml
{
    /** @return Collection<int, Fornitore> */
    public function cerca(?string $partitaIva, ?string $paesePartitaIva, ?string $codiceFiscale): Collection
    {
        $piva = $paesePartitaIva === null || mb_strtoupper(trim($paesePartitaIva)) === 'IT'
            ? $this->normalizza($partitaIva)
            : null;
        $cf = $this->normalizza($codiceFiscale);

        if ($piva === null && $cf === null) {
            return collect();
        }

        return Fornitore::query()
            ->where(function ($q) use ($piva, $cf) {
                if ($piva !== null) {
                    $q->orWhereRaw('UPPER(TRIM(partita_iva)) = ?', [$piva]);
                }
                if ($cf !== null) {
                    $q->orWhereRaw('UPPER(TRIM(codice_fiscale)) = ?', [$cf]);
                }
            })
            ->get();
    }

    private function normalizza(?string $valore): ?string
    {
        if ($valore === null) {
            return null;
        }

        $pulito = mb_strtoupper(trim($valore));

        return $pulito === '' ? null : $pulito;
    }
}
