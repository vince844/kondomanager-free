<?php

namespace App\Services\Gestionale;

use App\Enums\NaturaGestione;
use App\Enums\RuoloAnagraficaImmobile;
use App\Helpers\DateHelper;
use App\Helpers\MoneyHelper;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RigaRiparto;
use App\Models\Gestionale\Subentro;
use App\Models\Gestione;
use App\Services\CalcoloQuoteService;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use App\Services\Riparto\CompetenzaDelPiano;
use App\Services\Riparto\RisolutoreTitolari;
use App\Support\PeriodoCompetenza;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Il consuntivo per persona: la spesa dell'esercizio che tocca a ogni persona, unità per unità e voce per voce, per gestione.
 *
 * Calcolato e non scritto: legge il giornale (`SpesaPerVoceService`) e divide con il motore del riparto
 * (`CalcoloQuoteService::consuntivoPerGestione`), con le tabelle, i titolari e i giorni del preventivo. Positivo = debito.
 *
 * - **Ordinaria**: lo speso per voce dell'esercizio; i tratti del capitolo dai piani dell'esercizio, se li hanno.
 * - **Straordinaria**: lo speso per fattura, con la competenza scritta sulla fattura (una nota di credito collegata, sempre quella
 *   della fattura che rettifica) o il giorno della delibera dei piani attivi dell'esercizio. Se una spesa senza competenza scritta
 *   non ha la delibera, o quei piani ne hanno più d'una con date diverse, la gestione non si calcola e lo dice.
 *
 * La forma: `persone[gestione][anagrafica][immobile] = ['voci' => [conto => centesimi], 'righe' => [...]]` (le righe solo per
 * le persone chieste) e `gestioni[gestione] = ['nome', 'tipo', 'uscite' => [conto => centesimi], 'diviso_su' => [voce => [conto => centesimi]],
 * 'non_attribuite' => [conto => centesimi], 'motivi' => [conto => [motivo => centesimi]], 'non_calcolabile' => ?string]`. Il
 * conto è quello su cui la voce si divide (il capitolo del piano con le tabelle, o la voce stessa): `diviso_su` dice dove è
 * finita ogni voce del giornale. Per ogni conto le uscite sono la somma delle persone più le non attribuite, al centesimo.
 *
 * **Il conguaglio e il saldo**, alla data della lettura (la fine dell'esercizio, o oggi se l'esercizio non è finito). Per ogni persona e
 * unità, oltre alle voci:
 * - `a`: la spesa delle voci, con il già versato **dell'unità** che i piani dell'esercizio hanno usato tolto dalla spesa dell'unità prima
 *   di dividerla fra i suoi titolari (in proporzione alla parte di ognuno, cioè ai giorni): `gia_versato_unita` dice quanto;
 * - `personali`: le spese per singole unità non ancora chieste da un piano, riga di fattura per riga, a chi era titolare di diritto reale il
 *   giorno della fattura (o il primo giorno della competenza scritta sulla fattura); `di_cui_esercizi_prima`;
 * - `b`: quanto i piani dell'esercizio le chiedono, emesse o no: la spesa delle quote meno la loro parte personale (`quote`, `di_cui_non_emesse`),
 *   le coppie dei passaggi dell'esercizio (`coppie`), la cifra regolata fra le parti letta dal registro del passaggio, per la rinuncia, il
 *   conguaglio annullato e «non scritto» (`regolato`), il già versato della persona che i piani hanno tolto (`gia_versato_persona`); `totale`;
 * - `conguaglio` = a + personali − b: una proposta, calcolata e non scritta;
 * - `posizione`: la posizione sul giornale alla data (le rate emesse, residuo meno credito, rata 0 compresa; le righe su rate non ancora
 *   emesse; gli anticipi), per le gestioni dell'esercizio; `fuori_dalle_rate`: i saldi dell'esercizio non ancora in un piano (le coppie
 *   comprese) e il pregresso delle quote non ancora emesse;
 * - `saldo` = posizione + fuori dalle rate + conguaglio;
 * - `non_disponibile`: il perché, quando un passaggio dell'unità è regolato fra le parti e la cifra non è nel registro, o un piano dell'esercizio
 *   senza il dettaglio del riparto non si ricostruisce; allora `conguaglio`, `saldo` e `b.totale` sono nulli;
 * - `gia_versato_non_usato`: il già versato registrato per le voci delle gestioni dell'esercizio che i piani dell'esercizio non hanno usato,
 *   l'eccedenza compresa, registrato per questo esercizio (`questo_esercizio`) o per tutti (`tutti_gli_esercizi`): quello della persona resta
 *   suo, quello dell'unità va una volta sola ai titolari di diritto reale alla data, per quota (mai all'inquilino). Spiega e basta, non entra nel
 *   conguaglio; che cosa farne lo decide la chiusura;
 * - `avviso_anno_prima`: quando la persona ha sulla stessa unità un saldo iniziale scritto a mano con l'origine dichiarata in un altro esercizio,
 *   applicato o no, **e** una posizione aperta di altre gestioni o spese personali di esercizi prima non ancora chieste (una delle due cifre
 *   diversa da zero, con il suo segno): la frase per l'amministratore con le cifre, e le cifre. Nessuna
 *   cifra si toglie: se il saldo scritto a mano comprende la rata o la spesa dell'anno prima, la stessa cifra conta due volte, e lo controlla
 *   l'amministratore. Sta sulla riga della gestione che ha il saldo.
 * Per gestione, in più: `passaggi` (come è regolato ognuno), `personali_aperte` (le anticipazioni per unità del giornale),
 * `gia_versato_non_tolto` (il già versato dell'unità che nessuna persona dell'unità ha sulla voce).
 *
 * **Da altre gestioni e da esercizi prima** (`da_altre_gestioni`), una riga per persona e unità che non è di nessuna gestione dell'esercizio: la
 * posizione alla data nelle gestioni che l'esercizio non ha (una rata dell'anno prima non ancora pagata, una riga senza gestione) e le spese
 * personali non chieste scritte su quelle gestioni; il loro conguaglio (le sole spese personali) e il loro saldo. Il saldo della persona è la
 * somma delle sue righe delle gestioni e di questa: non dipende dall'ordine in cui le gestioni sono state create.
 *
 * Con l'opzione `per_tabella`, per ogni gestione anche la spesa per persona, unità e tabella millesimale (`per_tabella`), con le tabelle, i
 * millesimi delle unità e il ruolo di ognuno: la stampa del consuntivo per tabella.
 *
 * **La competenza scritta sulla fattura dell'ordinaria**: dove la fattura la dice, la sua spesa va a chi era titolare in quei giorni (tagliati
 * sull'esercizio), altrimenti il capitolo e i giorni; la riga dice ogni spesa così e la parte della persona (`competenze_scritte`, anche zero).
 *
 * **L'opera straordinaria**: finché la data di fine della gestione è vuota o dopo l'esercizio, l'opera è in corso: la gestione dice lo speso (dal suo
 * inizio) e il chiesto (`in_corso`) e non propone: le righe hanno `in_corso`, nessuna spesa dell'opera, nessuna quota, e il conguaglio sono le sole
 * spese personali. Con la data di fine dentro l'esercizio il conguaglio è sull'intera gestione: le spese e le quote di tutti i suoi esercizi fino a
 * questo, con le coppie e i passaggi; `opera_finita` dice le spese degli esercizi prima. `speso` è lo speso dell'esercizio della gestione. In un
 * esercizio dopo quello della fine (`finita_prima`) le spese dell'opera scritte lì si conguagliano lì, da sole: senza quote né coppie, che sono nel
 * conguaglio dell'opera.
 *
 * **Le quote contro il riparto** (`riparto_per_unita`, per gestione): per i piani con le righe del riparto, la spesa delle quote di ogni unità è la
 * somma delle sue righe; dove non torna, il conguaglio delle righe di quell'unità non è disponibile, con il perché.
 */
class ConsuntivoPerPersona
{
    public function __construct(
        private readonly SpesaPerVoceService $spese = new SpesaPerVoceService(),
    ) {}

