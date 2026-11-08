<?php

namespace App\Services\Gestionale;

use App\Enums\TipoMovimentoContabile;
use App\Helpers\DateHelper;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use App\Services\Riparto\CompetenzaDelPiano;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Il riepilogo finanziario dell'esercizio, per competenza (il «riepilogo finanziario» dell'art. 1130-bis c.c., come lo legge la Cassazione): le spese
 * dell'esercizio, pagate o no, per capitolo; le entrate da terzi; le quote dei condòmini; il risultato. Un array solo per la pagina e la stampa: nessuna
 * somma nella vista.
 *
 * LA DATA. La fine dell'esercizio se è finito (per data), oggi se è aperto: il pagato, le quote emesse e pagate si leggono a quella data.
 *
 * LE FONTI, UNA PER NUMERO.
 * - Il consuntivo per voce: `SpesaPerVoceService::perEsercizio` (le spese) e `perTipo` (l'incassato delle voci di entrata).
 * - Fuori dai capitoli, una riga ciascuna, mai silenziose: le fatture contestate (lo stesso predicato che il consuntivo esclude,
 *   `SpesaPerVoceService::legataAContestata`), le commissioni senza voce (le «Spese bancarie» di sistema), i costi non classificati (ogni altra riga di
 *   costo senza voce). Con il consuntivo sommano ai costi del giornale: la riga che riconcilia lo dice, e la differenza, se c'è, è un numero suo.
 * - Il pagato e il da pagare: `PagatoAllaData` sulle fatture dell'esercizio (il loro perimetro: non sono i debiti della situazione patrimoniale, che
 *   sono di tutti gli esercizi alla data); una riga di costo con la voce in una scrittura che non è una fattura (la regolazione, la commissione con la
 *   voce) è pagata alla data della sua scrittura.
 * - Le quote: `LetturaDebiti::quoteDeiPiani` sulle rate dei piani dell'esercizio (`CompetenzaDelPiano::esercizioDelPiano`).
 * - Il preventivo e il deliberato: il deliberato con le rate è la somma delle righe del riparto dei piani approvati dell'esercizio, per voce; il
 *   preventivo è l'importo della voce finché la gestione non è agganciata a un esercizio dopo (poi la voce porta già il preventivo dell'anno dopo, e
 *   la colonna rimanda al verbale dell'assemblea). Per una gestione straordinaria solo il deliberato: il piano chiede le fatture, e uno scostamento non c'è.
 * - Il risultato: `StatoPatrimonialePaginaService::risultatoDi`, con i suoi due termini dal giornale; una riga lega le quote dei piani alle quote a
 *   giornale.
 * - La gestione: quella della voce (il suo piano dei conti) per i costi, quella del piano per le quote.
 */
final class RiepilogoPerCompetenza
{
    /** Perché il deliberato con le rate non c'è: i piani generati prima che il programma ne conservasse il riparto. Una frase sola, per la pagina e per la stampa. */
    public const FRASE_DELIBERATO_NON_DISPONIBILE = 'Il deliberato con le rate non è disponibile per i piani generati prima che il programma ne conservasse il dettaglio: '
        .'è nel verbale dell\'assemblea che li ha approvati.';

    public function __construct(
        private readonly SpesaPerVoceService $spese,
        private readonly PagatoAllaData $pagato,
        private readonly LetturaDebiti $debiti,
        private readonly StatoPatrimonialePaginaService $pagina,
    ) {}

