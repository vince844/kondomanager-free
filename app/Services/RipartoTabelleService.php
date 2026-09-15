<?php

namespace App\Services;

use App\Models\Gestionale\PianoRate;
use App\Services\Riparto\MatriceRipartoBuilder;

/**
 * Costruisce la struttura dati per la stampa «Riparto bilancio per tabella e soggetto».
 *
 * OUTPUT buildMatrice():
 * [
 *   'tabelle'   => [ chiave => ['nome', 'quota_label', 'quota_tipo', 'decimali', 'senza_quote'?] ],
 *   'righe'     => [ immobile_id => ['codice_immobile', 'interno', 'piano', 'nome_immobile',
 *                    'soggetti' => [ anagrafica_id => ['nome', 'ruolo', 'ruolo_raw', 'quota_sogg',
 *                                    'per_tabella' => [ chiave => ['quota' => ?float, 'importo' => int] ],
 *                                    'totale' => int] ],
 *                    'totale_immobile' => int] ],
 *   'gran_totale'           => int,
 *   'tot_per_tabella'       => [ chiave => int ],
 *   'tot_quota_per_tabella' => [ tabella_id => float ],
 *   'fonte'                 => ['tipo' => registrato|ricostruito|anteprima, 'generato_il', 'versione'],
 * ]
 * Le chiavi delle colonne sono id di tabella oppure le pseudo-colonne (`COLONNA_*`); gli importi
 * sono in centesimi.
 *
 * ## Dalla 1.11.0-beta.29 questo servizio **legge**, non ricalcola
 *
 * Le celle sono somme delle righe di `righe_riparto`, scritte alla generazione insieme alle quote
 * (una riga per tabella × ruolo, il già versato come riga negativa, l'ad personam con la riga di
 * fattura): vedi `App\Services\Riparto\MatriceRipartoBuilder`, che serve anche la stampa gemella per
 * capitolo. Per i piani senza dettaglio — generati prima della beta.29 — il motore gira al momento
 * della stampa in sola lettura e la matrice lo dichiara (`fonte.tipo = ricostruito`).
 *
 * ## Storia, per chi legge un vecchio PDF
 *
 * - v1.9.1 distribuiva il totale di riga sui pesi e scaricava il resto sull'ultima tabella, anche
 *   a peso zero («€ 0,01 su TUNNEL a chi non c'entra»); vedi
 *   docs/ripartotabelle_discrepanza_centesimale.md.
 * - 1.10.0-beta.8 («penny-perfect»): le celle ricostruite conto per conto con lo stesso algoritmo
 *   del motore — colonne al budget, righe uguali a `rate_quote` — e un riallineamento di sicurezza
 *   del residuo. Fino alla beta.73 il residuo finiva su una tabella millesimale; poi sempre in
 *   «Addebito diretto», dove si sommava agli addebiti ad personam veri (Coda 77).
 * - 1.11.0-beta.29: nessuna aritmetica in stampa. «Addebito diretto» contiene solo gli ad personam,
 *   il pregresso sta in «Saldi precedenti», il già versato in «Già versato», e un residuo — che nel
 *   registrato non può esistere — compare come «Fuori riparto» ed è loggato come errore.
 */
class RipartoTabelleService
{
    /** Pseudo-colonna degli addebiti ad personam: una stringa, così non collide con l'id di una tabella. */
    public const COLONNA_DIRETTO = MatriceRipartoBuilder::COLONNA_DIRETTO;
    public const COLONNA_PREGRESSO = MatriceRipartoBuilder::COLONNA_PREGRESSO;
    public const COLONNA_GIA_VERSATO = MatriceRipartoBuilder::COLONNA_GIA_VERSATO;
    public const COLONNA_FUORI_RIPARTO = MatriceRipartoBuilder::COLONNA_FUORI_RIPARTO;

    public function buildMatrice(PianoRate $pianoRate): array
    {
        return app(MatriceRipartoBuilder::class)->perTabelle($pianoRate);
    }
}