    /**
     * @param  list<int>  $righePer  le persone di cui tenere le righe di spiegazione
     * @return array{persone: array<int, array<int, array<int, array{voci: array<int,int>, righe: list<array<string,mixed>>}>>>, gestioni: array<int, array<string,mixed>>}
     */
    public function perEsercizio(Esercizio $esercizio, array $righePer = [], ?string $alla = null, bool $perTabella = false): array
    {
        $oggi = DateHelper::oggiUtente();
        $fine = $esercizio->data_fine === null ? $oggi : substr((string) $esercizio->data_fine, 0, 10);
        $alla ??= min($fine, $oggi);
        $pianiIds = [];
        $pianiPer = [];
        $dalPer = [];
        $eserciziPer = [];
        $inCorso = [];
        $dopoLaFine = [];
        $gestioni = Gestione::join('esercizio_gestione as eg', 'eg.gestione_id', '=', 'gestioni.id')
            ->where('eg.esercizio_id', $esercizio->id)
            ->orderBy('gestioni.id')
            ->get(['gestioni.*']);
        $speso = $this->spese->perEsercizio($esercizio);
        $competenza = app(CompetenzaDelPiano::class);

        $risultato = ['persone' => [], 'gestioni' => []];
        $dedotti = [];
        foreach ($gestioni as $gestione) {
            $voci = DB::table('conti')->join('piani_conti as pc', 'pc.id', '=', 'conti.piano_conto_id')
                ->where('pc.gestione_id', $gestione->id)->pluck('conti.id')->map(fn ($id) => (int) $id)->all();
            $straordinaria = NaturaGestione::daStringa($gestione->tipo) === NaturaGestione::Straordinaria;
            $piani = PianoRate::where('gestione_id', $gestione->id)->orderBy('id')->get();
            // L'opera di una straordinaria: in corso, o finita in questo esercizio con i suoi esercizi; null, l'esercizio da solo.
            $opera = $straordinaria ? $this->opera($gestione, $esercizio) : null;
            $suoiEsercizi = $opera['esercizi'] ?? [$esercizio];
            $suoiIds = array_map(fn (Esercizio $e) => (int) $e->id, $suoiEsercizi);
            // Le quote di un'opera finita sono quelle di tutti i suoi piani, anche di un esercizio dopo (la rata a saldo): l'opera si conguaglia una volta.
            $pianiDellEsercizio = ($opera['stato'] ?? null) === 'finita' ? $piani->values()
                : $piani->filter(fn (PianoRate $p) => in_array($competenza->esercizioDelPiano($p)?->id, $suoiIds, true))->values();
            $idsDeiPiani = $pianiDellEsercizio->pluck('id')->map(fn ($id) => (int) $id)->all();
            // I piani dell'esercizio senza l'esercizio scritto: il programma lo deduce, e la scheda lo dice.
            foreach ($pianiDellEsercizio as $p) {
                if ($competenza->esercizioDedotto($p)) {
                    $dedotti[$gestione->id][] = ['piano' => (string) $p->nome, 'esercizio' => (string) ($competenza->esercizioDelPiano($p)?->nome ?? '')];
                }
            }

            if (($opera['stato'] ?? null) === 'finita_prima') {
                // L'opera finita in un esercizio prima: il suo conguaglio, con tutte le quote, è nel rendiconto di quell'esercizio. Le spese scritte qui,
                // dopo la fine (la fattura finale che arriva l'anno dopo), si conguagliano qui, da sole: divise alla delibera del primo piano dell'opera,
                // quella che l'ha approvata (non a quella del piano a saldo), senza quote e senza coppie, che sono nel conguaglio dell'opera.
                $suoiEsercizi = [$esercizio];
                $pianiDellEsercizio = $piani->values();
                $idsDeiPiani = [];
                $dopoLaFine[$gestione->id] = true;
            }
            if (($opera['stato'] ?? null) === 'in_corso') {
                // L'opera in corso: lo speso e il chiesto, senza proporre; il conguaglio si propone quando l'opera è finita.
                $inCorso[$gestione->id] = true;
                $chiesti = 0;
                foreach (app(LetturaDebiti::class)->quoteDeiPianiPerUnita((int) $esercizio->condominio_id, $idsDeiPiani, $alla) as $perGestione) {
                    foreach ($perGestione[$gestione->id] ?? [] as $q) {
                        $chiesti += $q['spesa'] - $q['personale'];
                    }
                }
                $spesoQui = (int) array_sum(array_column($this->spese->perEsercizioEFattura($esercizio, $voci), 'importo'));
                $risultato['gestioni'][$gestione->id] = ['nome' => (string) $gestione->nome, 'tipo' => (string) $gestione->tipo, 'uscite' => [], 'diviso_su' => [],
                    'non_attribuite' => [], 'motivi' => [], 'non_calcolabile' => null, 'speso' => $spesoQui, 'competenze_scritte' => [], 'divisioni' => [],
                    'in_corso' => ['spesi' => (int) $this->spese->cumulatoDellaGestione((int) $gestione->id, substr((string) $esercizio->data_inizio, 0, 10))['consuntivo'],
                        'chiesti' => $chiesti, 'speso_esercizio' => $spesoQui, 'data_fine' => $opera['data_fine'],
                        // Con la data di fine dopo l'esercizio: la gestione è legata all'esercizio in cui finisce? Se no, lì il conguaglio non si proporrebbe.
                        'legata_alla_fine' => $opera['data_fine'] === null ? null : $opera['legata_alla_fine']]];
                if ($perTabella) {
                    $risultato['per_tabella'][$gestione->id] = ['righe' => [], 'tabelle' => [], 'millesimi' => [], 'ruoli' => []];
                }

                continue;
            }
            $pianiIds = [...$pianiIds, ...$idsDeiPiani];
            $pianiPer[$gestione->id] = $idsDeiPiani;

            $opzioni = ['righe_per' => $righePer, 'per_tabella' => $perTabella];
            $spesoPrima = 0;
            $periodiDiversi = [];
            if ($straordinaria) {
                // Una spesa senza competenza scritta va al giorno della delibera che ha approvato l'opera, quella del primo piano: la spesa straordinaria è
                // di chi era titolare quando l'assemblea l'ha deliberata; un piano dell'anno dopo la finanzia, non la delibera di nuovo, e le spese degli altri
                // esercizi dell'opera vanno a quella. Nell'esercizio del primo piano contano le delibere dei suoi piani attivi: con date diverse il programma
                // non sceglie (un piano dell'anno dopo, o spento, non lo ferma).
                [$delibereDi, $primaDelibera, $esercizioDelPrimo] = $this->delibereDellOpera($pianiDellEsercizio, $competenza);
                // Un'opera finita in questo esercizio: le spese di tutti i suoi esercizi.
                $cifre = [];
                foreach ($suoiEsercizi as $e) {
                    $sue = $this->spese->perEsercizioEFattura($e, $voci);
                    if ((int) $e->id !== (int) $esercizio->id) {
                        $spesoPrima += (int) array_sum(array_column($sue, 'importo'));
                    }
                    $sueDelibere = (int) $e->id === $esercizioDelPrimo ? ($delibereDi[(int) $e->id] ?? []) : [];
                    foreach ($sue as $riga) {
                        $cifre[] = $riga + ['delibera' => count($sueDelibere) === 1 ? $sueDelibere[0] : ($sueDelibere === [] ? $primaDelibera : null),
                            'delibere_diverse' => count($sueDelibere) > 1];
                    }
                }
                $opzioni['delibere'] = array_values(array_unique(array_merge(...array_values($delibereDi ?: [[]]))));
                $spesoQui = (int) array_sum(array_column($cifre, 'importo')) - $spesoPrima;
            } else {
                $cifre = array_filter(array_intersect_key($speso, array_flip($voci)), fn (int $c) => $c !== 0);
                $opzioni['capitoli'] = $this->capitoliDellEsercizio($pianiDellEsercizio);
                // Le fatture con la competenza scritta: la loro spesa va a chi era titolare in quei giorni, solo dove la fattura la dice.
                $opzioni['competenze_scritte'] = array_values(array_filter($this->spese->perEsercizioEFattura($esercizio, array_keys($cifre)),
                    fn (array $r) => $r['competenza_dal'] !== null && $r['competenza_al'] !== null && $r['importo'] !== 0));
                $spesoQui = (int) array_sum($cifre);
                // Due piani dell'esercizio con periodi diversi per la stessa voce, e un'unità della voce che cambia titolare: il programma non sceglie quale
                // periodo vale. La voce non si divide (resta fra le uscite che nessuno paga, con il perché); la spesa con la competenza scritta sulla fattura sì.
                $fermate = $this->vociConPeriodiDiversi($pianiDellEsercizio, array_keys($cifre), $esercizio);
                foreach ($fermate as $vid => $piani) {
                    $scritte = (int) array_sum(array_column(array_filter($opzioni['competenze_scritte'], fn (array $r) => (int) $r['conto_id'] === $vid), 'importo'));
                    $periodiDiversi[$vid] = ['piani' => $piani, 'importo' => (int) $cifre[$vid] - $scritte];
                    if ($scritte === 0) {
                        unset($cifre[$vid]);
                    } else {
                        $cifre[$vid] = $scritte;
                    }
                }
            }

            $esito = (new CalcoloQuoteService())->consuntivoPerGestione($gestione, $esercizio, $cifre, $opzioni);
            foreach ($periodiDiversi ?? [] as $vid => $fermata) {
                if ($fermata['importo'] !== 0) {
                    $esito['non_attribuite'][$vid] = ($esito['non_attribuite'][$vid] ?? 0) + $fermata['importo'];
                    $esito['motivi'][$vid]['periodi_diversi'] = ($esito['motivi'][$vid]['periodi_diversi'] ?? 0) + abs($fermata['importo']);
                }
            }

            foreach ($esito['persone'] as $aid => $perUnita) {
                foreach ($perUnita as $iid => $perVoce) {
                    $risultato['persone'][$gestione->id][$aid][$iid] = ['voci' => $perVoce, 'righe' => []];
                }
            }
            foreach ($esito['righe'] as $riga) {
                if ($riga['anagrafica_id'] !== null && isset($risultato['persone'][$gestione->id][$riga['anagrafica_id']][$riga['immobile_id']])) {
                    $risultato['persone'][$gestione->id][$riga['anagrafica_id']][$riga['immobile_id']]['righe'][] = $riga;
                }
            }
            $risultato['gestioni'][$gestione->id] = [
                'nome' => (string) $gestione->nome,
                'tipo' => (string) $gestione->tipo,
                'uscite' => $esito['uscite'],
                'diviso_su' => $esito['diviso_su'],
                'non_attribuite' => $esito['non_attribuite'],
                'motivi' => $esito['motivi'],
                'non_calcolabile' => $esito['non_calcolabile'],
                // Lo speso dell'esercizio (di un'opera finita, quello di questo esercizio): la 8.3 lo conta per la gestione che non si calcola.
                'speso' => $spesoQui,
                'competenze_scritte' => $esito['competenze_scritte'],
                // Quante volte il motore ha diviso ogni conto (una per competenza): il controllo per unità ne conta i centesimi del resto.
                'divisioni' => $esito['divisioni'],
                // Le voci ferme per i periodi diversi di due piani: i piani, per voce.
                'periodi_diversi' => array_map(fn (array $f) => $f['piani'], $periodiDiversi),
            ];
            if (isset($dopoLaFine[$gestione->id])) {
                $risultato['gestioni'][$gestione->id]['finita_prima'] = ['data_fine' => $opera['data_fine'], 'esercizio' => $opera['esercizio_della_fine'], 'speso_esercizio' => $spesoQui];
            }
            if (($opera['stato'] ?? null) === 'finita' && count($suoiEsercizi) > 1) {
                $prima = array_values(array_filter($suoiEsercizi, fn (Esercizio $e) => (int) $e->id !== (int) $esercizio->id));
                $risultato['gestioni'][$gestione->id]['opera_finita'] = ['data_fine' => $opera['data_fine'], 'spese_esercizi_prima' => $spesoPrima,
                    'esercizi_prima' => array_map(fn (Esercizio $e) => (string) $e->nome, $prima)];
                $dalPer[$gestione->id] = substr((string) $suoiEsercizi[0]->data_inizio, 0, 10);
                $eserciziPer[$gestione->id] = array_map(fn (Esercizio $e) => (int) $e->id, $prima);
            }
            if ($perTabella) {
                $risultato['per_tabella'][$gestione->id] = $esito['per_tabella'];
            }
        }

        foreach ($dedotti as $g => $elenco) {
            $risultato['gestioni'][$g]['piani_dedotti'] = $elenco;
        }

        return $this->conguaglio($risultato, $esercizio, $gestioni->pluck('id')->map(fn ($id) => (int) $id)->all(), $pianiIds, $alla,
            ['piani' => $pianiPer, 'dal' => $dalPer, 'esercizi_prima' => $eserciziPer, 'in_corso' => $inCorso, 'dopo_la_fine' => $dopoLaFine]);
    }

    /**
     * L'opera di una gestione straordinaria, per l'esercizio: **in corso** finché la data di fine della gestione è vuota o dopo l'esercizio; **finita**
     * quando la data di fine è dentro l'esercizio, e allora il conguaglio è sull'intera gestione, con i suoi esercizi fino a questo (`esercizi`,
     * dal primo); **finita prima** quando la data di fine è prima dell'esercizio: il conguaglio è nel rendiconto dell'esercizio in cui è finita, e qui
     * si conguagliano solo le spese scritte dopo la fine.
     *
     * @return array{stato: string, esercizi: list<Esercizio>, data_fine: ?string, legata_alla_fine?: bool, esercizio_della_fine?: ?string}
     */
    private function opera(Gestione $gestione, Esercizio $esercizio): array
    {
        $fine = $gestione->data_fine === null ? null : substr((string) $gestione->data_fine, 0, 10);
        $inizio = substr((string) $esercizio->data_inizio, 0, 10);
        // Gli esercizi a cui la gestione è legata, in ordine.
        $legati = Esercizio::join('esercizio_gestione as eg', 'eg.esercizio_id', '=', 'esercizi.id')->where('eg.gestione_id', $gestione->id)
            ->orderBy('esercizi.data_inizio')->get(['esercizi.*']);
        $contiene = fn (Esercizio $e) => substr((string) $e->data_inizio, 0, 10) <= $fine && $fine <= substr((string) $e->data_fine, 0, 10);
        if ($fine !== null && $fine < $inizio) {
            $della = $legati->first($contiene) ?? Esercizio::where('condominio_id', $esercizio->condominio_id)->get()->first($contiene);

            return ['stato' => 'finita_prima', 'esercizi' => [], 'data_fine' => $fine, 'esercizio_della_fine' => $della === null ? null : (string) $della->nome];
        }
        $esercizi = $legati->filter(fn (Esercizio $e) => substr((string) $e->data_inizio, 0, 10) <= $inizio && (int) $e->id !== (int) $esercizio->id)->values()
            ->push($esercizio)->all();

        return ['stato' => $fine === null || $fine > substr((string) $esercizio->data_fine, 0, 10) ? 'in_corso' : 'finita', 'esercizi' => $esercizi, 'data_fine' => $fine,
            'legata_alla_fine' => $fine !== null && $legati->contains($contiene)];
    }

    /**
     * Le delibere dei piani attivi di un'opera, per esercizio del piano; la prima (del primo piano: quello con la delibera più vecchia, a parità il numero più
     * basso, non quello scritto per primo), quella che ha approvato l'opera e che vale per le spese degli altri esercizi dell'opera; e l'esercizio del primo piano.
     *
     * @return array{0: array<int, list<string>>, 1: ?string, 2: ?int}
     */
    private function delibereDellOpera($piani, CompetenzaDelPiano $competenza): array
    {
        $perEsercizio = [];
        $ordine = [];
        foreach ($piani as $p) {
            if (! (bool) $p->attivo || $p->data_delibera_assemblea === null) {
                continue;
            }
            $e = $competenza->esercizioDelPiano($p);
            $d = substr((string) $p->data_delibera_assemblea, 0, 10);
            $perEsercizio[(int) ($e?->id ?? 0)][$d] = $d;
            $ordine[] = [$d, (int) $p->id, (int) ($e?->id ?? 0)];
        }
        sort($ordine);

        return [array_map('array_values', $perEsercizio), $ordine === [] ? null : $ordine[0][0], $ordine === [] ? null : $ordine[0][2]];
    }

