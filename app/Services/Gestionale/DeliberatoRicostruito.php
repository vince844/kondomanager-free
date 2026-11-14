<?php

namespace App\Services\Gestionale;

use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RataQuote;
use App\Services\Riparto\DettaglioRiparto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Il deliberato con le rate di un piano senza le righe del riparto (generato prima della 1.11.0-beta.29), ricostruito quando si legge (Coda 557,
 * decisione 240). Nessuna riga si scrive: le righe del registro le leggono 25 file, e un passaggio già registrato su un piano vecchio si rileggerebbe con
 * regole diverse.
 *
 * - **La ricostruzione** è quella delle stampe del riparto (`DettaglioRiparto::perPiano`, il motore in sola lettura, con le titolarità di oggi): un motore
 *   che si ferma è una ricostruzione che non torna, e un piano senza quote non ha un deliberato con le rate.
 * - **Il controllo è per unità** (D2): per ogni unità la somma delle righe con un soggetto è, al centesimo, la spesa delle quote del piano su quell'unità
 *   (`RataQuote::componenti()['spesa']`: il pregresso resta fuori), in tutte e due le direzioni. Per persona scarterebbe i piani giusti con un passaggio
 *   dopo. È il controllo delle sonde dell'analisi: sui 28 piani approvati con le righe ne accetta 27, uguali per voce alle righe registrate.
 * - **Per voce**, la somma di tutte le righe con la voce tranne quelle delle spese per singole unità, come la lettura delle righe registrate; quelle
 *   sono `personali`, la loro somma.
 * - **Le voci che il piano non ha fissato** (D3) hanno la cifra con gli importi delle voci di oggi: in un piano non straordinario, quelle senza un importo in
 *   `piano_rate_capitoli` (sotto un capitolo fissato, o in un piano creato senza scegliere i capitoli), e quelle il cui capitolo ha un importo nella tabella
 *   (il motore divide l'importo del capitolo fra le voci con gli importi di oggi anche se la voce ha la sua riga: `CalcoloQuoteService`, la forma mista); il
 *   capitolo stesso, quando non ha un importo suo e le sue voci sono divise (in un piano senza capitoli il suo totale è la somma degli importi di oggi). In
 *   un piano straordinario con le fatture nessuna.
 *
 * Il limite (analisi, § 2): legge i dati di oggi. Se dopo cambiano i millesimi, le voci o le quote, il controllo non torna più e il piano torna «non
 * disponibile»; e non vede uno spostamento fra due voci ripartite con la stessa tabella che lasci uguale il totale di ogni unità.
 *
 * **«Sposta spesa»** riscrive gli importi del piano (`piano_rate_capitoli`) e non le quote: rifatto con gli importi di dopo, il riparto direbbe le voci
 * spostate, e il controllo per unità non lo vede quando le voci hanno la stessa tabella. Un piano con spostamenti che non si compensano (il saldo dei
 * movimenti di una voce non è zero, gli storni compresi) non si rifà (decisione 253, D5): {@see spostato()}, che chi legge guarda prima di {@see perPiano()}.
 * Anche quando lo spostamento è di prima di «Ricalcola», e quindi già nelle quote: le date non si confrontano (decisione 255, D2).
 */
final class DeliberatoRicostruito
{
    /**
     * Il piano ha spostamenti di «Sposta spesa» che non si compensano: per almeno una voce il saldo dei movimenti (quanto le è arrivato meno quanto le è
     * stato tolto, gli storni compresi) non è zero.
     */
    public function spostato(PianoRate $piano): bool
    {
        $saldo = [];
        foreach (DB::table('budget_movements')->where('piano_rate_id', $piano->id)->get(['source_conto_id', 'destination_conto_id', 'amount']) as $m) {
            $saldo[(int) $m->source_conto_id] = ($saldo[(int) $m->source_conto_id] ?? 0) - (int) $m->amount;
            $saldo[(int) $m->destination_conto_id] = ($saldo[(int) $m->destination_conto_id] ?? 0) + (int) $m->amount;
        }

        return array_filter($saldo) !== [];
    }

    /**
     * @return array{per_voce: array<int, int>, personali: int, divise_oggi: array<int, true>}|null
     */
    public function perPiano(PianoRate $piano): ?array
    {
        try {
            $dettaglio = DettaglioRiparto::perPiano($piano);
        } catch (\Throwable $e) {
            // Un motore che si ferma è una ricostruzione che non torna; ma un errore vero (una query, un difetto) deve lasciare una traccia.
            Log::warning('Deliberato ricostruito: il motore si è fermato sul piano '.$piano->id.': '.$e->getMessage());

            return null;
        }
        if (($dettaglio['fonte']['tipo'] ?? null) !== DettaglioRiparto::RICOSTRUITO) {
            return null;
        }

        $righe = [];
        $perVoce = [];
        $personali = 0;
        $radice = [];
        foreach ($dettaglio['righe'] as $r) {
            if (($r['anagrafica_id'] ?? null) !== null) {
                $unita = (int) ($r['immobile_id'] ?? 0);
                $righe[$unita] = ($righe[$unita] ?? 0) + (int) $r['importo'];
            }
            if (($r['tipo'] ?? null) === \App\Models\Gestionale\RigaRiparto::TIPO_AD_PERSONAM) {
                $personali += (int) $r['importo'];
            } elseif (($r['conto_id'] ?? null) !== null) {
                $perVoce[(int) $r['conto_id']] = ($perVoce[(int) $r['conto_id']] ?? 0) + (int) $r['importo'];
                $radice[(int) $r['conto_id']] = (int) ($r['conto_radice_id'] ?? $r['conto_id']);
            }
        }

        $quote = [];
        foreach (RataQuote::whereIn('rata_id', $piano->rate()->pluck('id'))->get() as $q) {
            $unita = (int) ($q->immobile_id ?? 0);
            $quote[$unita] = ($quote[$unita] ?? 0) + $q->componenti()['spesa'];
        }

        foreach (array_keys($righe + $quote) as $unita) {
            if (($righe[$unita] ?? 0) !== ($quote[$unita] ?? 0)) {
                return null;
            }
        }

        $divise = [];
        if (! ($piano->tipo === 'straordinario' && $piano->fatture()->exists())) {
            $fissate = DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->whereNotNull('importo')->pluck('conto_id')
                ->map(fn ($id) => (int) $id)->flip()->all();
            foreach (array_keys($perVoce) as $voce) {
                $capitolo = $radice[$voce] ?? $voce;
                // Divisa se la voce non ha un importo suo, o se ce l'ha ma il suo capitolo ne ha uno (il motore divide quello).
                if (! isset($fissate[$voce]) || ($capitolo !== $voce && isset($fissate[$capitolo]))) {
                    $divise[$voce] = true;
                    // Il capitolo senza un importo suo ha per totale la somma degli importi di oggi delle voci: l'asterisco anche a lui.
                    if ($capitolo !== $voce && ! isset($fissate[$capitolo])) {
                        $divise[$capitolo] = true;
                    }
                }
            }
        }

        return ['per_voce' => $perVoce, 'personali' => $personali, 'divise_oggi' => $divise];
    }
}