    public function perEsercizio(Condominio $condominio, Esercizio $esercizio, ?string $oggi = null): array
    {
        $oggi = $oggi ?? DateHelper::oggiUtente();
        $dal = $esercizio->data_inizio->format('Y-m-d');
        $fine = $esercizio->data_fine->format('Y-m-d');
        $finito = $fine < $oggi;
        $alla = $finito ? $fine : $oggi;
        $dopo = CarbonImmutable::parse($alla, 'UTC')->addDay()->format('Y-m-d');

        $gestioni = DB::table('gestioni')->join('esercizio_gestione as eg', 'eg.gestione_id', '=', 'gestioni.id')
            ->where('eg.esercizio_id', $esercizio->id)->orderBy('gestioni.id')->get(['gestioni.id', 'gestioni.nome', 'gestioni.tipo'])->keyBy('id');
        $voci = DB::table('conti')->join('piani_conti as pc', 'pc.id', '=', 'conti.piano_conto_id')
            ->whereIn('pc.gestione_id', $gestioni->keys()->all())
            ->orderBy('conti.id')
            ->get(['conti.id', 'conti.nome', 'conti.tipo', 'conti.importo', 'conti.parent_id', 'pc.gestione_id']);

        $speso = $this->spese->perEsercizio($esercizio);
        $perTipo = $this->spese->perTipo($speso, $esercizio);
        $prima = Esercizio::where('condominio_id', $esercizio->condominio_id)->where('data_inizio', '<', $dal)->orderByDesc('data_inizio')->first();
        $perTipoPrima = $prima ? $this->spese->perTipo($this->spese->perEsercizio($prima), $prima) : null;

        $fatture = $this->pagato->perFatture((int) $condominio->id, $alla, (int) $esercizio->id);
        $movimenti = $this->movimentiSenzaFattura($esercizio, $dopo);
        $dettaglio = $this->dettaglioPerVoce($fatture, $movimenti);

        $piani = $this->pianiDellEsercizio($condominio, $esercizio);
        $deliberato = $this->deliberato($piani);
        $successivi = DB::table('esercizio_gestione as eg')->join('esercizi as e', 'e.id', '=', 'eg.esercizio_id')
            // «Dal giorno dopo»: su SQLite le date sono testo con l'ora, e '2026-01-01 00:00:00' > '2026-01-01' è vero.
            ->whereIn('eg.gestione_id', $gestioni->keys()->all())->where('e.data_inizio', '>=', CarbonImmutable::parse($dal)->addDay()->format('Y-m-d'))->distinct()->pluck('eg.gestione_id')->map(fn ($id) => (int) $id)->flip();

        $quote = $this->debiti->quoteDeiPiani((int) $condominio->id, $piani->pluck('id')->map(fn ($id) => (int) $id)->all(), $alla, (int) $esercizio->id);

        $blocchi = [];
        foreach ($gestioni as $g) {
            $gestioneId = (int) $g->id;
            $straordinaria = $g->tipo === 'straordinaria';
            $vociG = $voci->where('gestione_id', $gestioneId);
            $leggi = function ($v) use ($perTipo, $perTipoPrima, $prima, $dettaglio, $deliberato, $gestioneId, $straordinaria, $successivi) {
                $entrata = $v->tipo === 'entrata';
                // Le spese della voce (per una voce di entrata, quelle scritte su di lei prima che la registrazione la rifiutasse): il loro pagato e il
                // loro da pagare, che il «da pagare» del riepilogo conta.
                $consuntivo = (int) ($perTipo['spese'][$v->id] ?? 0);
                $d = $dettaglio[(int) $v->id] ?? ['pagato' => 0, 'ritenute_da_versare' => 0, 'pagato_in_piu' => 0, 'righe' => []];
                $spese = [
                    'consuntivo' => $consuntivo,
                    'pagato' => $d['pagato'],
                    // Mai sotto zero per una fattura: un pagamento in più sta nella sua colonna, e consuntivo = pagato − pagato in più + da pagare.
                    'da_pagare' => $consuntivo - $d['pagato'] + $d['pagato_in_piu'],
                    'pagato_in_piu' => $d['pagato_in_piu'],
                    'ritenute_da_versare' => $d['ritenute_da_versare'],
                    'dettaglio' => $d['righe'],
                ];
                $preventivoStato = $straordinaria ? 'solo_deliberato' : (isset($successivi[$gestioneId]) ? 'non_conservato' : 'conservato');
                $delib = $deliberato[$gestioneId] ?? ['stato' => 'nessun_piano', 'per_voce' => []];
                $delibValore = $delib['stato'] === 'conservato' ? (int) ($delib['per_voce'][(int) $v->id] ?? 0) * ($entrata ? -1 : 1) : null;
                $riga = [
                    'voce_id' => (int) $v->id, 'nome' => $v->nome, 'tipo' => $entrata ? 'entrata' : 'spesa',
                    'preventivo' => $preventivoStato === 'conservato' ? (int) $v->importo : null, 'preventivo_stato' => $preventivoStato,
                    'deliberato' => $delibValore, 'deliberato_stato' => $delib['stato'],
                ];
                if ($entrata) {
                    return $riga + [
                        'incassato' => (int) ($perTipo['incassato'][$v->id] ?? 0),
                        'spese_sulla_voce' => $consuntivo,
                        'anno_prima' => $prima ? (int) ($perTipoPrima['incassato'][$v->id] ?? 0) : null,
                    ] + $spese;
                }

                return $riga + [
                    'anno_prima' => $prima ? (int) ($perTipoPrima['spese'][$v->id] ?? 0) : null,
                    'scostamento' => ! $straordinaria && $delibValore !== null ? $consuntivo - $delibValore : null,
                    'scostamento_preventivo' => $preventivoStato === 'conservato' ? $consuntivo - (int) $v->importo : null,
                ] + $spese;
            };

            $spese = [];
            $entrate = [];
            foreach ($vociG->whereNull('parent_id') as $radice) {
                $figli = $this->foglie($vociG, (int) $radice->id);
                $capitolo = $figli === [] ? $leggi($radice) : $this->capitolo($leggi($radice), array_map($leggi, $figli));
                if ($capitolo['tipo'] === 'entrata') {
                    $entrate[] = $capitolo;
                } else {
                    $spese[] = $capitolo;
                }
            }

            $q = $quote[$gestioneId] ?? null;
            $delibQuote = ($deliberato[$gestioneId]['stato'] ?? 'nessun_piano') === 'conservato' ? (int) array_sum($deliberato[$gestioneId]['per_voce']) : null;
            $righeQuote = [];
            if ($q !== null || $delibQuote !== null) {
                $righeQuote[] = ['riga' => $straordinaria ? 'straordinarie' : 'ordinarie', 'deliberate' => $delibQuote] + ($q['quote'] ?? $this->quoteVuote());
                // Il pregresso ha la sua riga quando c'è: anche un credito riportato soltanto (nessuna quota emessa, un credito sulla rata).
                if ($q !== null && array_filter($q['pregresso']) !== []) {
                    $righeQuote[] = ['riga' => 'esercizi_precedenti', 'deliberate' => null] + $q['pregresso'];
                }
            }

            $blocchi[] = [
                'gestione' => ['id' => $gestioneId, 'nome' => $g->nome, 'tipo' => $g->tipo],
                'spese' => $spese,
                'entrate' => $entrate,
                'quote' => $righeQuote,
                'totale_spese' => $this->somma($spese, ['preventivo', 'deliberato', 'consuntivo', 'anno_prima', 'pagato', 'da_pagare', 'pagato_in_piu', 'ritenute_da_versare']),
                'totale_entrate' => $this->somma($entrate, ['preventivo', 'deliberato', 'incassato', 'anno_prima', 'consuntivo', 'pagato', 'da_pagare', 'pagato_in_piu']),
                'cumulato_opera' => $straordinaria ? $this->spese->cumulatoDellaGestione($gestioneId, $dal) : null,
            ];
        }

        $fuori = $this->fuoriDaiCapitoli($esercizio, $fatture, $dopo);
        $risultato = $this->pagina->risultatoDi($condominio, $esercizio, $oggi);
        // Le spese per capitolo meno le entrate **che la pagina mostra**: una spesa del giornale che non sta in nessuna riga esce come differenza.
        $capitoliNetti = (int) array_sum(array_map(fn ($b) => (int) $b['totale_spese']['consuntivo'] + (int) $b['totale_entrate']['consuntivo'] - (int) $b['totale_entrate']['incassato'], $blocchi));
        $sommaFuori = $fuori['commissioni_senza_voce']['consuntivo'] + $fuori['contestate']['consuntivo'] + $fuori['non_classificati']['consuntivo'];
        $totaleQuote = $this->totaleQuote($blocchi);
        // La riga che lega le quote pagate ai versamenti dei condòmini del registro: il numero del registro, sommato sul suo perimetro.
        $totaleQuote['versamenti_registro'] = app(RegistroContabilitaService::class)->versamentiDeiCondomini($esercizio);

        return [
            'esercizio' => ['id' => (int) $esercizio->id, 'nome' => $esercizio->nome, 'dal' => $dal, 'al' => $fine],
            'alla' => $alla,
            'finito' => $finito,
            'anno_prima' => $prima ? ['id' => (int) $prima->id, 'nome' => $prima->nome] : null,
            'blocchi' => $blocchi,
            'fuori_capitoli' => $fuori,
            'quote' => ['totale' => $totaleQuote],
            'risultato' => ['quote_emesse' => $risultato['quote_emesse'], 'costi' => $risultato['costi'], 'risultato' => $risultato['risultato']],
            'raccordo_quote' => $this->raccordoQuote($totaleQuote, $risultato['quote_emesse'], $esercizio, $piani->pluck('id')->map(fn ($id) => (int) $id)->all()),
            'riconciliazione' => [
                'costi' => $risultato['costi'], 'capitoli_netti' => $capitoliNetti,
                'commissioni_senza_voce' => $fuori['commissioni_senza_voce']['consuntivo'], 'contestate' => $fuori['contestate']['consuntivo'],
                'non_classificati' => $fuori['non_classificati']['consuntivo'], 'differenza' => $risultato['costi'] - $capitoliNetti - $sommaFuori,
            ],
            'totali' => [
                'da_pagare' => (int) array_sum(array_map(fn ($b) => (int) $b['totale_spese']['da_pagare'] + (int) $b['totale_entrate']['da_pagare'], $blocchi))
                    + $fuori['contestate']['da_pagare'] + $fuori['commissioni_senza_voce']['da_pagare'] + $fuori['non_classificati']['da_pagare'],
                'pagato_in_piu' => (int) array_sum(array_map(fn ($b) => (int) $b['totale_spese']['pagato_in_piu'] + (int) $b['totale_entrate']['pagato_in_piu'], $blocchi)),
            ],
            'avvertenze' => [
                'perimetro_da_pagare' => 'Il «da pagare» è delle fatture di questo esercizio: i debiti verso i fornitori della situazione patrimoniale sono di tutti gli esercizi, alla data, e possono essere diversi.',
                'pagato_diviso' => 'Il pagato di una fattura su più voci, pagata in parte, è diviso fra le voci in proporzione al loro costo: un pagamento non dice quale voce paga.',
                'anno_prima' => $prima ? null : 'primo esercizio nel programma',
                // Una frase sola, quando almeno una gestione ha un piano approvato senza le righe del riparto.
                'deliberato' => $gestioni->keys()->contains(fn ($id) => ($deliberato[(int) $id]['stato'] ?? null) === 'non_conservato') ? self::FRASE_DELIBERATO_NON_DISPONIBILE : null,
                'data' => $finito ? 'alla data del '.CarbonImmutable::parse($alla)->format('d/m/Y') : 'a oggi: cambia a ogni lettura, finché l\'esercizio è aperto',
            ],
        ];
    }