    /**
     * Aggiunge alla spesa delle voci il già versato, le spese personali, le quote dei piani, la posizione e il saldo (vedi la classe).
     *
     * @param array{piani?: array<int, list<int>>, dal?: array<int,string>, esercizi_prima?: array<int, list<int>>, in_corso?: array<int,bool>, dopo_la_fine?: array<int,bool>} $opere
     *        per gestione: i piani delle quote, e per un'opera finita su più esercizi il primo giorno e gli esercizi prima; le opere in corso; le opere
     *        finite in un esercizio prima, con le spese scritte qui (senza quote né coppie)
     */
    private function conguaglio(array $risultato, Esercizio $esercizio, array $gestioniIds, array $pianiIds, string $alla, array $opere = []): array
    {
        $condominioId = (int) $esercizio->condominio_id;
        $lettura = app(LetturaDebiti::class);
        $quote = $lettura->quoteDeiPianiPerUnita($condominioId, $pianiIds, $alla);
        $nelleGestioni = array_flip($gestioniIds);
        $inCorso = $opere['in_corso'] ?? [];
        $dopoLaFine = $opere['dopo_la_fine'] ?? [];
        $ripartoNon = [];
        foreach ($this->quoteControRiparto($condominioId, $quote, $opere['piani'] ?? [], $alla) as $g => $controllo) {
            $risultato['gestioni'][$g]['riparto_per_unita'] = $controllo;
            foreach ($controllo['non_tornano'] as $iid => $f) {
                $ripartoNon[$g][$iid] = 'Le quote dei piani su questa unità ('.self::euro($f['quote']).') non sono il riparto registrato ('.self::euro($f['riparto'])
                    .'): il piano è cambiato dopo il riparto, per esempio una tabella. Il conguaglio di questa unità non si calcola.';
            }
        }

        // La posizione delle gestioni dell'esercizio, e quella delle altre (l'ordinaria dell'anno prima non ancora riportata, una riga senza
        // gestione), per persona e unità: la riga «da altre gestioni e da esercizi prima».
        $posizioni = [];
        $altre = [];
        foreach ($lettura->posizioniPerUnita($condominioId, $alla) as $aid => $perGestione) {
            foreach ($perGestione as $g => $perUnita) {
                foreach ($perUnita as $iid => $cifra) {
                    if (isset($nelleGestioni[$g])) {
                        $posizioni[$aid][$g][$iid] = $cifra;
                    } else {
                        $altre[$aid][$iid] = ($altre[$aid][$iid] ?? 0) + $cifra;
                    }
                }
            }
        }
        // Le anticipazioni aperte per unità; quelle di una gestione fuori dall'esercizio a parte (`0`).
        $aperte = [];
        foreach ($lettura->anticipazioniPerUnita($condominioId, $alla) as $g => $perUnita) {
            foreach ($perUnita as $iid => $cifra) {
                $dove = isset($nelleGestioni[$g]) ? $g : 0;
                $aperte[$dove][$iid] = ($aperte[$dove][$iid] ?? 0) + $cifra;
            }
        }

        // Il già versato che i piani dell'esercizio hanno tolto: le righe `netting` del riparto, della persona e dell'unità.
        $versatoPersona = [];
        $versatoUnita = [];
        $usato = [];
        foreach (DB::table('righe_riparto as rr')->join('piani_rate as p', 'p.id', '=', 'rr.piano_rate_id')
            ->whereIn('rr.piano_rate_id', $pianiIds)->where('rr.tipo', RigaRiparto::TIPO_NETTING)
            ->get(['p.gestione_id', 'rr.anagrafica_id', 'rr.immobile_id', 'rr.conto_id', 'rr.importo', 'rr.riga_descrizione']) as $r) {
            $g = (int) $r->gestione_id;
            $dellaPersona = $r->riga_descrizione === CalcoloQuoteService::NETTING_DELLA_PERSONA;
            if ($dellaPersona) {
                $versatoPersona[$g][(int) $r->anagrafica_id][(int) $r->immobile_id] = ($versatoPersona[$g][(int) $r->anagrafica_id][(int) $r->immobile_id] ?? 0) - (int) $r->importo;
            } else {
                $versatoUnita[$g][(int) $r->conto_id][(int) $r->immobile_id] = ($versatoUnita[$g][(int) $r->conto_id][(int) $r->immobile_id] ?? 0) - (int) $r->importo;
            }
            $chi = $dellaPersona ? (int) $r->anagrafica_id : 0;
            $usato[$g][(int) $r->immobile_id][$chi] = ($usato[$g][(int) $r->immobile_id][$chi] ?? 0) - (int) $r->importo;
        }
        // Il già versato non usato: quello della persona resta suo; quello dell'unità va una volta sola, ai titolari di diritto reale alla data, per
        // quota (mai all'inquilino), nell'ordine della catena del saldo pregresso della gestione (ordinaria: prima l'usufruttuario; straordinaria:
        // prima il nudo proprietario); senza un titolare quel giorno resta della gestione.
        $nonUsato = [];
        $nonUsatoSenzaTitolare = [];
        $daDecidere = [];
        $titolariNonUsato = app(RisolutoreTitolari::class);
        $daDividere = $this->giaVersatoNonUsato($esercizio, $usato);
        // Dopo un cambio di titolare nell'esercizio il già versato dell'unità non usato non va sul foglio di nessuno: a chi va lo decide l'amministratore.
        $cambiano = $daDividere === [] ? [] : $this->unitaCheCambiano($esercizio, true);
        $tipi = $daDividere === [] ? [] : DB::table('gestioni')->whereIn('id', array_keys($daDividere))->pluck('tipo', 'id')->all();
        foreach ($daDividere as $g => $perUnita) {
            $ordine = self::ordineDeiTitolari($tipi[$g] ?? null);
            foreach ($perUnita as $iid => $perChi) {
                foreach ($perChi as $chi => $ambiti) {
                    $destinatari = $chi !== 0 ? [$chi => 1.0] : $this->titolariDiDirittoReale($titolariNonUsato, (int) $iid, $alla, $ordine);
                    if ($destinatari === []) {
                        $nonUsatoSenzaTitolare[$g][$iid] = ($nonUsatoSenzaTitolare[$g][$iid] ?? 0) + $ambiti['questo_esercizio'] + $ambiti['tutti_gli_esercizi'];

                        continue;
                    }
                    if ($chi === 0 && isset($cambiano[(int) $iid])) {
                        $daDecidere[$g][$iid] = ($daDecidere[$g][$iid] ?? 0) + $ambiti['questo_esercizio'] + $ambiti['tutti_gli_esercizi'];

                        continue;
                    }
                    foreach ($ambiti as $ambito => $importo) {
                        foreach (MoneyHelper::ripartisciPerQuote($importo, $destinatari) as $aid => $parte) {
                            $nonUsato[$g][$aid][$iid][$ambito] = ($nonUsato[$g][$aid][$iid][$ambito] ?? 0) + $parte;
                        }
                    }
                }
            }
        }

        // I saldi dell'esercizio: le coppie dei passaggi (in B) e quelli non ancora in un piano (fuori dalle rate).
        $coppie = [];
        $liberi = [];
        // I saldi scritti a mano con l'origine dichiarata in un altro esercizio, applicati o no, per gestione, persona e unità: se la stessa persona ha
        // anche rate o spese personali aperte di esercizi prima, la stessa cifra può essere nelle due (l'avviso sulla riga).
        $conOrigine = [];
        foreach (DB::table('saldi')->where('condominio_id', $condominioId)->where('esercizio_id', $esercizio->id)->whereIn('gestione_id', $gestioniIds)->whereNotNull('anagrafica_id')
            ->get(['anagrafica_id', 'immobile_id', 'gestione_id', 'subentro_id', 'saldo_iniziale', 'is_applicato', 'esercizio_origine_id']) as $r) {
            $k = [(int) $r->gestione_id, (int) $r->anagrafica_id, (int) $r->immobile_id];
            if ($r->subentro_id !== null) {
                $coppie[$k[0]][$k[1]][$k[2]] = ($coppie[$k[0]][$k[1]][$k[2]] ?? 0) + (int) $r->saldo_iniziale;
            }
            if (! (bool) $r->is_applicato) {
                $liberi[$k[0]][$k[1]][$k[2]] = ($liberi[$k[0]][$k[1]][$k[2]] ?? 0) + (int) $r->saldo_iniziale;
            }
            if ($r->subentro_id === null && $r->esercizio_origine_id !== null && (int) $r->esercizio_origine_id !== (int) $esercizio->id) {
                $o = &$conOrigine[$k[0]][$k[1]][$k[2]];
                $o ??= ['importo' => 0, 'applicati' => 0, 'esercizi' => []];
                $o['importo'] += (int) $r->saldo_iniziale;
                $o['applicati'] += (bool) $r->is_applicato ? (int) $r->saldo_iniziale : 0;
                $o['esercizi'][(int) $r->esercizio_origine_id] = (int) $r->esercizio_origine_id;
                unset($o);
            }
        }
        // Le coppie dei passaggi degli esercizi prima di un'opera finita in questo: le sue quote sono di tutti i suoi esercizi, e le coppie con loro; quelle
        // non ancora in un piano sono anche fuori dalle rate, come le coppie dell'esercizio: nel saldo come nelle quote.
        foreach ($opere['esercizi_prima'] ?? [] as $g => $esercizi) {
            foreach (DB::table('saldi')->where('condominio_id', $condominioId)->where('gestione_id', $g)->whereIn('esercizio_id', $esercizi)->whereNotNull('anagrafica_id')
                ->whereNotNull('subentro_id')->get(['anagrafica_id', 'immobile_id', 'saldo_iniziale', 'is_applicato']) as $r) {
                $coppie[$g][(int) $r->anagrafica_id][(int) $r->immobile_id] = ($coppie[$g][(int) $r->anagrafica_id][(int) $r->immobile_id] ?? 0) + (int) $r->saldo_iniziale;
                if (! (bool) $r->is_applicato) {
                    $liberi[$g][(int) $r->anagrafica_id][(int) $r->immobile_id] = ($liberi[$g][(int) $r->anagrafica_id][(int) $r->immobile_id] ?? 0) + (int) $r->saldo_iniziale;
                }
            }
        }

        [$regolato, $passaggi, $nonDisponibile, $comeDi] = $this->passaggi($esercizio, $gestioniIds, $opere['dal'] ?? []);
        [$personali, $senzaTitolare, $nonRicostruite, $nonEmessePerUnita] = $this->personali($esercizio, $nelleGestioni, $alla);
        // Le spese personali per unità: non chieste, di giorni senza un titolare, chieste con rate non ancora emesse, contro le anticipazioni aperte. Dove non
        // tornano, una spesa personale non è né da chiedere né in un piano: il controllo delle spese personali e la riga dell'unità lo dicono.
        $personaliPerUnita = [];
        $zero = ['non_chieste' => 0, 'senza_titolare' => 0, 'non_emesse' => 0, 'aperte' => 0];
        foreach ($personali as $perPersona) {
            foreach ($perPersona as $perUnita) {
                foreach ($perUnita as $iid => $sue) {
                    $personaliPerUnita[$iid] ??= $zero;
                    $personaliPerUnita[$iid]['non_chieste'] += (int) array_sum(array_column($sue, 'importo'));
                }
            }
        }
        foreach ([['senza_titolare', $senzaTitolare], ['aperte', $aperte]] as [$chiave, $fonte]) {
            foreach ($fonte as $perUnita) {
                foreach ($perUnita as $iid => $cifra) {
                    if ((int) $iid !== 0) {
                        $personaliPerUnita[$iid] ??= $zero;
                        $personaliPerUnita[$iid][$chiave] += (int) $cifra;
                    }
                }
            }
        }
        foreach ($nonEmessePerUnita as $iid => $cifra) {
            $personaliPerUnita[$iid] ??= $zero;
            $personaliPerUnita[$iid]['non_emesse'] += (int) $cifra;
        }
        $personaliPerUnita = array_filter($personaliPerUnita, fn (array $u) => $u !== $zero);
        $nonRegistrati = $this->passaggiNonRegistrati($esercizio);
        foreach ($nonRicostruite as $g => $perUnita) {
            foreach ($perUnita as $iid => $motivo) {
                foreach ($quote as $aid => $perGestione) {
                    if (isset($perGestione[$g][$iid])) {
                        $nonDisponibile[$g][$aid][$iid] ??= $motivo;
                    }
                }
            }
        }

        // La riga «da altre gestioni e da esercizi prima», per persona e unità: la posizione e le spese personali non chieste delle gestioni che
        // l'esercizio non ha. Il suo conguaglio sono le sole spese personali (A e B sono delle gestioni dell'esercizio).
        $daAltre = ['persone' => [], 'personali_aperte' => $aperte[0] ?? [], 'personali_senza_titolare' => $senzaTitolare[0] ?? [],
            'totali' => ['posizione' => 0, 'personali' => 0, 'conguaglio' => 0, 'saldo' => 0]];
        $chiaviAltre = [];
        foreach ([$altre, $personali[0] ?? []] as $fonte) {
            foreach ($fonte as $aid => $perUnita) {
                foreach ($perUnita as $iid => $_) {
                    $chiaviAltre[$aid][$iid] = true;
                }
            }
        }
        foreach ($chiaviAltre as $aid => $perUnita) {
            foreach ($perUnita as $iid => $_) {
                $sue = $personali[0][$aid][$iid] ?? [];
                $nonChieste = (int) array_sum(array_column($sue, 'importo'));
                $riga = ['posizione' => $altre[$aid][$iid] ?? 0, 'personali' => ['non_chieste' => $nonChieste,
                    'di_cui_esercizi_prima' => (int) array_sum(array_column(array_filter($sue, fn ($r) => $r['esercizio_prima']), 'importo')), 'righe' => $sue]];
                $riga['conguaglio'] = $nonChieste;
                $riga['saldo'] = $riga['posizione'] + $riga['conguaglio'];
                if ($riga['posizione'] === 0 && $nonChieste === 0 && $sue === []) {
                    continue;
                }
                $daAltre['persone'][$aid][$iid] = $riga;
                foreach (['posizione' => $riga['posizione'], 'personali' => $nonChieste, 'conguaglio' => $riga['conguaglio'], 'saldo' => $riga['saldo']] as $k => $v) {
                    $daAltre['totali'][$k] += $v;
                }
            }
        }
        // Quello che la persona ha ancora aperto di esercizi prima, su ogni unità, in due cifre con il loro segno: la posizione delle altre gestioni (anche
        // un credito), e le spese personali nate prima non ancora chieste (anche un rimborso), di altre gestioni o di una gestione dell'esercizio.
        $apertoPrima = [];
        foreach ($daAltre['persone'] as $aid => $perUnita) {
            foreach ($perUnita as $iid => $riga) {
                $apertoPrima[$aid][$iid] = ['posizione' => $riga['posizione'], 'personali' => $riga['personali']['di_cui_esercizi_prima']];
            }
        }
        foreach ($personali as $g => $perPersona) {
            if ($g === 0) {
                continue;
            }
            foreach ($perPersona as $aid => $perUnita) {
                foreach ($perUnita as $iid => $sue) {
                    $prima = (int) array_sum(array_column(array_filter($sue, fn ($r) => $r['esercizio_prima']), 'importo'));
                    if ($prima !== 0) {
                        $apertoPrima[$aid][$iid] ??= ['posizione' => 0, 'personali' => 0];
                        $apertoPrima[$aid][$iid]['personali'] += $prima;
                    }
                }
            }
        }
        $nomiEsercizi = $conOrigine === [] ? [] : Esercizio::where('condominio_id', $condominioId)->pluck('nome', 'id')->all();

        $vuota = ['voci' => [], 'righe' => []];
        foreach ($gestioniIds as $g) {
            $persone = $risultato['persone'][$g] ?? [];
            $chiavi = [];
            foreach ([$persone, $personali[$g] ?? [], $coppie[$g] ?? [], $liberi[$g] ?? [], $regolato[$g] ?? [], $versatoPersona[$g] ?? [], $nonUsato[$g] ?? []] as $fonte) {
                foreach ($fonte as $aid => $perUnita) {
                    foreach ($perUnita as $iid => $_) {
                        $chiavi[$aid][$iid] = true;
                    }
                }
            }
            foreach ([$quote, $posizioni] as $fonte) {
                foreach ($fonte as $aid => $perGestione) {
                    foreach ($perGestione[$g] ?? [] as $iid => $_) {
                        $chiavi[$aid][$iid] = true;
                    }
                }
            }

            // Il già versato dell'unità, tolto dalla spesa della voce dell'unità prima dei giorni: diviso fra le persone dell'unità in
            // proporzione alla loro parte della voce.
            $tolto = [];
            $nonTolto = [];
            foreach ($versatoUnita[$g] ?? [] as $conto => $perUnita) {
                foreach ($perUnita as $iid => $versato) {
                    $pesi = [];
                    foreach ($persone as $aid => $perImmobile) {
                        if (isset($perImmobile[$iid]['voci'][$conto])) {
                            $pesi[$aid] = (float) max(0, $perImmobile[$iid]['voci'][$conto]);
                        }
                    }
                    if ($pesi === [] || array_sum($pesi) <= 0) {
                        $nonTolto[$iid][$conto] = ($nonTolto[$iid][$conto] ?? 0) + $versato;

                        continue;
                    }
                    foreach (MoneyHelper::ripartisciPerQuote($versato, $pesi) as $aid => $parte) {
                        $persone[$aid][$iid]['voci'][$conto] -= $parte;
                        $tolto[$aid][$iid] = ($tolto[$aid][$iid] ?? 0) + $parte;
                    }
                }
            }

            $totali = ['a' => 0, 'personali' => 0, 'b' => 0, 'conguaglio' => 0, 'posizione' => 0, 'fuori_dalle_rate' => 0, 'saldo' => 0, 'non_disponibili' => 0, 'a_non_disponibili' => 0];
            foreach ($chiavi as $aid => $perUnita) {
                foreach ($perUnita as $iid => $_) {
                    $riga = $persone[$aid][$iid] ?? $vuota;
                    $q = $quote[$aid][$g][$iid] ?? null;
                    // L'opera in corso non propone: le quote, le coppie e la cifra regolata contano quando l'opera è finita, sull'intera gestione. Le spese
                    // scritte dopo la fine di un'opera finita prima non hanno quote né coppie: sono tutte nel conguaglio dell'opera.
                    $operaInCorso = isset($inCorso[$g]);
                    $senzaCoppie = $operaInCorso || isset($dopoLaFine[$g]);
                    $b = [
                        'quote' => $q === null ? 0 : $q['spesa'] - $q['personale'],
                        'di_cui_non_emesse' => $q === null ? 0 : $q['spesa_non_emessa'] - $q['personale_non_emessa'],
                        'coppie' => $senzaCoppie ? 0 : $coppie[$g][$aid][$iid] ?? 0,
                        'regolato' => $senzaCoppie ? 0 : $regolato[$g][$aid][$iid] ?? 0,
                        'gia_versato_persona' => $versatoPersona[$g][$aid][$iid] ?? 0,
                    ];
                    $riga['in_corso'] = $operaInCorso;
                    $pu = $personaliPerUnita[$iid] ?? null;
                    $riga['personali_non_tornano'] = $pu !== null && $pu['non_chieste'] + $pu['senza_titolare'] + $pu['non_emesse'] !== $pu['aperte'] ? $pu : null;
                    $riga['passaggio_non_registrato'] = $nonRegistrati[$iid] ?? [];
                    $riga['gia_versato_da_decidere'] = (int) ($daDecidere[$g][$iid] ?? 0);
                    // La spesa con la competenza scritta sulla fattura che tocca l'unità, per conto, e la parte della persona (anche zero: quei giorni non
                    // erano suoi), con il primo e l'ultimo giorno e quante spese.
                    $riga['competenze_scritte'] = array_values(array_map(fn (array $c) => ['conto_id' => $c['conto_id'], 'dal' => $c['dal'], 'al' => $c['al'],
                        'spese' => $c['spese'], 'importo' => (int) ($c['persone'][$aid] ?? 0), 'unita' => $c['unita']], $risultato['gestioni'][$g]['competenze_scritte'][$iid] ?? []));
                    $b['totale'] = $b['quote'] + $b['coppie'] + $b['regolato'] + $b['gia_versato_persona'];
                    $sue = $personali[$g][$aid][$iid] ?? [];
                    $riga['a'] = (int) array_sum($riga['voci']);
                    $riga['gia_versato_unita'] = $tolto[$aid][$iid] ?? 0;
                    $riga['gia_versato_non_usato'] = ['questo_esercizio' => $nonUsato[$g][$aid][$iid]['questo_esercizio'] ?? 0,
                        'tutti_gli_esercizi' => $nonUsato[$g][$aid][$iid]['tutti_gli_esercizi'] ?? 0];
                    $riga['personali'] = [
                        'non_chieste' => (int) array_sum(array_column($sue, 'importo')),
                        'di_cui_esercizi_prima' => (int) array_sum(array_column(array_filter($sue, fn ($r) => $r['esercizio_prima']), 'importo')),
                        'righe' => $sue,
                        // La parte personale delle quote dei piani dell'esercizio non ancora emesse: chiesta, ma non ancora sul giornale della persona.
                        'chieste_non_emesse' => (int) ($q['personale_non_emessa'] ?? 0),
                    ];
                    $riga['posizione'] = $posizioni[$aid][$g][$iid] ?? 0;
                    $riga['fuori_dalle_rate'] = ($liberi[$g][$aid][$iid] ?? 0) + ($q['riporto_non_emesso'] ?? 0);
                    // Una gestione che non si calcola non propone niente: senza la spesa divisa, A − B direbbe meno tutte le quote.
                    $nonCalcolabile = $risultato['gestioni'][$g]['non_calcolabile'] ?? null;
                    $riga['non_disponibile'] = $nonDisponibile[$g][$aid][$iid] ?? $ripartoNon[$g][$iid]
                        ?? ($nonCalcolabile === null ? null : 'La gestione non si calcola: '.$nonCalcolabile);
                    // Come il passaggio dell'unità entra nelle quote: la coppia, la cifra regolata fra le parti, la parte di un passaggio non scritto.
                    $riga['passaggio'] = $comeDi[$g][$aid][$iid] ?? ($b['coppie'] !== 0 ? 'coppia' : null);
                    $aperto = $apertoPrima[$aid][$iid] ?? ['posizione' => 0, 'personali' => 0];
                    $riga['avviso_anno_prima'] = isset($conOrigine[$g][$aid][$iid]) && ($aperto['posizione'] !== 0 || $aperto['personali'] !== 0)
                        ? $this->avvisoAnnoPrima($conOrigine[$g][$aid][$iid], $aperto, $nomiEsercizi)
                        : null;
                    if ($riga['non_disponibile'] === null) {
                        $riga['b'] = $b;
                        $riga['conguaglio'] = $riga['a'] + $riga['personali']['non_chieste'] - $b['totale'];
                        $riga['saldo'] = $riga['posizione'] + $riga['fuori_dalle_rate'] + $riga['conguaglio'];
                        foreach (['a' => $riga['a'], 'personali' => $riga['personali']['non_chieste'], 'b' => $b['totale'], 'conguaglio' => $riga['conguaglio'], 'posizione' => $riga['posizione'],
                            'fuori_dalle_rate' => $riga['fuori_dalle_rate'], 'saldo' => $riga['saldo']] as $k => $v) {
                            $totali[$k] += $v;
                        }
                    } else {
                        // Senza la cifra regolata (o la parte personale) la riga non ha un conguaglio né un saldo: due cifre false sono peggio di nessuna.
                        $riga['b'] = ['totale' => null] + $b;
                        $riga['conguaglio'] = null;
                        $riga['saldo'] = null;
                        $totali['non_disponibili']++;
                        // La spesa dell'esercizio di una riga non disponibile si conosce: la si dice accanto ai totali, che non la contano.
                        $totali['a_non_disponibili'] += $riga['a'];
                    }
                    $risultato['persone'][$g][$aid][$iid] = $riga;
                }
            }

            unset($risultato['gestioni'][$g]['competenze_scritte']);
            $risultato['gestioni'][$g] = ($risultato['gestioni'][$g] ?? []) + [
                'passaggi' => $passaggi[$g] ?? [],
                'personali_aperte' => $aperte[$g] ?? [],
                'personali_senza_titolare' => $senzaTitolare[$g] ?? [],
                'gia_versato_non_tolto' => $nonTolto,
                'gia_versato_non_usato_senza_titolare' => $nonUsatoSenzaTitolare[$g] ?? [],
                'gia_versato_da_decidere' => $daDecidere[$g] ?? [],
                'totali' => $totali,
            ];
        }
        $risultato['da_altre_gestioni'] = $daAltre;
        $risultato['alla'] = $alla;
        $risultato['personali_per_unita'] = $personaliPerUnita;
        $risultato['passaggi_non_registrati'] = $nonRegistrati;

        return $risultato;
    }

