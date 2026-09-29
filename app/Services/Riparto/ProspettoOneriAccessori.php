<?php

namespace App\Services\Riparto;

use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Support\InsiemePeriodi;
use App\Support\PeriodoCompetenza;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Il prospetto degli oneri accessori di un'unità in un esercizio (B3a, 1.11.0-beta.34).
 *
 * **Non è un riparto e non è una richiesta del condominio.** Verso il condominio risponde il proprietario: l'amministratore
 * riscuote dai condòmini, non dal conduttore (art. 63 disp. att. c.c.; D11 del progetto). È il documento con cui il
 * proprietario regola col conduttore le spese che il riparto pone a carico dell'inquilino — l'«indicazione specifica
 * delle spese con la menzione dei criteri di ripartizione» che l'art. 9 co. 3 L. 392/1978 gli riconosce prima di pagare.
 *
 * Si legge sul **preventivo** (decisione di Vincenzo del 26/09/2026): i piani rate approvati dell'esercizio, dal riparto
 * congelato (`DettaglioRiparto`, fonte `registrato`; un piano della 1.10 senza righe si legge `ricostruito` e lo si
 * dice). Il consuntivo arriva con il rendiconto (1.12).
 *
 * Le righe sono quelle con `ruolo_richiesto = inquilino`, e sono di due specie:
 *
 * - `ruolo_risolto = inquilino`: il motore le ha già intestate all'inquilino nelle rate — **«nelle sue rate»**;
 * - `ruolo_risolto` diverso (ripiego, decisione 22): nei giorni del piano nessun inquilino era registrato e le ha pagate
 *   chi c'era (di solito il proprietario). Se oggi un conduttore risulta in quei giorni — una locazione registrata dopo la
 *   generazione — la sua parte si divide **sui giorni di conduzione con la regola del motore** (D7,
 *   `RisolutoreTitolari::giorniDiTitolarita`): è **da rimborsare** a chi ha pagato. I giorni senza nessun conduttore
 *   restano a chi ha pagato, in una sezione a sé.
 *
 * La **quota inquilino** di una voce si legge dagli importi congelati (parte inquilino / tutte le righe della stessa voce
 * e tabella sull'unità), non da `conto_tabella_ripartizioni`, che si può modificare dopo la generazione: è l'istantanea
 * che §3.3 delle pertinenze chiede. Nessuna data di mora: i due mesi decorrono dalla ricezione della richiesta del
 * locatore, che il programma non conosce.
 */
final class ProspettoOneriAccessori
{
    public function __construct(
        private readonly RisolutoreTitolari $titolari = new RisolutoreTitolari(),
        private readonly CompetenzaDelPiano $competenza = new CompetenzaDelPiano(),
    ) {
    }

    /**
     * @return array{
     *   unita: array{id: int, etichetta: string}, esercizio: array{id: int, nome: ?string, dal: ?string, al: ?string},
     *   intestazione: list<string>, piani: list<array<string,mixed>>, piani_esclusi: list<array{nome: string, motivo: string}>,
     *   conduttori: list<array<string,mixed>>, senza_conduttore: array{voci: list<array<string,mixed>>, totale: int},
     *   totale_inquilino: int
     * }
     */
    public function calcola(Immobile $unita, Esercizio $esercizio): array
    {
        $periodoEsercizio = PeriodoCompetenza::daEsercizio($esercizio);
        [$piani, $esclusi] = $this->pianiDellEsercizio($unita, $esercizio);

        // La titolarità di oggi della coppia (unità, inquilino), con la regola del motore: solo le righe attive (R14,
        // Fase 1-bis — una riga spenta non fa da predecessore). Serve a dividere i ripieghi sui giorni di conduzione.
        $righeInquilino = $this->titolari->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $unita->id)->where('tipologia', 'inquilino'))
            ->orderBy('data_inizio')->get();
        $partecipanti = $righeInquilino->filter(fn ($r) => (float) $r->quota > 0)->values();

        $voci = [];      // [anagrafica_id => list<voce>]
        $senza = [];     // list<voce>
        $pianiLetti = [];
        $totaleInquilino = 0;

        foreach ($piani as $piano) {
            $dettaglio = DettaglioRiparto::perPiano($piano);
            $fonte = $dettaglio['fonte']['tipo'];
            if ($fonte === DettaglioRiparto::ANTEPRIMA) {
                $esclusi[] = ['nome' => $piano->nome, 'motivo' => 'senza rate generate'];
                continue;
            }
            $righe = collect($dettaglio['righe'])->filter(fn ($r) => (int) ($r['immobile_id'] ?? 0) === (int) $unita->id);
            $riparto = $righe->where('tipo', 'riparto');

            // R2 (Fase 1-bis): un piano della 1.10 senza riparto registrato si ricostruisce con i titolari di oggi. Se
            // le persone del riparto ricostruito non sono quelle che hanno le rate, le voci non si riconducono alle rate:
            // il piano esce, e si dice perché. Non si tira a indovinare.
            if ($fonte === DettaglioRiparto::RICOSTRUITO && ! $this->stessePersone($riparto, (int) $piano->id, (int) $unita->id)) {
                $esclusi[] = ['nome' => $piano->nome, 'motivo' => 'generato da una versione precedente senza riparto registrato, e i titolari dell\'unità sono cambiati dopo la generazione: le voci non si possono ricondurre alle rate'];
                continue;
            }
            $pianiLetti[] = ['id' => (int) $piano->id, 'nome' => $piano->nome, 'tipo' => $piano->tipo, 'fonte' => $fonte, 'dedotto' => $piano->esercizio_id === null];

            // Quota inquilino per (voce, tabella), dagli importi congelati.
            $quotaInquilino = $riparto->groupBy(fn ($r) => ($r['conto_id'] ?? 0) . '|' . ($r['tabella_id'] ?? 0))->map(function (Collection $g) {
                $tutto = (int) $g->sum('importo');
                $inq = (int) $g->where('ruolo_richiesto', 'inquilino')->sum('importo');

                return $tutto !== 0 ? (int) round(100 * $inq / $tutto) : null;
            });
            // R6: la competenza a tratti del capitolo (decisione 20) — la riga ne porta solo gli estremi.
            $trattiConto = PeriodoDellaRiga::trattiPerConto((int) $piano->id);
            $esercizioInsieme = $periodoEsercizio !== null ? InsiemePeriodi::uno($periodoEsercizio) : null;

            foreach ($riparto->where('ruolo_richiesto', 'inquilino') as $r) {
                $importo = (int) $r['importo'];
                $totaleInquilino += $importo;
                $competenza = $this->competenzaDellaRiga($r, $trattiConto, $esercizioInsieme);
                $base = [
                    'piano'           => $piano->nome,
                    'piano_rate_id'   => (int) $piano->id,
                    'conto_id'        => (int) ($r['conto_id'] ?? 0),
                    'conto'           => $r['conto_nome'],
                    'tabella_id'      => (int) ($r['tabella_id'] ?? 0),
                    'tabella'         => $r['tabella_nome'],
                    'millesimi'       => ['valore' => (float) $r['valore_millesimo'], 'somma' => (float) $r['somma_valori'], 'unita' => $r['tabella_quota']],
                    'quota_inquilino' => $quotaInquilino[($r['conto_id'] ?? 0) . '|' . ($r['tabella_id'] ?? 0)] ?? null,
                    'competenza'      => $competenza?->toArray(),
                    'nota'            => null,
                ];

                if (($r['ruolo_risolto'] ?? null) === 'inquilino') {
                    $giorni = $r['giorni_titolarita'] ?? $this->periodoDellaRiga($r, $competenza)?->giorni();
                    $voci[(int) $r['anagrafica_id']][] = $base + ['importo' => $importo, 'giorni' => $giorni !== null ? (int) $giorni : null, 'modo' => 'rate', 'pagato_da' => null];
                    continue;
                }

                // Ripiego: pagata da chi c'era nei giorni senza inquilino. R5: il tratto congelato può essere l'estensione
                // di più buchi — si ricostruiscono i giorni veri togliendo quelli degli altri ruoli, o la riga non si divide.
                $periodo = $this->periodoDellaRiga($r, $competenza);
                if ($periodo !== null && ! empty($r['titolarita_dal'])) {
                    $periodo = PeriodoDellaRiga::senzaGliAltri($periodo, $r, $riparto);
                }
                $pagatoDa = Anagrafica::whereKey($r['anagrafica_id'])->value('nome');
                if ($periodo === null) {
                    $senza[] = $base + ['importo' => $importo, 'giorni' => $r['giorni_titolarita'] ?? null, 'modo' => 'proprietario', 'pagato_da' => $pagatoDa,
                        'nota' => 'i giorni di questa voce non si ricostruiscono dal riparto registrato: non si divide'];
                    continue;
                }

                // R10: chi ha pagato cambia con le vendite (e con l'usufrutto) registrate dopo la generazione: il periodo si
                // spezza sulla catena di chi ha avuto la quota, e ogni pezzo si divide sui conduttori per conto suo.
                $pezzi = $this->catenaDeiPagatori((int) $r['anagrafica_id'], (int) $unita->id, $periodo);
                $importiPezzi = MoneyHelper::ripartisciPerQuote($importo, array_map(fn ($pz) => (float) $pz['periodo']->giorni(), $pezzi));
                foreach ($pezzi as $i => $pz) {
                    $this->dividiSuiConduttori((int) ($importiPezzi[$i] ?? 0), $pz['periodo'], $pz['nome'], $base, $partecipanti, $righeInquilino, $voci, $senza);
                }
            }

            // Il già versato sulla chiave del conduttore (decisione 17): la sua quota nelle rate ne è già al netto.
            foreach ($righe->where('tipo', 'netting')->filter(fn ($r) => isset($voci[(int) ($r['anagrafica_id'] ?? 0)])) as $r) {
                $voci[(int) $r['anagrafica_id']][] = [
                    'piano' => $piano->nome, 'piano_rate_id' => (int) $piano->id, 'conto_id' => (int) ($r['conto_id'] ?? 0),
                    'conto' => ($r['conto_nome'] ?? 'Voce') . ' — già versato', 'tabella_id' => 0, 'tabella' => null, 'millesimi' => null,
                    'quota_inquilino' => null, 'competenza' => null, 'nota' => null, 'importo' => (int) $r['importo'], 'giorni' => null, 'modo' => 'rate', 'pagato_da' => null,
                ];
                $totaleInquilino += (int) $r['importo'];
            }
        }

        $conduttori = collect($voci)->map(fn (array $v, int $anagraficaId) => $this->sezione($anagraficaId, $this->accorpa($v), $righeInquilino, $periodoEsercizio))
            ->sortBy(fn ($c) => ($c['periodo']['dal'] ?? '9999') . '|' . $c['nome'])->values()->all();
        $voceSenza = $this->accorpa($senza);
        $totaleSenza = (int) array_sum(array_column($voceSenza, 'importo'));

        return [
            'unita'         => ['id' => (int) $unita->id, 'etichetta' => $unita->etichetta_estesa],
            'esercizio'     => ['id' => (int) $esercizio->id, 'nome' => $esercizio->nome, 'dal' => $periodoEsercizio?->dal->toDateString(), 'al' => $periodoEsercizio?->al->toDateString()],
            'intestazione'  => $this->intestazione($pianiLetti, $esclusi),
            'piani'         => $pianiLetti,
            'piani_esclusi' => $esclusi,
            'conduttori'    => $conduttori,
            'senza_conduttore' => ['voci' => $voceSenza, 'totale' => $totaleSenza],
            'totale_inquilino' => $totaleInquilino,
            // R22: il totale del ruolo inquilino si legge diviso — ai conduttori, e ciò che resta a chi ha pagato.
            'totale_conduttori' => $totaleInquilino - $totaleSenza,
        ];
    }

    /**
     * Divide un importo pagato da `$pagatoDa` sul periodo dato fra i conduttori che oggi risultano in quei giorni (quota ×
     * giorni, regola del motore); i giorni senza nessun conduttore restano a chi ha pagato.
     */
    private function dividiSuiConduttori(int $importo, InsiemePeriodi $periodo, ?string $pagatoDa, array $base, Collection $partecipanti, Collection $righeInquilino, array &$voci, array &$senza): void
    {
        if ($importo === 0) {
            return;
        }
        if ($partecipanti->isEmpty()) {
            $senza[] = $base + ['importo' => $importo, 'giorni' => $periodo->giorni(), 'modo' => 'proprietario', 'pagato_da' => $pagatoDa];

            return;
        }
        $scoperti = $this->titolari->giorniScoperti($righeInquilino, $periodo)?->giorni() ?? 0;
        $coperti = $periodo->giorni() - $scoperti;
        $parti = MoneyHelper::ripartisciPerQuote($importo, ['conduttori' => (float) $coperti, 'nessuno' => (float) $scoperti]);
        $pesi = [];
        $giorniDi = [];
        foreach ($partecipanti as $p) {
            $g = $this->titolari->giorniDiTitolarita($p, $periodo, $righeInquilino);
            if ($g > 0) {
                $pesi[(int) $p->anagrafica_id] = ($pesi[(int) $p->anagrafica_id] ?? 0) + (float) $p->quota * $g;
                $giorniDi[(int) $p->anagrafica_id] = ($giorniDi[(int) $p->anagrafica_id] ?? 0) + $g;
            }
        }
        foreach ($pesi === [] ? [] : MoneyHelper::ripartisciPerQuote((int) ($parti['conduttori'] ?? 0), $pesi) as $anagraficaId => $cents) {
            $voci[(int) $anagraficaId][] = $base + ['importo' => (int) $cents, 'giorni' => $giorniDi[$anagraficaId], 'modo' => 'proprietario', 'pagato_da' => $pagatoDa];
        }
        $resto = (int) ($parti['nessuno'] ?? 0) + ($pesi === [] ? (int) ($parti['conduttori'] ?? 0) : 0);
        if ($resto !== 0) {
            $senza[] = $base + ['importo' => $resto, 'giorni' => $pesi === [] ? $periodo->giorni() : $scoperti, 'modo' => 'proprietario', 'pagato_da' => $pagatoDa];
        }
    }

    /**
     * R10 (Fase 1-bis): i pezzi del periodo per chi ha pagato davvero. Parte dal soggetto della riga (chi aveva la quota
     * alla generazione) e segue le vendite e le costituzioni d'usufrutto registrate sull'unità con decorrenza dentro il
     * periodo: prima della decorrenza ha pagato chi esce, dopo chi entra (le bozze gli passano o la coppia di conguaglio
     * lo riporta ai suoi giorni — decisioni 21 e 25). La vendita con riserva d'usufrutto (beta.38) non fa avanzare la
     * catena: le ordinarie le paga ancora chi vende, che resta usufruttuario (art. 1004 c.c.).
     *
     * @return list<array{nome: ?string, periodo: InsiemePeriodi}>
     */
    private function catenaDeiPagatori(int $anagraficaId, int $immobileId, InsiemePeriodi $periodo): array
    {
        $pezzi = [];
        $chi = $anagraficaId;
        $dal = $periodo->dal();
        $visti = [];
        while (! isset($visti[$chi])) {
            $visti[$chi] = true;
            $passaggio = Subentro::where('immobile_id', $immobileId)->whereIn('tipo_passaggio', ['vendita', 'usufrutto'])
                ->where('anagrafica_uscente_id', $chi)->whereNotNull('anagrafica_entrante_id')
                ->whereDate('decorrenza', '>', $dal->toDateString())->whereDate('decorrenza', '<=', $periodo->al()->toDateString())
                ->orderBy('decorrenza')->get()->first(fn (Subentro $p) => ! $p->riservaUsufrutto());
            if ($passaggio === null) {
                break;
            }
            $pezzo = $periodo->intersezione(new PeriodoCompetenza($dal, $passaggio->decorrenza->toImmutable()->subDay()));
            if ($pezzo !== null) {
                $pezzi[] = ['nome' => Anagrafica::whereKey($chi)->value('nome'), 'periodo' => $pezzo];
            }
            $chi = (int) $passaggio->anagrafica_entrante_id;
            $dal = $passaggio->decorrenza->toImmutable();
        }
        $ultimo = $periodo->intersezione(new PeriodoCompetenza($dal, $periodo->al()));
        if ($ultimo !== null) {
            $pezzi[] = ['nome' => Anagrafica::whereKey($chi)->value('nome'), 'periodo' => $ultimo];
        }

        return $pezzi;
    }

    /**
     * R2: nel ramo ricostruito le persone del riparto devono essere quelle che hanno le rate del piano sull'unità (senza
     * le quote di soli saldi lasciate a chi esce da una vendita), nei due versi.
     */
    private function stessePersone(Collection $riparto, int $pianoRateId, int $immobileId): bool
    {
        $delRiparto = $riparto->pluck('anagrafica_id')->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        $delleRate = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
            ->where('rate.piano_rate_id', $pianoRateId)->where('rate_quote.immobile_id', $immobileId)
            ->where(fn ($q) => $q->whereNull('rate_quote.tipo')->orWhere('rate_quote.tipo', '!=', 'saldo_iniziale'))
            ->distinct()->pluck('rate_quote.anagrafica_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        return $delRiparto === $delleRate;
    }

    /**
     * R6: la competenza di una riga — i tratti del capitolo quando il gradino è «capitolo» (conto, poi radice: la regola
     * del motore e del conguaglio), altrimenti gli estremi congelati, altrimenti l'esercizio. Un gradino «capitolo» senza
     * più tratti non ripiega in silenzio sugli estremi: la riga resta senza competenza.
     *
     * @param array<int, InsiemePeriodi> $trattiConto
     */
    private function competenzaDellaRiga(array $r, array $trattiConto, ?InsiemePeriodi $esercizio): ?InsiemePeriodi
    {
        if (($r['gradino_competenza'] ?? null) === 'capitolo') {
            return $trattiConto[(int) ($r['conto_id'] ?? 0)] ?? $trattiConto[(int) ($r['conto_radice_id'] ?? 0)] ?? null;
        }
        if (! empty($r['competenza_dal']) && ! empty($r['competenza_al'])) {
            return InsiemePeriodi::uno(new PeriodoCompetenza($r['competenza_dal'], $r['competenza_al']));
        }

        return $esercizio;
    }

    /**
     * Cosa dice il contratto sul rimborso delle spese, per regime (`subentri.regime_contratto`). L'art. 9 L. 392/1978 si
     * cita **solo** per la locazione abitativa e per quella a uso diverso (art. 27, per il rinvio dell'art. 41): mai su un
     * box locato a un privato, mai sul comodato (§3.2 e §3.8 delle pertinenze). Senza regime registrato non si tira a
     * indovinare: la frase dice le due strade.
     */
    public function notaContratto(?string $regime): string
    {
        $articolo9 = 'Prima di pagare il conduttore ha diritto all\'indicazione specifica delle spese con i criteri di ripartizione — è questo prospetto — e a prendere visione dei documenti giustificativi; il pagamento è dovuto entro due mesi dalla ricezione della richiesta del locatore (art. 9 co. 3 L. 392/1978).';

        return match ($regime) {
            'abitativo'   => 'Locazione abitativa (L. 431/1998): gli oneri accessori seguono l\'art. 9 L. 392/1978, salvo patto contrario. ' . $articolo9,
            'uso_diverso' => 'Locazione a uso diverso dall\'abitativo (art. 27 L. 392/1978): per il rinvio dell\'art. 41 gli oneri accessori seguono l\'art. 9 L. 392/1978, salvo patto contrario. ' . $articolo9,
            'atipica'     => 'Locazione atipica (artt. 1571 ss. c.c.): il rimborso delle spese si regola nel contratto, e l\'art. 9 L. 392/1978 non si applica. Il prospetto elenca le voci che il riparto pone a carico dell\'inquilino, non un obbligo di legge.',
            'comodato'    => 'Comodato (art. 1808 c.c.): il comodatario non è un conduttore e l\'art. 9 L. 392/1978 non si applica; quali spese rimborsare si regola fra le parti.',
            default       => 'Il regime del contratto non è registrato nel programma: se è una locazione abitativa o a uso diverso (art. 27 L. 392/1978), gli oneri accessori seguono l\'art. 9 L. 392/1978 — indicazione specifica delle spese, giustificativi, pagamento entro due mesi dalla ricezione della richiesta del locatore; se è una locazione atipica o un comodato, vale ciò che dice il contratto.',
        };
    }

    /**
     * I piani rate dell'esercizio: `esercizio_id` (migrazione 9), o dedotto per i piani che non lo ricordano. Entrano gli
     * approvati; quelli in bozza si nominano e restano fuori — non sono ancora deliberati.
     *
     * @return array{0: list<PianoRate>, 1: list<array{nome: string, motivo: string}>}
     */
    private function pianiDellEsercizio(Immobile $unita, Esercizio $esercizio): array
    {
        $candidati = PianoRate::with('gestione')->where('condominio_id', $unita->condominio_id)
            ->where(fn ($q) => $q->where('esercizio_id', $esercizio->id)->orWhereNull('esercizio_id'))
            ->orderBy('id')->get();
        $gestioniDellEsercizio = DB::table('esercizio_gestione')->where('esercizio_id', $esercizio->id)->pluck('gestione_id')->map(fn ($id) => (int) $id)->flip()->all();

        $inclusi = [];
        $esclusi = [];
        $piani = [];
        foreach ($candidati as $p) {
            if ((int) $p->esercizio_id === (int) $esercizio->id) {
                $piani[] = $p;
                continue;
            }
            // Un piano che non ricorda l'esercizio (anteriore alla beta.31) si attribuisce dalla data di creazione. R13
            // (Fase 1-bis): lo si dice — in testa se entra qui, fra gli esclusi se è di una gestione di questo esercizio
            // ma la data di creazione lo porta in un altro.
            $dedotto = $this->competenza->esercizioDelPiano($p);
            if ((int) ($dedotto?->id ?? 0) === (int) $esercizio->id) {
                $piani[] = $p;
            } elseif (isset($gestioniDellEsercizio[(int) $p->gestione_id]) && $dedotto !== null) {
                $esclusi[] = ['nome' => $p->nome, 'motivo' => sprintf('attribuito all\'esercizio «%s» dalla data di creazione: il piano non ricorda il suo esercizio (versione precedente)', $dedotto->nome)];
            }
        }
        foreach ($piani as $p) {
            // `stato` ha il cast a `StatoPianoRate`: il confronto con la stringa sarebbe sempre falso.
            if (($p->stato instanceof \BackedEnum ? $p->stato->value : $p->stato) === 'bozza') {
                $esclusi[] = ['nome' => $p->nome, 'motivo' => 'in bozza: non ancora approvato'];
                continue;
            }
            $inclusi[] = $p;
        }

        return [$inclusi, $esclusi];
    }

    /** Il periodo della riga: la sua competenza ∩ il suo tratto di titolarità (quando c'è). */
    private function periodoDellaRiga(array $r, ?InsiemePeriodi $competenza): ?InsiemePeriodi
    {
        if ($competenza === null) {
            return null;
        }
        if (! empty($r['titolarita_dal']) && ! empty($r['titolarita_al'])) {
            return $competenza->intersezione(new PeriodoCompetenza($r['titolarita_dal'], $r['titolarita_al']));
        }

        return $competenza;
    }

    /**
     * Una riga per (piano, voce, tabella, modo, chi ha pagato): due tratti della stessa voce si sommano, i giorni pure.
     *
     * @param list<array<string,mixed>> $voci
     * @return list<array<string,mixed>>
     */
    private function accorpa(array $voci): array
    {
        return collect($voci)
            // R20: la competenza nella chiave — due righe della stessa voce con competenze diverse restano due.
            ->groupBy(fn ($v) => $v['piano_rate_id'] . '|' . $v['conto_id'] . '|' . $v['tabella_id'] . '|' . $v['modo'] . '|' . $v['pagato_da'] . '|' . $v['conto'] . '|' . json_encode($v['competenza']) . '|' . ($v['nota'] ?? ''))
            ->map(function (Collection $g) {
                $prima = $g->first();
                $giorni = $g->pluck('giorni')->filter(fn ($x) => $x !== null);

                return array_replace($prima, [
                    'importo' => (int) $g->sum('importo'),
                    'importo_formattato' => MoneyHelper::format((int) $g->sum('importo')),
                    'giorni' => $giorni->isEmpty() ? null : (int) $giorni->sum(),
                ]);
            })
            ->sortBy(fn ($v) => sprintf('%010d|%010d|%s', $v['piano_rate_id'], $v['conto_id'], $v['modo']))
            ->values()->all();
    }

    /**
     * La sezione di un conduttore: le sue voci, i totali per modo, il periodo di conduzione nell'esercizio (dalla
     * titolarità di oggi con la regola del motore, D7) e il regime del contratto (dal passaggio che ha aperto la riga).
     *
     * @param list<array<string,mixed>> $voci
     */
    private function sezione(int $anagraficaId, array $voci, Collection $righeInquilino, ?PeriodoCompetenza $esercizio): array
    {
        $sue = $righeInquilino->where('anagrafica_id', $anagraficaId)->values();
        $periodo = null;
        $tratti = collect();
        $inVigore = collect();
        if ($esercizio !== null && $sue->isNotEmpty()) {
            $inVigore = $sue->filter(fn ($r) => $this->titolari->trattoEffettivo($r, $esercizio, $righeInquilino) !== null)->values();
            $tratti = $inVigore->map(fn ($r) => $this->titolari->trattoEffettivo($r, $esercizio, $righeInquilino))->sortBy(fn ($t) => $t->dal->toDateString())->values();
            if ($tratti->isNotEmpty()) {
                $periodo = ['dal' => $tratti->first()->dal->toDateString(), 'al' => $tratti->max(fn ($t) => $t->al->toDateString())];
            }
        }
        // R19 (Fase 1-bis): il regime dei contratti in vigore nell'esercizio; se sono più d'uno e diversi, nessuno —
        // la nota diventa condizionale invece di scegliere il più recente.
        $regimi = $inVigore->isEmpty() ? collect() : Subentro::whereIn('riga_entrante_id', $inVigore->pluck('id'))
            ->whereIn('tipo_passaggio', ['inizio_locazione', 'fine_locazione'])->pluck('regime_contratto')->unique()->values();
        $regime = $regimi->count() === 1 ? $regimi->first() : null;
        $totale = (int) array_sum(array_column($voci, 'importo'));
        $rate = (int) array_sum(array_map(fn ($v) => $v['modo'] === 'rate' ? $v['importo'] : 0, $voci));

        return [
            'anagrafica_id' => $anagraficaId,
            'nome'          => Anagrafica::whereKey($anagraficaId)->value('nome') ?? '—',
            'periodo'       => $periodo,
            // R19: i tratti di conduzione uno per uno — due contratti con un vuoto in mezzo non sono un periodo solo.
            'tratti'        => $tratti->map(fn ($t) => ['dal' => $t->dal->toDateString(), 'al' => $t->al->toDateString()])->all(),
            'regime'        => $regime,
            // R15: il comodatario non è un conduttore (art. 1808 c.c.), e la sezione lo chiama per nome.
            'ruolo'         => $regime === 'comodato' ? 'Comodatario' : 'Conduttore',
            'nota_contratto' => $this->notaContratto($regime),
            'voci'          => $voci,
            'totale'        => $totale,
            'totale_formattato' => MoneyHelper::format($totale),
            'nelle_sue_rate' => $rate,
            'nelle_sue_rate_formattato' => MoneyHelper::format($rate),
            'da_rimborsare' => $totale - $rate,
            'da_rimborsare_formattato' => MoneyHelper::format($totale - $rate),
        ];
    }

    /**
     * Le frasi in testa: cosa il documento non è, su quali numeri si regge, come divide, come si legge.
     *
     * @param list<array{nome: string, fonte: string}> $piani
     * @param list<array{nome: string, motivo: string}> $esclusi
     * @return list<string>
     */
    private function intestazione(array $piani, array $esclusi): array
    {
        $nomi = implode('», «', array_column($piani, 'nome'));
        $frasi = [
            // R21 (Fase 1-bis): la regola come norma, con le fonti di §3.1 delle pertinenze.
            'Non è un riparto e non è una richiesta di pagamento del condominio. Verso il condominio risponde il proprietario: l\'amministratore può esigere i contributi solo dai condòmini, non dal conduttore, anche quando le rate sono intestate a lui (artt. 1123 c.c. e 63 disp. att. c.c.; Cass. 17039/2007). È il prospetto con cui il proprietario regola con il conduttore le spese che il riparto pone a carico dell\'inquilino.',
            $piani === []
                ? 'Nessun piano rate approvato in questo esercizio: il prospetto è vuoto.'
                : sprintf('Gli importi sono quelli del preventivo, dal piano rate approvato («%s»): il conguaglio definitivo si fa sul rendiconto approvato dell\'esercizio.', $nomi),
            'Le spese si dividono per giorni di conduzione, con la stessa regola del riparto, e non per consumi: dove c\'è la contabilizzazione del calore il risultato è un altro.',
            'Accanto a ogni voce: «nelle sue rate» se la voce è già intestata al conduttore nelle rate — un pagamento diretto, che non lo rende debitore del condominio (Cass. 19650/2006); «pagata da …» se l\'ha pagata un titolare perché nei giorni del piano nessun inquilino era registrato — è la parte da rimborsare.',
        ];
        foreach ($piani as $p) {
            if (! empty($p['dedotto'])) {
                // R13: un piano che non ricorda l'esercizio è attribuito dalla data di creazione, e si dice.
                $frasi[] = sprintf('Il piano «%s» non ricorda l\'esercizio con cui è stato generato (versione precedente): è attribuito a questo esercizio dalla data di creazione.', $p['nome']);
            }
            if ($p['fonte'] === DettaglioRiparto::RICOSTRUITO) {
                $frasi[] = sprintf('Il piano «%s» è stato generato da una versione precedente e non ha il riparto registrato: gli importi sono ricostruiti dai dati di oggi.', $p['nome']);
            }
        }
        foreach ($esclusi as $e) {
            $frasi[] = sprintf('Il piano «%s» non compare: %s.', $e['nome'], $e['motivo']);
        }

        return $frasi;
    }
}