    /**
     * Le righe con la voce delle scritture dell'esercizio che non sono la scrittura di competenza di una fattura (la regolazione, la commissione con la
     * voce, i loro storni, l'incasso da terzi), per voce e per scrittura, con il pagato alla data della scrittura. Senza le scritture legate a una
     * fattura contestata, come il consuntivo.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function movimentiSenzaFattura(Esercizio $esercizio, string $dopo)
    {
        return DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', fn ($j) => $j->on('rs.scrittura_id', '=', 'sc.id')->whereNull('sc.deleted_at'))
            ->where('sc.esercizio_id', $esercizio->id)
            ->whereNotNull('rs.voce_spesa_id')
            ->whereNotExists(fn ($q) => SpesaPerVoceService::legataAContestata($q))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('fattura_scrittura as fc')->whereColumn('fc.scrittura_contabile_id', 'sc.id')->where('fc.tipo', 'competenza'))
            // Gli incassi da terzi e i loro storni sono l'incassato della voce di entrata, non una spesa da pagare (`SpesaPerVoceService::perTipo`).
            ->where(fn ($q) => $q->whereNull('sc.tipo_movimento')->orWhereNotIn('sc.tipo_movimento', [TipoMovimentoContabile::INCASSO_DIVERSO->value, TipoMovimentoContabile::STORNO_INCASSO_DIVERSO->value]))
            ->groupBy('rs.voce_spesa_id', 'sc.id', 'sc.numero_protocollo', 'sc.causale', 'sc.data_competenza', 'sc.tipo_movimento')
            ->orderBy('sc.data_competenza')->orderBy('sc.id')
            ->select(['rs.voce_spesa_id', 'sc.id', 'sc.numero_protocollo', 'sc.causale', 'sc.data_competenza', 'sc.tipo_movimento'])
            ->selectRaw("SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END) as netto")
            ->selectRaw('CASE WHEN sc.data_competenza < ? THEN 1 ELSE 0 END as entro', [$dopo])
            ->get();
    }

    /**
     * Per voce: il pagato (le parti delle fatture non contestate e i movimenti senza fattura entro la data), le ritenute da versare, e il dettaglio per
     * spesa: una riga per fattura e una per movimento, che sommano alla voce.
     *
     * @return array<int, array{pagato:int, ritenute_da_versare:int, righe:list<array<string,mixed>>}>
     */
    private function dettaglioPerVoce(array $fatture, $movimenti): array
    {
        $descrizioni = $fatture === [] ? collect() : DB::table('righe_fattura')->whereIn('fattura_passiva_id', array_keys($fatture))->whereNotNull('conto_id')
            ->orderBy('id')->get(['fattura_passiva_id', 'conto_id', 'descrizione'])
            ->groupBy(fn ($r) => $r->fattura_passiva_id.'|'.$r->conto_id)
            ->map(fn ($g) => $g->pluck('descrizione')->filter()->unique()->implode('; '));

        $esito = [];
        foreach ($fatture as $f) {
            if ($f['contestata']) {
                continue;
            }
            foreach ($f['parti'] as $voce => $p) {
                if (! is_int($voce)) {
                    continue;
                }
                // Il da pagare di una fattura non scende sotto zero: quello che è pagato oltre il costo è «pagato in più», a parte. Una nota di credito
                // non ancora compensata resta negativa: è un credito verso il fornitore.
                $resto = $p['costo'] - $p['pagato'];
                $inPiu = $f['segno'] > 0 ? max(0, -$resto) : 0;
                $esito[$voce] ??= ['pagato' => 0, 'ritenute_da_versare' => 0, 'pagato_in_piu' => 0, 'righe' => []];
                $esito[$voce]['pagato'] += $p['pagato'];
                $esito[$voce]['ritenute_da_versare'] += $p['ritenute_da_versare'];
                $esito[$voce]['pagato_in_piu'] += $inPiu;
                $esito[$voce]['righe'][] = ['tipo' => 'fattura', 'fattura_id' => $f['fattura_id'], 'numero' => $f['numero'], 'fornitore' => $f['fornitore'],
                    'descrizione' => $descrizioni[$f['fattura_id'].'|'.$voce] ?? null, 'data' => $f['data'],
                    'consuntivo' => $p['costo'], 'pagato' => $p['pagato'], 'da_pagare' => $resto + $inPiu, 'pagato_in_piu' => $inPiu];
            }
        }
        foreach ($movimenti as $m) {
            $voce = (int) $m->voce_spesa_id;
            $netto = (int) $m->netto;
            $pagato = (int) $m->entro === 1 ? $netto : 0;
            $esito[$voce] ??= ['pagato' => 0, 'ritenute_da_versare' => 0, 'pagato_in_piu' => 0, 'righe' => []];
            $esito[$voce]['pagato'] += $pagato;
            // La riga con la voce dentro un pagamento è la sua commissione: la causale è quella del pagamento, e da sola si leggerebbe come il pagamento.
            $commissione = ($m->tipo_movimento instanceof \BackedEnum ? $m->tipo_movimento->value : (string) $m->tipo_movimento) === TipoMovimentoContabile::PAGAMENTO_FORNITORE->value;
            $esito[$voce]['righe'][] = ['tipo' => 'movimento', 'scrittura_id' => (int) $m->id, 'numero' => $m->numero_protocollo, 'fornitore' => null,
                'descrizione' => $commissione ? 'Commissione del pagamento: '.$m->causale : $m->causale, 'data' => substr((string) $m->data_competenza, 0, 10), 'consuntivo' => $netto, 'pagato' => $pagato, 'da_pagare' => $netto - $pagato,
                'pagato_in_piu' => 0];
        }

        return $esito;
    }