    /**
     * Le quote contro il riparto registrato, per gestione e unità (la regola del deliberato ricostruito, decisa per unità): per i piani delle quote che
     * hanno le righe del riparto, la spesa delle quote di un'unità (`RataQuote::componenti()['spesa']`, il pregresso fuori) è la somma delle sue righe
     * con un soggetto. Dove non torna il piano è cambiato dopo il riparto (una tabella, una quota), e il conguaglio di quell'unità non si calcola.
     * Quando una gestione ha anche piani senza le righe, le quote dei soli piani con le righe si leggono a parte.
     *
     * @param array<int, array<int, array<int, array<string,int>>>> $quote la lettura delle quote dei piani (persona, gestione, unità)
     * @param array<int, list<int>> $pianiPer gestione => piani delle quote
     * @return array<int, array{controllate:int, non_tornano: array<int, array{quote:int, riparto:int, piano_rate_id:int}>, tabelle_cambiate: array<int, list<array<string,mixed>>>, piano_rate_id:int}>
     */
    private function quoteControRiparto(int $condominioId, array $quote, array $pianiPer, string $alla): array
    {
        $tutti = array_merge(...array_values($pianiPer ?: [[]]));
        if ($tutti === []) {
            return [];
        }
        $conRegistro = array_flip(DB::table('righe_riparto')->whereIn('piano_rate_id', $tutti)->distinct()->pluck('piano_rate_id')->map(fn ($id) => (int) $id)->all());
        $esito = [];
        foreach ($pianiPer as $g => $ids) {
            $registrati = array_values(array_filter($ids, fn (int $id) => isset($conRegistro[$id])));
            if ($registrati === []) {
                continue;
            }
            $riparto = [];
            $pianoDi = [];
            foreach (DB::table('righe_riparto')->whereIn('piano_rate_id', $registrati)->whereNotNull('anagrafica_id')->groupBy('immobile_id')
                ->get(['immobile_id', DB::raw('SUM(importo) as importo'), DB::raw('MIN(piano_rate_id) as piano')]) as $r) {
                $riparto[(int) $r->immobile_id] = (int) $r->importo;
                $pianoDi[(int) $r->immobile_id] = (int) $r->piano;
            }
            $dellePersone = count($registrati) === count($ids) ? $quote : app(LetturaDebiti::class)->quoteDeiPianiPerUnita($condominioId, $registrati, $alla);
            $spesa = [];
            foreach ($dellePersone as $perGestione) {
                foreach ($perGestione[$g] ?? [] as $iid => $q) {
                    $spesa[(int) $iid] = ($spesa[(int) $iid] ?? 0) + (int) $q['spesa'];
                }
            }
            $non = [];
            $unita = array_unique([...array_keys($riparto), ...array_keys($spesa)]);
            foreach ($unita as $iid) {
                if (($riparto[$iid] ?? 0) !== ($spesa[$iid] ?? 0)) {
                    $non[$iid] = ['quote' => $spesa[$iid] ?? 0, 'riparto' => $riparto[$iid] ?? 0, 'piano_rate_id' => $pianoDi[$iid] ?? $registrati[0]];
                }
            }
            $esito[$g] = ['controllate' => count($unita), 'non_tornano' => $non, 'tabelle_cambiate' => $this->tabelleCambiate($registrati), 'piano_rate_id' => $registrati[0]];
        }

        return $esito;
    }

