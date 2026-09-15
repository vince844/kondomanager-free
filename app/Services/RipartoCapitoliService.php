<?php

namespace App\Services;

use App\Models\Gestionale\PianoRate;
use App\Services\Riparto\MatriceRipartoBuilder;

/**
 * Costruisce la matrice per la stampa «Riparto bilancio per capitolo e soggetto».
 *
 * OUTPUT buildMatrice(): la stessa forma della gemella per tabella, con `capitoli` al posto di
 * `tabelle`, `per_capitolo` al posto di `per_tabella` e `tot_per_capitolo` al posto di
 * `tot_per_tabella`; le colonne sono i capitoli **radice** (il conto di primo livello, congelato al
 * momento della generazione) più le pseudo-colonne `COLONNA_*`. Vedi `RipartoTabelleService`.
 *
 * ## Dalla 1.11.0-beta.29 questo servizio **legge**, non ricalcola
 *
 * Fino alla beta.28 questa stampa non istanziava nemmeno il motore: si rifaceva gli importi da
 * `righe_fattura` con `abs()`, i pesi dall'albero dei conti vivo, un Hare per radice — mentre il
 * motore ne fa uno per foglia — e **deduceva** il già versato per differenza fra la quota emessa
 * e ciò che aveva ricostruito, con un tetto letto da `contributi_versati`. Da uno scalare per riga
 * non si ricava a quale capitolo appartenga uno scarto né cosa sia: i resti finivano sul capitolo
 * a peso maggiore o in «Fuori riparto», e dopo un subentro fatto a mano lo sconto compariva in capo
 * a chi non aveva versato niente (Coda 78). Un titolare staccato dopo la generazione spariva dal
 * documento (era nato per questo `COLONNA_FUORI_RIPARTO`).
 *
 * Ora le celle sono somme delle righe di `righe_riparto`, aggregate per `conto_radice_id`: i
 * centesimi sono quelli del motore, riga per riga; il già versato è una riga negativa per soggetto;
 * l'ad personam sta in «Addebito diretto» come nella gemella. Per i piani senza dettaglio la
 * matrice si ricostruisce dal motore in sola lettura e lo dichiara (`fonte.tipo`). «Fuori riparto»
 * resta come guardia: nel registrato compare solo se qualcosa non torna, ed è un errore loggato.
 */
class RipartoCapitoliService
{
    /** L'importo di un soggetto che nessuna colonna spiega: nel registrato non deve esistere. */
    public const COLONNA_FUORI_RIPARTO = MatriceRipartoBuilder::COLONNA_FUORI_RIPARTO;
    /** Lo sconto a chi aveva già versato, per soggetto, dal dettaglio (non più dedotto). */
    public const COLONNA_GIA_VERSATO = MatriceRipartoBuilder::COLONNA_GIA_VERSATO;
    /** I saldi degli esercizi precedenti (`regole_calcolo.importi.saldo_usato`), che non passano dal motore. */
    public const COLONNA_PREGRESSO = MatriceRipartoBuilder::COLONNA_PREGRESSO;
    /** Le spese ad personam, come nella gemella per tabella. */
    public const COLONNA_DIRETTO = MatriceRipartoBuilder::COLONNA_DIRETTO;

    public function buildMatrice(PianoRate $pianoRate): array
    {
        return app(MatriceRipartoBuilder::class)->perCapitoli($pianoRate);
    }
}