    /**
     * Le tre righe fuori dai capitoli: le fatture contestate (le righe con la voce delle scritture legate a una contestata), le commissioni senza voce
     * (sulle «Spese bancarie» di sistema), i costi non classificati (ogni altra riga di costo senza voce). Consuntivo, pagato alla data, da pagare.
     *
     * @return array{contestate: array<string,int>, commissioni_senza_voce: array<string,int>, non_classificati: array<string,int>}
     */
    private function fuoriDaiCapitoli(Esercizio $esercizio, array $fatture, string $dopo): array
    {
        $base = fn () => DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', fn ($j) => $j->on('rs.scrittura_id', '=', 'sc.id')->whereNull('sc.deleted_at'))
            ->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
            ->where('sc.esercizio_id', $esercizio->id);
        $netto = "SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END)";
        $leggi = fn ($q) => $q->selectRaw("COALESCE({$netto}, 0) as consuntivo")
            ->selectRaw("COALESCE(SUM(CASE WHEN sc.data_competenza < ? AND NOT EXISTS (SELECT 1 FROM fattura_scrittura fc2 WHERE fc2.scrittura_contabile_id = sc.id AND fc2.tipo = 'competenza') THEN (CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END) ELSE 0 END), 0) as pagato_movimenti", [$dopo])
            ->selectRaw('COUNT(DISTINCT sc.id) as scritture')
            ->first();

        // Le fatture contestate: le righe con la voce delle scritture legate a una contestata; il pagato delle fatture, per le loro parti con la voce.
        $c = $leggi($base()->whereNotNull('rs.voce_spesa_id')->whereExists(fn ($q) => SpesaPerVoceService::legataAContestata($q)));
        $pagatoContestate = 0;
        $numeroContestate = 0;
        $pagatoSenzaVoce = [PagatoAllaData::COMMISSIONE_SENZA_VOCE => 0, PagatoAllaData::COSTO_SENZA_VOCE => 0];
        foreach ($fatture as $f) {
            foreach ($f['parti'] as $parte => $p) {
                if (is_int($parte) && $f['contestata']) {
                    $pagatoContestate += $p['pagato'];
                } elseif (isset($pagatoSenzaVoce[$parte])) {
                    $pagatoSenzaVoce[$parte] += $p['pagato'];
                }
            }
            $numeroContestate += $f['contestata'] ? 1 : 0;
        }
        $contestate = ['consuntivo' => (int) $c->consuntivo, 'pagato' => $pagatoContestate + (int) $c->pagato_movimenti, 'numero' => $numeroContestate];

        $s = $leggi($base()->whereNull('rs.voce_spesa_id')->where('cc.ruolo', 'spese_bancarie'));
        $commissioni = ['consuntivo' => (int) $s->consuntivo, 'pagato' => $pagatoSenzaVoce[PagatoAllaData::COMMISSIONE_SENZA_VOCE] + (int) $s->pagato_movimenti,
            'numero' => (int) $base()->whereNull('rs.voce_spesa_id')->where('cc.ruolo', 'spese_bancarie')->where('rs.tipo_riga', 'dare')->count()];

        $n = $leggi($base()->whereNull('rs.voce_spesa_id')->where('cc.tipo', 'costo')->where(fn ($q) => $q->whereNull('cc.ruolo')->orWhere('cc.ruolo', '<>', 'spese_bancarie')));
        $nonClassificati = ['consuntivo' => (int) $n->consuntivo, 'pagato' => $pagatoSenzaVoce[PagatoAllaData::COSTO_SENZA_VOCE] + (int) $n->pagato_movimenti, 'numero' => (int) $n->scritture];

        $con = fn (array $r) => $r + ['da_pagare' => $r['consuntivo'] - $r['pagato']];

        return ['contestate' => $con($contestate), 'commissioni_senza_voce' => $con($commissioni), 'non_classificati' => $con($nonClassificati)];
    }