    /**
     * Le tabelle cambiate dopo il piano, per unità: le righe del riparto hanno il millesimo dell'unità, la somma dei millesimi e il coefficiente della voce
     * che il piano ha usato; si confrontano con quelli di oggi. Le quote e il riparto restano quelli del piano, le spese dell'esercizio si dividono con la
     * tabella di oggi: il conguaglio comprende la differenza, e il controllo la fa leggere (una segnalazione, non una riga non disponibile).
     *
     * @param  list<int>  $piani  i piani con le righe del riparto
     * @return array<int, list<array{tabella:string, prima:float, somma_prima:float, oggi:?float, somma_oggi:float, coefficiente_prima:?float, coefficiente_oggi:?float}>>
     */
    private function tabelleCambiate(array $piani): array
    {
        $usati = DB::table('righe_riparto')->whereIn('piano_rate_id', $piani)->whereNotNull('tabella_id')->whereNotNull('immobile_id')
            ->distinct()->get(['tabella_id', 'tabella_nome', 'immobile_id', 'valore_millesimo', 'somma_valori']);
        if ($usati->isEmpty()) {
            return [];
        }
        $tabelle = $usati->pluck('tabella_id')->unique()->map(fn ($id) => (int) $id)->all();
        $oggi = [];
        $somme = [];
        foreach (DB::table('quote_tabella')->whereIn('tabella_id', $tabelle)->get(['tabella_id', 'immobile_id', 'valore']) as $q) {
            $oggi[(int) $q->tabella_id][(int) $q->immobile_id] = $q->valore === null ? null : (float) $q->valore;
            $somme[(int) $q->tabella_id] = ($somme[(int) $q->tabella_id] ?? 0.0) + (float) $q->valore;
        }
        // Il coefficiente di ogni voce sulla tabella, nel piano e oggi: un coefficiente cambiato cambia la parte di tutte le unità della voce.
        $coeffOggi = DB::table('conto_tabella_millesimale')->whereIn('tabella_id', $tabelle)->get(['conto_id', 'tabella_id', 'coefficiente'])
            ->mapWithKeys(fn ($r) => [$r->conto_id.'|'.$r->tabella_id => (float) $r->coefficiente])->all();
        $coeffCambiati = [];
        foreach (DB::table('righe_riparto')->whereIn('piano_rate_id', $piani)->whereNotNull('tabella_id')->whereNotNull('conto_id')->distinct()
            ->get(['conto_id', 'tabella_id', 'coefficiente']) as $r) {
            $adesso = $coeffOggi[$r->conto_id.'|'.$r->tabella_id] ?? null;
            if ($r->coefficiente !== null && ($adesso === null || abs($adesso - (float) $r->coefficiente) > 0.0001)) {
                $coeffCambiati[(int) $r->tabella_id] = ['prima' => (float) $r->coefficiente, 'oggi' => $adesso];
            }
        }
        $cambiate = [];
        foreach ($usati as $u) {
            $t = (int) $u->tabella_id;
            $iid = (int) $u->immobile_id;
            $adesso = $oggi[$t][$iid] ?? null;
            $diverso = $u->valore_millesimo === null || $adesso === null || abs($adesso - (float) $u->valore_millesimo) > 0.0001
                || abs(($somme[$t] ?? 0.0) - (float) $u->somma_valori) > 0.0001 || isset($coeffCambiati[$t]);
            if ($diverso && ! isset($cambiate[$iid][$t])) {
                $cambiate[$iid][$t] = ['tabella' => (string) $u->tabella_nome, 'prima' => (float) $u->valore_millesimo, 'somma_prima' => (float) $u->somma_valori,
                    'oggi' => $adesso, 'somma_oggi' => (float) ($somme[$t] ?? 0.0),
                    'coefficiente_prima' => $coeffCambiati[$t]['prima'] ?? null, 'coefficiente_oggi' => $coeffCambiati[$t]['oggi'] ?? null];
            }
        }

        return array_map('array_values', $cambiate);
    }

    private static function euro(int $c): string
    {
        return '€ '.number_format($c / 100, 2, ',', '.');
    }

    /**
     * L'avviso della riga che ha un saldo iniziale scritto a mano con l'origine in un altro esercizio, quando la persona ha anche rate o spese
     * personali ancora aperte di esercizi prima: la frase per l'amministratore, con le cifre, e le cifre. Nessuna cifra si toglie.
     *
     * @param array{importo:int, applicati:int, esercizi: array<int,int>} $origine
     * @param array<int,string> $nomiEsercizi
     */
    private function avvisoAnnoPrima(array $origine, array $aperto, array $nomiEsercizi): array
    {
        $nomi = array_map(fn (int $id) => '«' . ($nomiEsercizi[$id] ?? 'esercizio ' . $id) . '»', array_values($origine['esercizi']));
        $da = count($nomi) === 1 ? $nomi[0] : implode(', ', array_slice($nomi, 0, -1)) . ' e ' . end($nomi);
        $euro = fn (int $c) => '€ ' . number_format($c / 100, 2, ',', '.');
        $nelleRate = $origine['applicati'] === 0 ? ''
            : ($origine['applicati'] === $origine['importo'] ? ' ed è già nelle rate di questo esercizio' : ', ' . $euro($origine['applicati']) . ' già nelle rate di questo esercizio,');
        // Le due cifre con il loro segno, quella che c'è: con un credito o con due cifre che si annullano la frase le dice tutte e due.
        $cosa = array_values(array_filter([
            $aperto['posizione'] !== 0 ? 'la posizione ancora aperta di altre gestioni (' . $euro($aperto['posizione']) . ')' : null,
            $aperto['personali'] !== 0 ? 'le spese personali di esercizi prima non ancora chieste (' . $euro($aperto['personali']) . ')' : null,
        ]));

        return [
            'perche' => 'Ci sono rate o spese personali ancora aperte di esercizi prima e anche un saldo iniziale scritto a mano con l\'origine in un esercizio prima: se il saldo le comprende, la stessa cifra conta due volte.',
            'frase' => 'Il saldo iniziale scritto a mano di ' . $euro($origine['importo']) . ' viene da ' . $da . $nelleRate . ': se comprende già ' . implode(' o ', $cosa)
                . ', la stessa cifra conta due volte. Controllalo nei saldi iniziali dell\'esercizio, e togli la parte che è già nell\'altra cifra.',
            'altre_gestioni' => $aperto['posizione'],
            'personali_esercizi_prima' => $aperto['personali'],
            'saldi_con_origine' => $origine['importo'],
            'applicati' => $origine['applicati'],
            'esercizi_origine' => array_values($origine['esercizi']),
        ];
    }

    /**
     * Il già versato registrato per le voci delle gestioni dell'esercizio (`contributi_versati` su una voce, dell'esercizio o di tutti gli
     * esercizi) che i piani dell'esercizio non hanno usato, l'eccedenza compresa: per gestione, unità e persona (`0` per quello dell'unità). Non entra
     * nel conguaglio e non prende un esercizio da solo: la riga lo dice, la regola è della chiusura.
     *
     * Per ambito: `questo_esercizio` (registrato per l'esercizio) e `tutti_gli_esercizi` (registrato senza esercizio: un piano di un altro esercizio
     * può ancora usarlo); quello che i piani hanno usato si toglie prima dal primo.
     *
     * @param array<int, array<int, array<int,int>>> $usato le righe `netting` dei piani dell'esercizio, positive
     * @return array<int, array<int, array<int, array{questo_esercizio:int, tutti_gli_esercizi:int}>>>
     */
    private function giaVersatoNonUsato(Esercizio $esercizio, array $usato): array
    {
        $voci = DB::table('conti')->join('piani_conti as pc', 'pc.id', '=', 'conti.piano_conto_id')
            ->join('esercizio_gestione as eg', 'eg.gestione_id', '=', 'pc.gestione_id')->where('eg.esercizio_id', $esercizio->id)
            ->pluck('pc.gestione_id', 'conti.id')->all();
        if ($voci === []) {
            return [];
        }
        $registrato = [];
        foreach (DB::table('contributi_versati')->where('condominio_id', $esercizio->condominio_id)->where('target_type', \App\Models\Gestionale\Conto::class)
            ->whereIn('target_id', array_keys($voci))->where(fn ($q) => $q->where('esercizio_id', $esercizio->id)->orWhereNull('esercizio_id'))
            ->get(['target_id', 'immobile_id', 'anagrafica_id', 'importo_cents', 'esercizio_id']) as $r) {
            $g = (int) $voci[(int) $r->target_id];
            $chi = (int) ($r->anagrafica_id ?? 0);
            $ambito = $r->esercizio_id === null ? 'tutti_gli_esercizi' : 'questo_esercizio';
            $registrato[$g][(int) $r->immobile_id][$chi][$ambito] = ($registrato[$g][(int) $r->immobile_id][$chi][$ambito] ?? 0) + (int) $r->importo_cents;
        }
        // Quello per tutti gli esercizi lo usano anche i piani degli altri esercizi della gestione: di ogni esercizio, quello che i suoi piani hanno usato
        // oltre il già versato registrato per lui.
        $usatoAltrove = $this->usatoDaTuttiNegliAltriEsercizi($esercizio, array_keys($registrato), array_keys($voci));
        // Quello che i piani dell'esercizio hanno usato si toglie prima dal già versato registrato per l'esercizio, poi da quello per tutti gli esercizi.
        $nonUsato = [];
        foreach ($registrato as $g => $perUnita) {
            foreach ($perUnita as $iid => $perChi) {
                foreach ($perChi as $chi => $ambiti) {
                    $usatoQui = $usato[$g][$iid][$chi] ?? 0;
                    $questo = max(0, ($ambiti['questo_esercizio'] ?? 0) - $usatoQui);
                    $tutti = max(0, ($ambiti['tutti_gli_esercizi'] ?? 0) - max(0, $usatoQui - ($ambiti['questo_esercizio'] ?? 0)) - ($usatoAltrove[$g][$iid][$chi] ?? 0));
                    if ($questo + $tutti > 0) {
                        $nonUsato[$g][$iid][$chi] = ['questo_esercizio' => $questo, 'tutti_gli_esercizi' => $tutti];
                    }
                }
            }
        }

        return $nonUsato;
    }

    /**
     * Il già versato per tutti gli esercizi usato dai piani degli **altri** esercizi delle gestioni, per gestione, unità e persona (`0` per l'unità):
     * di ogni esercizio, quello che i suoi piani hanno tolto (le righe `netting`) oltre il già versato registrato per quell'esercizio. Un piano senza
     * un esercizio conta tutto su quello per tutti gli esercizi.
     *
     * @param  list<int>  $gestioni
     * @param  list<int>  $voci  le voci delle gestioni dell'esercizio
     * @return array<int, array<int, array<int,int>>>
     */
    private function usatoDaTuttiNegliAltriEsercizi(Esercizio $esercizio, array $gestioni, array $voci): array
    {
        $competenza = app(CompetenzaDelPiano::class);
        $esercizioDi = [];
        foreach (PianoRate::whereIn('gestione_id', $gestioni)->get() as $p) {
            $e = (int) ($competenza->esercizioDelPiano($p)?->id ?? 0);
            if ($e !== (int) $esercizio->id) {
                $esercizioDi[(int) $p->id] = $e;
            }
        }
        if ($esercizioDi === []) {
            return [];
        }
        $usatoPer = [];
        foreach (DB::table('righe_riparto as rr')->join('piani_rate as p', 'p.id', '=', 'rr.piano_rate_id')->whereIn('rr.piano_rate_id', array_keys($esercizioDi))
            ->where('rr.tipo', RigaRiparto::TIPO_NETTING)->get(['rr.piano_rate_id', 'p.gestione_id', 'rr.anagrafica_id', 'rr.immobile_id', 'rr.importo', 'rr.riga_descrizione']) as $r) {
            $chi = $r->riga_descrizione === CalcoloQuoteService::NETTING_DELLA_PERSONA ? (int) $r->anagrafica_id : 0;
            $e = $esercizioDi[(int) $r->piano_rate_id];
            $usatoPer[(int) $r->gestione_id][(int) $r->immobile_id][$chi][$e] = ($usatoPer[(int) $r->gestione_id][(int) $r->immobile_id][$chi][$e] ?? 0) - (int) $r->importo;
        }
        $registratoPer = [];
        $eserciziAltri = array_values(array_unique(array_filter($esercizioDi)));
        if ($eserciziAltri !== []) {
            foreach (DB::table('contributi_versati as cv')->join('conti', 'conti.id', '=', 'cv.target_id')->join('piani_conti as pc', 'pc.id', '=', 'conti.piano_conto_id')
                ->where('cv.condominio_id', $esercizio->condominio_id)->where('cv.target_type', \App\Models\Gestionale\Conto::class)->whereIn('cv.esercizio_id', $eserciziAltri)
                ->whereIn('pc.gestione_id', $gestioni)->get(['pc.gestione_id', 'cv.immobile_id', 'cv.anagrafica_id', 'cv.importo_cents', 'cv.esercizio_id']) as $r) {
                $chi = (int) ($r->anagrafica_id ?? 0);
                $registratoPer[(int) $r->gestione_id][(int) $r->immobile_id][$chi][(int) $r->esercizio_id] = ($registratoPer[(int) $r->gestione_id][(int) $r->immobile_id][$chi][(int) $r->esercizio_id] ?? 0) + (int) $r->importo_cents;
            }
        }
        $usatoAltrove = [];
        foreach ($usatoPer as $g => $perUnita) {
            foreach ($perUnita as $iid => $perChi) {
                foreach ($perChi as $chi => $perEsercizio) {
                    foreach ($perEsercizio as $e => $importo) {
                        $usatoAltrove[$g][$iid][$chi] = ($usatoAltrove[$g][$iid][$chi] ?? 0) + max(0, $importo - ($registratoPer[$g][$iid][$chi][$e] ?? 0));
                    }
                }
            }
        }

        return $usatoAltrove;
    }


    /**
     * I passaggi dell'esercizio (decorrenza dentro l'esercizio, non annullati, l'unità principale): con la coppia scritta la cifra è nei saldi;
     * con la rinuncia, il conguaglio annullato o «non scritto» la cifra regolata fra le parti si legge dal registro (`regolato_fuori`), per
     * gestione e persona, come una coppia: positiva per chi entra, il suo opposto per chi esce. Il registro dice la cifra per unità solo nel cambio
     * d'inquilino (`per_unita`); altrimenti la cifra della gestione, che somma l'unità e le pertinenze del passaggio, va tutta sull'unità del
     * passaggio: per persona torna, per unità no. Senza la cifra nel registro (i passaggi di
     * prima che il registro la tenesse, la successione con più eredi senza le cifre di ciascuno) il conguaglio delle persone del passaggio
     * su quell'unità non è disponibile.
     *
     * @return array{0: array<int, array<int, array<int, int>>>, 1: array<int, array<int, array{come:string, motivo:?string}>>, 2: array<int, array<int, array<int, string>>>, 3: array<int, array<int, array<int, string>>>}
     */
    private function passaggi(Esercizio $esercizio, array $gestioniIds, array $dalPer = []): array
    {
        $comeDi = [];
        $regolato = [];
        $passaggi = [];
        $nonDisponibile = [];
        $dal = substr((string) $esercizio->data_inizio, 0, 10);
        $al = substr((string) $esercizio->data_fine, 0, 10);
        // Un'opera finita in questo esercizio conta anche i passaggi dei suoi esercizi prima (`$dalPer`, gestione => primo giorno).
        $primo = min([$dal, ...array_values($dalPer)]);
        $subentri = Subentro::where('condominio_id', $esercizio->condominio_id)->whereNull('subentro_padre_id')
            ->whereDate('decorrenza', '>=', $primo)->whereDate('decorrenza', '<=', $al)->orderBy('id')->get();
        foreach ($subentri as $sub) {
            $fuori = $sub->conguaglioRinunciato() || $sub->conguaglioAnnullato();
            $come = ! $fuori ? 'coppia' : ($sub->conguaglioNonScritto() ? 'non_scritto' : 'regolato');
            $uscente = $sub->anagrafica_uscente_id === null ? null : (int) $sub->anagrafica_uscente_id;
            $voci = $sub->registro['regolato_fuori'] ?? null;
            $decorrenza = substr((string) $sub->decorrenza, 0, 10);
            foreach ($gestioniIds as $g) {
                if ($decorrenza < ($dalPer[$g] ?? $dal)) {
                    continue;
                }
                if (! $fuori) {
                    $passaggi[$g][(int) $sub->id] = ['come' => $come, 'motivo' => null];

                    continue;
                }
                $motivo = null;
                if (! is_array($voci)) {
                    $motivo = 'Il passaggio è regolato fra le parti, ma la cifra non è nel registro: si legge dalla nota del passaggio, e il conguaglio di queste persone su questa unità si fa a mano.';
                } elseif ($sub->successione() && count($sub->eredi()) > 1 && collect($voci)->contains(fn ($v) => (int) ($v['gestione_id'] ?? 0) === $g && ! array_key_exists('persone', $v))) {
                    $motivo = 'Il passaggio è regolato fra le parti, ma le cifre di ciascun erede non sono nel registro: si leggono dalla nota del passaggio, e il conguaglio di queste persone su questa unità si fa a mano.';
                }
                $passaggi[$g][(int) $sub->id] = ['come' => $motivo === null ? $come : 'non_disponibile', 'motivo' => $motivo];
                if ($motivo !== null) {
                    $chi = array_filter([$uscente, $sub->anagrafica_entrante_id === null ? null : (int) $sub->anagrafica_entrante_id]);
                    foreach ($chi as $aid) {
                        $nonDisponibile[$g][$aid][(int) $sub->immobile_id] = $motivo;
                    }

                    continue;
                }
                foreach ($voci as $v) {
                    if ((int) ($v['gestione_id'] ?? 0) !== $g) {
                        continue;
                    }
                    $gruppi = isset($v['per_unita']) ? $v['per_unita']
                        : [['immobile_id' => (int) $sub->immobile_id, 'persone' => $v['persone'] ?? [['anagrafica_id' => (int) $sub->anagrafica_entrante_id, 'importo' => (int) ($v['importo'] ?? 0)]]]];
                    foreach ($gruppi as $gruppo) {
                        $iid = (int) $gruppo['immobile_id'];
                        $somma = 0;
                        $conChiEsce = false;
                        foreach ($gruppo['persone'] as $p) {
                            $aid = (int) ($p['anagrafica_id'] ?? 0);
                            $conChiEsce = $conChiEsce || $aid === $uscente;
                            $somma += (int) ($p['importo'] ?? 0);
                            $regolato[$g][$aid][$iid] = ($regolato[$g][$aid][$iid] ?? 0) + (int) ($p['importo'] ?? 0);
                            $comeDi[$g][$aid][$iid] = $come;
                        }
                        if (! $conChiEsce && $uscente !== null) {
                            $regolato[$g][$uscente][$iid] = ($regolato[$g][$uscente][$iid] ?? 0) - $somma;
                            $comeDi[$g][$uscente][$iid] = $come;
                        }
                    }
                }
            }
        }

        return [$regolato, $passaggi, $nonDisponibile, $comeDi];
    }