    /** I piani rate dell'esercizio: quelli generati con questo esercizio, o dedotti per i piani di prima della colonna. */
    private function pianiDellEsercizio(Condominio $condominio, Esercizio $esercizio)
    {
        $competenza = app(CompetenzaDelPiano::class);

        return PianoRate::where('condominio_id', $condominio->id)->get()
            ->filter(fn (PianoRate $p) => (int) ($competenza->esercizioDelPiano($p)?->id ?? 0) === (int) $esercizio->id)->values();
    }

    /**
     * Il deliberato con le rate, per gestione e per voce: la somma delle righe del riparto dei piani approvati. Stato `non_conservato` se un piano approvato
     * non ha le righe (generato prima che il riparto si scrivesse): la colonna dice «non disponibile» e {@see FRASE_DELIBERATO_NON_DISPONIBILE} dice perché;
     * «nessun piano» se la gestione non ne ha uno approvato.
     *
     * @return array<int, array{stato:string, per_voce: array<int,int>}>
     */
    private function deliberato($piani): array
    {
        $esito = [];
        $approvati = $piani->filter(fn ($p) => ($p->stato instanceof \BackedEnum ? $p->stato->value : $p->stato) === 'approvato');
        foreach ($approvati->groupBy('gestione_id') as $gestioneId => $pianiG) {
            $ids = $pianiG->pluck('id')->all();
            $conRighe = DB::table('righe_riparto')->whereIn('piano_rate_id', $ids)->distinct()->pluck('piano_rate_id')->all();
            if (count($conRighe) < count($ids)) {
                $esito[(int) $gestioneId] = ['stato' => 'non_conservato', 'per_voce' => []];

                continue;
            }
            $esito[(int) $gestioneId] = ['stato' => 'conservato', 'per_voce' => DB::table('righe_riparto')->whereIn('piano_rate_id', $ids)->whereNotNull('conto_id')
                ->groupBy('conto_id')->select('conto_id', DB::raw('SUM(importo) as importo'))->pluck('importo', 'conto_id')->map(fn ($i) => (int) $i)->all()];
        }

        return $esito;
    }