    /**
     * Le spese per singole unità non ancora chieste da un piano, alla data: le righe di fattura con l'unità di ogni gestione del condominio,
     * con la scrittura fino alla data. Quelle di una gestione che l'esercizio non ha (l'anno prima) vanno sotto la chiave `0`, la riga «da altre
     * gestioni e da esercizi prima», e la
     * data del documento prima dell'esercizio le dice «nate in esercizi prima».
     *
     * «Chiesta» vuol dire presa da un piano di questo esercizio o di uno prima (quelli di cui B la toglie, o che l'hanno già chiesta), **per
     * importo**: le righe `ad_personam` del riparto con quella riga di fattura dicono quanto il piano ne ha chiesto (un piano che finanzia una parte
     * della fattura ne chiede una parte di ogni riga), e il resto è ancora da chiedere. Per un piano senza il registro del riparto (di prima della
     * 1.11.0-beta.29) le righe della ricostruzione, o, se non si ricostruisce, tutte le righe con l'unità delle sue fatture per intero, e allora la
     * parte personale delle sue quote non si sa: le unità di un piano dell'esercizio così sono «non disponibili». Un piano di un esercizio dopo non
     * cambia il foglio di oggi.
     *
     * L'importo è quello della riga com'è scritto, con il suo segno (imponibile + IVA, come la riga del giornale): una nota di credito è già in
     * meno. Per una fattura con una parte chiesta su un'unità, la fattura e le sue note collegate su quell'unità sono una cifra sola: le righe, più
     * le note, meno il chiesto (il piano che chiede il netto ha già tolto la nota; una nota arrivata dopo il piano resta a credito).
     *
     * Ognuna a chi era titolare di diritto reale (proprietario, poi nudo proprietario, poi usufruttuario, come l'addebito diretto del motore; su
     * un'unità mista anche il ruolo gemello, come il motore) il giorno della fattura; con la competenza scritta su più giorni, per giorni fra chi era
     * titolare in quei giorni, come una spesa comune con la competenza scritta. Fra più titolari per quota.
     *
     * @param array<int, int> $nelleGestioni le gestioni dell'esercizio (id => posizione)
     * @return array{0: array<int, array<int, array<int, list<array<string,mixed>>>>>, 1: array<int, array<int, int>>, 2: array<int, array<int, string>>, 3: array<int, int>}
     */
    private function personali(Esercizio $esercizio, array $nelleGestioni, string $alla): array
    {
        $dopo = CarbonImmutable::parse($alla, 'UTC')->addDay()->format('Y-m-d');
        $inizio = substr((string) $esercizio->data_inizio, 0, 10);

        // I piani fino a questo esercizio.
        $competenza = app(CompetenzaDelPiano::class);
        $piani = PianoRate::where('condominio_id', $esercizio->condominio_id)->orderBy('id')->get()
            ->filter(function (PianoRate $p) use ($competenza, $inizio) {
                $suo = $competenza->esercizioDelPiano($p);

                return $suo !== null && substr((string) $suo->data_inizio, 0, 10) <= $inizio;
            });
        $pianiIds = $piani->pluck('id')->map(fn ($id) => (int) $id)->all();
        // Quanto ogni piano ha chiesto di ogni riga di fattura: la somma delle sue righe `ad_personam`.
        $chiesto = [];
        $conAdPersonam = [];
        if ($pianiIds !== []) {
            foreach (DB::table('righe_riparto')->whereIn('piano_rate_id', $pianiIds)->where('tipo', RigaRiparto::TIPO_AD_PERSONAM)->whereNotNull('riga_fattura_id')
                ->groupBy('riga_fattura_id', 'piano_rate_id')->select('riga_fattura_id', 'piano_rate_id', DB::raw('SUM(importo) as importo'))->get() as $r) {
                $chiesto[(int) $r->riga_fattura_id] = ($chiesto[(int) $r->riga_fattura_id] ?? 0) + (int) $r->importo;
                $conAdPersonam[(int) $r->piano_rate_id] = true;
            }
        }
        $conRegistro = $pianiIds === [] ? [] : array_flip(DB::table('righe_riparto')->whereIn('piano_rate_id', $pianiIds)->distinct()->pluck('piano_rate_id')->map(fn ($id) => (int) $id)->all());
        $tutta = [];
        $nonRicostruite = [];
        foreach ($piani as $piano) {
            if (isset($conRegistro[(int) $piano->id])) {
                continue;
            }
            $delPiano = DB::table('piano_rate_fatture as prf')->join('righe_fattura as rf', 'rf.fattura_passiva_id', '=', 'prf.fattura_passiva_id')
                ->where('prf.piano_rate_id', $piano->id)->whereNotNull('rf.immobile_id')->get(['rf.id', 'rf.immobile_id']);
            if ($delPiano->isEmpty()) {
                continue;
            }
            $ricostruite = RataQuote::righeRicostruiteAdPersonam($piano);
            if ($ricostruite !== null) {
                foreach ($ricostruite as $r) {
                    if ($r['riga_fattura_id'] !== null) {
                        $chiesto[(int) $r['riga_fattura_id']] = ($chiesto[(int) $r['riga_fattura_id']] ?? 0) + (int) $r['importo'];
                    }
                }
                $conAdPersonam[(int) $piano->id] = true;

                continue;
            }
            foreach ($delPiano as $r) {
                $tutta[(int) $r->id] = true;
                if (isset($nelleGestioni[(int) $piano->gestione_id]) && $competenza->esercizioDelPiano($piano)?->id === $esercizio->id) {
                    $nonRicostruite[(int) $piano->gestione_id][(int) $r->immobile_id] = 'Il piano «' . $piano->nome . '» non ha il dettaglio del riparto e non si ricostruisce: la parte personale delle sue quote non si sa.';
                }
            }
        }

        $righe = DB::table('righe_fattura as rf')
            ->join('fatture_passive as fp', 'fp.id', '=', 'rf.fattura_passiva_id')
            ->join('fattura_scrittura as fs', 'fs.fattura_passiva_id', '=', 'fp.id')
            ->join('scritture_contabili as sc', 'sc.id', '=', 'fs.scrittura_contabile_id')
            ->where('fp.condominio_id', $esercizio->condominio_id)
            ->whereNotNull('rf.immobile_id')
            ->where('fs.tipo', 'competenza')
            ->whereNull('sc.deleted_at')
            ->where('sc.data_competenza', '<', $dopo)
            ->groupBy('rf.id', 'rf.fattura_passiva_id', 'rf.immobile_id', 'rf.importo_imponibile', 'rf.importo_iva', 'fp.fattura_rettificata_id', 'fp.data_documento', 'fp.competenza_dal', 'fp.competenza_al', 'sc.gestione_id')
            ->orderBy('rf.id')
            ->get(['rf.id', 'rf.fattura_passiva_id', 'rf.immobile_id', 'rf.importo_imponibile', 'rf.importo_iva', 'fp.fattura_rettificata_id', 'fp.data_documento', 'fp.competenza_dal', 'fp.competenza_al', 'sc.gestione_id']);

        // Le fatture con una parte chiesta su un'unità: con le loro note collegate su quell'unità fanno una cifra sola (righe + note − chiesto).
        $chiestoSu = [];
        foreach ($righe as $r) {
            $id = (int) $r->id;
            $lordo = (int) $r->importo_imponibile + (int) $r->importo_iva;
            $quanto = isset($tutta[$id]) ? $lordo : ($chiesto[$id] ?? 0);
            if ($quanto !== 0 || isset($tutta[$id])) {
                $k = (int) ($r->fattura_rettificata_id ?? $r->fattura_passiva_id) . '|' . (int) $r->immobile_id;
                $chiestoSu[$k] = ($chiestoSu[$k] ?? 0) + $quanto;
            }
        }
        $voci = [];
        $gruppi = [];
        foreach ($righe as $r) {
            $lordo = (int) $r->importo_imponibile + (int) $r->importo_iva;
            $k = (int) ($r->fattura_rettificata_id ?? $r->fattura_passiva_id) . '|' . (int) $r->immobile_id;
            if (! isset($chiestoSu[$k])) {
                // Niente chiesto di questa fattura su questa unità: ogni riga per sé, con la sua data.
                $voci[] = ['r' => $r, 'importo' => $lordo];

                continue;
            }
            if (! isset($gruppi[$k])) {
                // La cifra della fattura sta al posto della sua prima riga.
                $gruppi[$k] = ['r' => $r, 'importo' => -$chiestoSu[$k]];
                $voci[] = ['gruppo' => $k];
            }
            $gruppi[$k]['importo'] += $lordo;
            // La riga della fattura (non della nota) dice la data, la gestione e il legame.
            if ($r->fattura_rettificata_id === null && $gruppi[$k]['r']->fattura_rettificata_id !== null) {
                $gruppi[$k]['r'] = $r;
            }
        }
        $voci = array_map(fn (array $v) => isset($v['gruppo']) ? $gruppi[$v['gruppo']] : $v, $voci);

        $titolari = app(RisolutoreTitolari::class);
        $destinatariDi = [];
        $esito = [];
        $senzaTitolare = [];
        foreach ($voci as ['r' => $r, 'importo' => $importo]) {
            if ($importo === 0) {
                continue;
            }
            $giorno = substr((string) ($r->competenza_dal ?? $r->data_documento), 0, 10);
            $fino = $r->competenza_dal !== null && $r->competenza_al !== null ? substr((string) $r->competenza_al, 0, 10) : $giorno;
            $g = isset($nelleGestioni[(int) $r->gestione_id]) ? (int) $r->gestione_id : 0;
            $iid = (int) $r->immobile_id;
            // Un giorno solo (o titolari che non cambiano nei giorni scritti): chi era titolare, per quota. Più tratti: per giorni fra chi era titolare in ognuno.
            $tratti = $this->trattiDellUnita($iid, $giorno, max($giorno, $fino));
            if (count($tratti) === 1) {
                $pesi = $destinatariDi[$iid . '|' . $giorno] ??= $this->titolariDiDirittoReale($titolari, $iid, $giorno);
                $tratti = [];
            } else {
                $pesi = [];
            }
            $scoperti = 0;
            foreach ($tratti as [$dal, $giorni]) {
                $chi = $destinatariDi[$iid . '|' . $dal] ??= $this->titolariDiDirittoReale($titolari, $iid, $dal);
                if ($chi === []) {
                    $scoperti += $giorni;

                    continue;
                }
                $somma = array_sum($chi);
                foreach ($chi as $aid => $quota) {
                    $pesi[$aid] = ($pesi[$aid] ?? 0.0) + $giorni * $quota / ($somma > 0 ? $somma : 1.0);
                }
            }
            if ($scoperti > 0) {
                $pesi[0] = (float) $scoperti;
            }
            if ($pesi === []) {
                $senzaTitolare[$g][$iid] = ($senzaTitolare[$g][$iid] ?? 0) + $importo;

                continue;
            }
            foreach (count($pesi) === 1 ? [array_key_first($pesi) => $importo] : MoneyHelper::ripartisciPerQuote($importo, $pesi) as $aid => $parte) {
                if ((int) $aid === 0) {
                    $senzaTitolare[$g][$iid] = ($senzaTitolare[$g][$iid] ?? 0) + $parte;

                    continue;
                }
                $esito[$g][$aid][$iid][] = ['riga_fattura_id' => (int) $r->id, 'fattura_passiva_id' => (int) $r->fattura_passiva_id, 'giorno' => $giorno,
                    'importo' => $parte, 'esercizio_prima' => substr((string) $r->data_documento, 0, 10) < $inizio];
            }
        }

        // La parte personale delle quote dei piani fino a questo esercizio ancora da emettere, per unità: chiesta, ma ancora fra le anticipazioni aperte.
        $nonEmesse = [];
        if ($conAdPersonam !== []) {
            foreach (app(LetturaDebiti::class)->quoteDeiPianiPerUnita((int) $esercizio->condominio_id, array_keys($conAdPersonam), $alla) as $perGestione) {
                foreach ($perGestione as $perUnita) {
                    foreach ($perUnita as $iid => $q) {
                        $nonEmesse[(int) $iid] = ($nonEmesse[(int) $iid] ?? 0) + (int) ($q['personale_non_emessa'] ?? 0);
                    }
                }
            }
        }

        return [$esito, $senzaTitolare, $nonRicostruite, $nonEmesse];
    }

    /**
     * I tratti di un'unità fra due giorni in cui i suoi titolari non cambiano: `[primo giorno, giorni]`, dai giorni di inizio e dai giorni dopo la fine
     * delle sue righe. Un giorno solo: un tratto.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function trattiDellUnita(int $immobileId, string $dal, string $al): array
    {
        if ($dal === $al) {
            return [[$dal, 1]];
        }
        $tagli = [$dal => true];
        foreach (DB::table('anagrafica_immobile')->where('immobile_id', $immobileId)->get(['data_inizio', 'data_fine']) as $r) {
            foreach ([$r->data_inizio === null ? null : substr((string) $r->data_inizio, 0, 10),
                $r->data_fine === null ? null : CarbonImmutable::parse(substr((string) $r->data_fine, 0, 10))->addDay()->format('Y-m-d')] as $giorno) {
                if ($giorno !== null && $giorno > $dal && $giorno <= $al) {
                    $tagli[$giorno] = true;
                }
            }
        }
        $inizi = array_keys($tagli);
        sort($inizi);
        $tratti = [];
        foreach ($inizi as $i => $giorno) {
            $fine = isset($inizi[$i + 1]) ? CarbonImmutable::parse($inizi[$i + 1])->subDay()->format('Y-m-d') : $al;
            $tratti[] = [$giorno, (int) CarbonImmutable::parse($giorno)->diffInDays(CarbonImmutable::parse($fine)) + 1];
        }

        return $tratti;
    }

    /**
     * I titolari di diritto reale nell'ordine della catena del saldo pregresso della gestione (`catenaSaldoSolidale`), e dopo gli altri: in una
     * gestione ordinaria prima l'usufruttuario (art. 1004 c.c.), in una straordinaria prima il nudo proprietario (art. 1005 c.c.).
     *
     * @return list<RuoloAnagraficaImmobile>
     */
    private static function ordineDeiTitolari(?string $tipoGestione): array
    {
        $catena = RuoloAnagraficaImmobile::catenaSaldoSolidale($tipoGestione);

        return [...$catena, ...array_values(array_filter(RuoloAnagraficaImmobile::titolariDiDirittoReale(), fn ($r) => ! in_array($r, $catena, true)))];
    }

    /**
     * I titolari di diritto reale di un'unità in un giorno, con la loro quota (`anagrafica_id => quota`), nella preferenza dell'addebito diretto. Su
     * un'unità mista anche il ruolo gemello dell'altra parte, per la sua quota, come l'addebito diretto del motore: non il
     * primo ruolo trovato, che darebbe tutto a chi ha la piena proprietà di metà.
     *
     * @return array<int, float>
     */
    private function titolariDiDirittoReale(RisolutoreTitolari $titolari, int $immobileId, string $giorno, ?array $ordine = null): array
    {
        $giornoSolo = PeriodoCompetenza::puntuale($giorno);
        $attivi = $titolari->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $immobileId), $giornoSolo)
            ->orderBy('data_inizio')->orderBy('id')->get();
        foreach ($ordine ?? RuoloAnagraficaImmobile::titolariDiDirittoReale() as $ruolo) {
            $destinatari = $attivi->where('tipologia', $ruolo->value)->values();
            if ($destinatari->isNotEmpty()) {
                $tutte = $titolari->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $immobileId))->get();
                $gemelle = (new \App\Services\Riparto\UnitaMista($titolari))->righeGemelle($tutte, $destinatari, $ruolo->value, $giornoSolo);
                if ($gemelle->isNotEmpty()) {
                    $idGemelle = $gemelle->map(fn ($r) => (int) $r->id)->flip()->all();
                    $destinatari = $destinatari->concat($attivi->filter(fn ($r) => isset($idGemelle[(int) $r->id])))->values();
                }
                $pesi = [];
                foreach ($destinatari as $d) {
                    $pesi[(int) $d->anagrafica_id] = ($pesi[(int) $d->anagrafica_id] ?? 0) + (float) $d->quota;
                }

                return $pesi;
            }
        }

        return [];
    }

    /**
     * Le voci con la spesa dell'esercizio che due piani dell'esercizio dividono con periodi diversi (i tratti del capitolo: su un piano sì e sull'altro no,
     * o diversi), quando almeno un'unità delle tabelle della voce cambia titolare nell'esercizio: `voce => nomi dei piani`. Senza un cambio di titolare i
     * periodi non cambiano la parte di nessuno, e la voce si divide come sempre (con i periodi del primo piano).
     *
     * @param  list<int>  $voci
     * @return array<int, list<string>>
     */
    private function vociConPeriodiDiversi($pianiDellEsercizio, array $voci, Esercizio $esercizio): array
    {
        $pianiIds = $pianiDellEsercizio->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($pianiIds) < 2 || $voci === []) {
            return [];
        }
        $righe = DB::table('piano_rate_capitoli as prc')->join('piani_rate as p', 'p.id', '=', 'prc.piano_rate_id')->whereIn('prc.piano_rate_id', $pianiIds)
            ->orderBy('prc.id')->get(['prc.id', 'prc.conto_id', 'prc.piano_rate_id', 'p.nome']);
        $tratti = DB::table('competenze_capitolo')->whereIn('piano_rate_capitolo_id', $righe->pluck('id')->all())->orderBy('dal')->get(['piano_rate_capitolo_id', 'dal', 'al'])
            ->groupBy('piano_rate_capitolo_id')->map(fn ($t) => $t->map(fn ($x) => substr((string) $x->dal, 0, 10).'/'.substr((string) $x->al, 0, 10))->implode(','));
        $diversi = [];
        foreach ($righe->groupBy('conto_id') as $cid => $perConto) {
            if ($perConto->pluck('piano_rate_id')->unique()->count() < 2) {
                continue;
            }
            if ($perConto->map(fn ($r) => (string) ($tratti[$r->id] ?? ''))->unique()->count() > 1) {
                $diversi[(int) $cid] = $perConto->pluck('nome')->map(fn ($n) => (string) $n)->unique()->values()->all();
            }
        }
        if ($diversi === []) {
            return [];
        }
        // Le voci della spesa che stanno sotto un capitolo con i periodi diversi (la voce stessa, o un suo capitolo).
        $padri = [];
        for ($cerca = $voci, $passi = 0; $cerca !== [] && $passi < 20; $passi++) {
            $nuovi = DB::table('conti')->whereIn('id', $cerca)->whereNotNull('parent_id')->pluck('parent_id', 'id')->map(fn ($id) => (int) $id)->all();
            $padri += $nuovi;
            $cerca = array_values(array_diff(array_unique(array_values($nuovi)), array_keys($padri)));
        }
        $cambiano = $this->unitaCheCambiano($esercizio);
        $esito = [];
        foreach ($voci as $vid) {
            $capitolo = null;
            for ($c = (int) $vid, $passi = 0; $c !== 0 && $passi < 20; $c = (int) ($padri[$c] ?? 0), $passi++) {
                if (isset($diversi[$c])) {
                    $capitolo = $c;
                    break;
                }
            }
            if ($capitolo === null) {
                continue;
            }
            $unita = DB::table('conto_tabella_millesimale as ctm')->join('quote_tabella as qt', 'qt.tabella_id', '=', 'ctm.tabella_id')
                ->whereIn('ctm.conto_id', [(int) $vid, $capitolo])->where('qt.valore', '>', 0)->distinct()->pluck('qt.immobile_id')->map(fn ($id) => (int) $id)->all();
            if (array_intersect_key($cambiano, array_flip($unita)) !== []) {
                $esito[(int) $vid] = $diversi[$capitolo];
            }
        }

        return $esito;
    }

    /**
     * Le unità del condominio con un cambio di titolare dentro l'esercizio: una riga che comincia dopo il primo giorno, o finisce prima dell'ultimo.
     * Con `$soloDirittoReale` le sole righe di proprietario, nudo proprietario e usufruttuario.
     *
     * @return array<int, true>
     */
    private function unitaCheCambiano(Esercizio $esercizio, bool $soloDirittoReale = false): array
    {
        $inizio = substr((string) $esercizio->data_inizio, 0, 10);
        $fine = substr((string) $esercizio->data_fine, 0, 10);
        $q = DB::table('anagrafica_immobile as ai')->join('immobili as i', 'i.id', '=', 'ai.immobile_id')->where('i.condominio_id', $esercizio->condominio_id)
            ->where('ai.attivo', true)
            ->where(fn ($w) => $w->where(fn ($x) => $x->where('ai.data_inizio', '>', $inizio)->where('ai.data_inizio', '<=', $fine))
                ->orWhere(fn ($x) => $x->where('ai.data_fine', '>=', $inizio)->where('ai.data_fine', '<', $fine)));
        if ($soloDirittoReale) {
            $q->whereIn('ai.tipologia', array_map(fn (RuoloAnagraficaImmobile $r) => $r->value, RuoloAnagraficaImmobile::titolariDiDirittoReale()));
        }

        return array_fill_keys($q->distinct()->pluck('ai.immobile_id')->map(fn ($id) => (int) $id)->all(), true);
    }

    /**
     * Le unità con un cambio di titolare dentro l'esercizio con giorni scoperti fra chi esce e chi entra e senza un passaggio registrato (D7: chi entra
     * conta dall'inizio, perché la sua riga non ha un predecessore chiuso il giorno prima): la frase, per unità. Il motore non cambia; la scheda e i
     * controlli dicono di registrare il passaggio.
     *
     * @return array<int, list<string>>
     */
    private function passaggiNonRegistrati(Esercizio $esercizio): array
    {
        $inizio = substr((string) $esercizio->data_inizio, 0, 10);
        $fine = substr((string) $esercizio->data_fine, 0, 10);
        $righe = DB::table('anagrafica_immobile as ai')->join('immobili as i', 'i.id', '=', 'ai.immobile_id')->where('i.condominio_id', $esercizio->condominio_id)
            ->where('ai.attivo', true)->orderBy('ai.immobile_id')->orderBy('ai.tipologia')->orderBy('ai.data_inizio')
            ->get(['ai.id', 'ai.immobile_id', 'ai.anagrafica_id', 'ai.tipologia', 'ai.data_inizio', 'ai.data_fine']);
        $entranti = DB::table('subentri')->where('condominio_id', $esercizio->condominio_id)->whereNotNull('riga_entrante_id')->pluck('riga_entrante_id')
            ->map(fn ($id) => (int) $id)->flip()->all();
        $trovati = [];
        foreach ($righe->groupBy(fn ($r) => $r->immobile_id.'|'.$r->tipologia) as $coppia) {
            foreach ($coppia as $b) {
                $dal = $b->data_inizio === null ? null : substr((string) $b->data_inizio, 0, 10);
                if ($dal === null || $dal <= $inizio || $dal > $fine || isset($entranti[(int) $b->id])) {
                    continue;
                }
                $vigilia = CarbonImmutable::parse($dal)->subDay()->format('Y-m-d');
                $prima = $coppia->filter(fn ($a) => (int) $a->id !== (int) $b->id && $a->data_fine !== null && substr((string) $a->data_fine, 0, 10) < $dal);
                $contiguo = $coppia->contains(fn ($a) => (int) $a->id !== (int) $b->id && ($a->data_inizio === null || substr((string) $a->data_inizio, 0, 10) < $dal)
                    && ($a->data_fine === null || substr((string) $a->data_fine, 0, 10) >= $vigilia));
                if ($prima->isEmpty() || $contiguo) {
                    continue;
                }
                $a = $prima->sortBy(fn ($x) => substr((string) $x->data_fine, 0, 10))->last();
                $trovati[] = ['iid' => (int) $b->immobile_id, 'esce' => (int) $a->anagrafica_id, 'fino' => substr((string) $a->data_fine, 0, 10), 'entra' => (int) $b->anagrafica_id, 'dal' => $dal];
            }
        }
        if ($trovati === []) {
            return [];
        }
        $nomi = DB::table('anagrafiche')->whereIn('id', array_merge(array_column($trovati, 'esce'), array_column($trovati, 'entra')))->pluck('nome', 'id')->all();
        $unita = \App\Models\Immobile::whereIn('id', array_column($trovati, 'iid'))->get()->mapWithKeys(fn ($i) => [(int) $i->id => (string) $i->etichetta])->all();
        $data = fn (string $d) => CarbonImmutable::parse($d)->format('d/m/Y');
        $esito = [];
        foreach ($trovati as $t) {
            $entra = (string) ($nomi[$t['entra']] ?? '');
            $esito[$t['iid']][] = ($unita[$t['iid']] ?? 'Unità '.$t['iid']).': '.($nomi[$t['esce']] ?? '').' fino al '.$data($t['fino']).', '.$entra.' dal '.$data($t['dal'])
                .', senza un passaggio registrato. Così '.$entra.' conta dall\'inizio dell\'anno; con il passaggio registrato conterebbe dal '.$data($t['dal']).'.';
        }

        return $esito;
    }

    /**
     * Le righe dei capitoli dei piani dell'esercizio (`conto_id => id della riga`): la chiave dei tratti di competenza del capitolo
     * del preventivo, che il motore legge allo stesso modo.
     *
     * @return array<int,int>
     */
    private function capitoliDellEsercizio($pianiDellEsercizio): array
    {
        $pianiIds = $pianiDellEsercizio->pluck('id')->all();
        if ($pianiIds === []) {
            return [];
        }

        $capitoli = [];
        foreach (DB::table('piano_rate_capitoli')->whereIn('piano_rate_id', $pianiIds)->orderBy('id')->get(['id', 'conto_id']) as $riga) {
            $capitoli[(int) $riga->conto_id] ??= (int) $riga->id;
        }

        return $capitoli;
    }
}