    /** Le foglie sotto una radice (a ogni profondità). */
    private function foglie($voci, int $radiceId): array
    {
        $figli = $voci->where('parent_id', $radiceId)->values()->all();
        $foglie = [];
        foreach ($figli as $f) {
            $sotto = $this->foglie($voci, (int) $f->id);
            array_push($foglie, ...($sotto === [] ? [$f] : $sotto));
        }

        return $foglie;
    }

    /** Un capitolo con le voci sotto: le sue cifre sono le somme delle voci (nessun valore suo). */
    private function capitolo(array $radice, array $voci): array
    {
        // Le cifre scritte sul capitolo stesso (una voce con le sue fatture diventata capitolo, le righe del riparto di prima): una riga loro, con il loro
        // dettaglio, che il capitolo somma alle voci. Senza, quella spesa non starebbe in nessuna riga della pagina.
        $proprie = array_intersect_key($radice, array_flip(['consuntivo', 'pagato', 'da_pagare', 'pagato_in_piu', 'ritenute_da_versare', 'deliberato', 'anno_prima', 'incassato']));
        if (array_filter($proprie, fn ($v) => $v !== null && $v !== 0) !== []) {
            $voci[] = array_merge($radice, ['nome' => 'Scritto sul capitolo', 'proprio' => true, 'preventivo' => null, 'scostamento' => null, 'scostamento_preventivo' => null]);
        }
        $somme = $this->somma($voci, ['preventivo', 'deliberato', 'consuntivo', 'incassato', 'spese_sulla_voce', 'anno_prima', 'scostamento', 'scostamento_preventivo', 'pagato', 'da_pagare',
            'pagato_in_piu', 'ritenute_da_versare']);
        $capitolo = array_merge($radice, array_intersect_key($somme, $radice), ['voci' => $voci]);
        unset($capitolo['dettaglio']);

        return $capitolo;
    }

    /** La somma di colonne: null se ogni valore è null (la colonna non c'è), altrimenti la somma dei valori presenti. */
    private function somma(array $righe, array $colonne): array
    {
        $esito = [];
        foreach ($colonne as $c) {
            $valori = array_filter(array_column($righe, $c), fn ($v) => $v !== null);
            $esito[$c] = $valori === [] ? null : (int) array_sum($valori);
        }

        return $esito;
    }

    private function quoteVuote(): array
    {
        return ['emesse' => 0, 'pagate' => 0, 'non_pagate' => 0, 'scadute' => 0, 'crediti' => 0, 'credito_riportato' => 0, 'emesse_altrove' => 0];
    }

    /** Il totale delle quote dei blocchi: il deliberato è null se in un blocco non è conservato. */
    private function totaleQuote(array $blocchi): array
    {
        $totale = $this->quoteVuote() + ['deliberate' => 0];
        $deliberateNote = true;
        foreach ($blocchi as $b) {
            foreach ($b['quote'] as $r) {
                foreach (array_keys($this->quoteVuote()) as $k) {
                    $totale[$k] += (int) $r[$k];
                }
                if ($r['riga'] !== 'esercizi_precedenti') {
                    $deliberateNote = $deliberateNote && $r['deliberate'] !== null;
                    $totale['deliberate'] += (int) $r['deliberate'];
                }
            }
        }
        $totale['deliberate'] = $deliberateNote ? $totale['deliberate'] : null;
        // Il netto della persona, come la Tesoreria: le non pagate meno i crediti sulle rate.
        $totale['netto'] = $totale['non_pagate'] - $totale['crediti'];

        return $totale;
    }

    /**
     * La riga che lega le quote dei piani dell'esercizio alle quote a giornale del risultato: quote dei piani = quote a giornale + rate di questi piani
     * emesse in un altro esercizio − rate di altri piani emesse in questo; quello che resta (una parte di saldi dentro una rata) è un numero suo.
     */
    private function raccordoQuote(array $totale, int $giornale, Esercizio $esercizio, array $pianiIds): array
    {
        $piani = $totale['emesse'];
        $altrove = $totale['emesse_altrove'];
        $altri = (int) DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', fn ($j) => $j->on('rs.scrittura_id', '=', 'sc.id')->whereNull('sc.deleted_at'))
            ->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
            ->join('rate', 'rate.id', '=', 'rs.rata_id')
            ->where('sc.esercizio_id', $esercizio->id)->where('sc.tipo_movimento', 'emissione_rata')->where('cc.ruolo', 'crediti_condomini')->where('rs.tipo_riga', 'dare')
            ->when($pianiIds !== [], fn ($q) => $q->whereNotIn('rate.piano_rate_id', $pianiIds))
            ->sum('rs.importo');

        return ['quote_piani' => $piani, 'quote_giornale' => $giornale, 'piani_emesse_altrove' => $altrove, 'altri_emesse_qui' => $altri,
            'altre_differenze' => $piani - ($giornale + $altrove - $altri)];
    }
}
