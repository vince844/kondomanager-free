<?php

namespace App\Services\Riparto;

use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Services\CalcoloQuoteService;
use App\Services\Subentro\ChiPagaAlPostoDellInquilino;
use App\Support\InsiemePeriodi;
use App\Support\PeriodoCompetenza;
use App\Support\ProRataTemporis;
use Carbon\CarbonImmutable;
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
 * Dalla 1.11.0-beta.50 (decisioni 32, 77 e 79) il prospetto legge anche la fine di una locazione senza un nuovo inquilino e il cambio
 * d'inquilino con «Non scriverlo»: con «Scrivi il conguaglio» i giorni dopo l'uscita li ha pagati chi sta al posto dell'inquilino; con
 * «Non scriverlo», o senza la scelta (le fini registrate prima), restano nelle rate di chi esce e gli si restituiscono (strada A′); con
 * un coinquilino che resta restano nelle rate di chi esce, e chi li paga lo decidono le parti. Dopo «Annulla il conguaglio» le parti
 * hanno regolato fra loro, e il prospetto non li tocca (decisioni 43 e 46).
 *
 * Il già versato «dell'unità» (decisione 17, D8) sconta anche le righe dell'inquilino, come nel conguaglio: le righe «nelle sue rate»
 * e quelle di ripiego si dividono al netto della loro parte, gruppo per gruppo (1.11.0-beta.47). I giorni di una voce che somma pezzi
 * della stessa persona sono l'unione dei loro tratti, non la somma.
 *
 * La **quota inquilino** di una voce si legge dagli importi congelati (parte inquilino / tutte le righe della stessa voce
 * e tabella sull'unità), non da `conto_tabella_ripartizioni`, che si può modificare dopo la generazione: è l'istantanea
 * che §3.3 delle pertinenze chiede. Nessuna data di mora: i due mesi decorrono dalla ricezione della richiesta del
 * locatore, che il programma non conosce.
 */
final class ProspettoOneriAccessori
{
    /** @var array<string, list<array{nome: ?string, periodo: InsiemePeriodi}>> le catene dei pagatori di un calcolo, per chiave */
    private array $catene = [];

    /** @var array<string, mixed> 1.11.0-beta.50: l'uscita di una fine e chi ha pagato dopo, per chiave: la catena li chiede per ogni voce */
    private array $uscite = [];

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
        $this->catene = [];
        $this->uscite = [];

        // La titolarità di oggi della coppia (unità, inquilino), con la regola del motore: solo le righe attive (R14,
        // Fase 1-bis — una riga spenta non fa da predecessore). Serve a dividere i ripieghi sui giorni di conduzione.
        $righeInquilino = $this->titolari->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $unita->id)->where('tipologia', 'inquilino'))
            ->orderBy('data_inizio')->get();
        $partecipanti = $righeInquilino->filter(fn ($r) => (float) $r->quota > 0)->values();

        $voci = [];      // [anagrafica_id => list<voce>]
        $senza = [];     // list<voce>
        $pianiLetti = [];

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

            $base = fn (array $r, ?InsiemePeriodi $competenza) => [
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
            $vociDelInquilino = $riparto->where('ruolo_richiesto', 'inquilino')->values();
            [$nelleRate, $ripieghi] = $vociDelInquilino->partition(fn ($r) => ($r['ruolo_risolto'] ?? null) === 'inquilino');

            // Le righe «nelle sue rate» della stessa voce e dello stesso tratto, su più tabelle, si dividono insieme: il conguaglio
            // le somma e arrotonda una volta sola (Fase 1-bis della .47; DV1, decisione 59), e il prospetto fa lo stesso.
            $gruppiRate = $nelleRate->groupBy(fn ($r) => implode('|', [$r['conto_id'] ?? 0, $r['anagrafica_id'] ?? 0, $r['titolarita_dal'] ?? '', $r['titolarita_al'] ?? '',
                $r['competenza_dal'] ?? '', $r['competenza_al'] ?? '', $r['gradino_competenza'] ?? '']));
            // DL4, DL5: sull'unità mista il ripiego della stessa persona sta in due righe gemelle (usufruttuario e proprietario
            // pieno, decisione 31.1) con lo stesso tratto e gli stessi giorni: è una parte sola dell'unità e si divide una volta —
            // i giorni non si contano due volte, e il centesimo si arrotonda una volta, come la parte di chi entra nel conguaglio
            // (DV1, decisione 59). Anche senza il tratto (l'unità vuota alla generazione): lì il periodo è la competenza, che sta
            // nella chiave; e sulle tabelle della voce, come le righe «nelle sue rate»: il conguaglio le somma (giri sulle correzioni).
            $gemelle = $ripieghi->values()->groupBy(fn ($r) => implode('|', ['ripiego', $r['conto_id'] ?? 0, $r['anagrafica_id'] ?? 0,
                $r['titolarita_dal'] ?? '', $r['titolarita_al'] ?? '', $r['giorni_titolarita'] ?? '', $r['competenza_dal'] ?? '', $r['competenza_al'] ?? '', $r['gradino_competenza'] ?? '']));
            // Terzo giro sulle correzioni: dentro il gruppo si conta come il conguaglio, una riga per ruolo con le tabelle sommate (la sua
            // chiave ha il ruolo e non la tabella); le righe per tabella restano solo per ripartire le voci fra le tabelle (`perTabella`).
            $gemelleIntere = $gemelle;
            $gemelle = $gemelle->map(fn (Collection $g) => $g->values()->groupBy(fn ($r) => (string) ($r['ruolo_risolto'] ?? ''))
                ->map(fn (Collection $perRuolo) => array_replace($perRuolo->first(), ['importo' => (int) $perRuolo->sum('importo')]))->values());

            // D8 (decisione 17): il già versato «dell'unità» sconta anche i giorni di chi entra, come nel conguaglio (`ConguaglioPassaggio`,
            // `$lordo - $nu`), e anche quelli che un conduttore registrato dopo rimborsa a chi ha pagato il ripiego; quello «della persona»
            // resta a chi lo ha versato. Si attribuisce ai gruppi di righe della persona sulla voce, come il conguaglio.
            [$nuRate, $nuGemelle] = $this->nettingDellUnita($righe, $riparto, $gruppiRate, $gemelle);
            $spostato = [];
            foreach ($gruppiRate as $k => $gruppo) {
                $this->dividiLeRate($piano, (int) $unita->id, $gruppo->values(), (int) ($nuRate[$k] ?? 0), $trattiConto, $esercizioInsieme, $base, $voci, $spostato, $senza, $partecipanti, $righeInquilino);
            }

            foreach ($gemelle as $k => $gruppo) {
                $gruppo = $gruppo->values();
                // Ogni riga al netto della sua parte del già versato «dell'unità», come la divide il conguaglio (una parte per riga).
                $nuRiga = $nuGemelle[$k] ?? [];
                $nuGruppo = (int) array_sum($nuRiga);
                $netti = $gruppo->map(fn ($g, $i) => array_replace($g, ['importo' => (int) $g['importo'] - (int) ($nuRiga[$i] ?? 0)]));
                if ($nuGruppo !== 0) {
                    $chiave = (int) ($gruppo->first()['anagrafica_id'] ?? 0) . '|' . (int) ($gruppo->first()['conto_id'] ?? 0);
                    $spostato[$chiave] = ($spostato[$chiave] ?? 0) + $nuGruppo;
                }
                $r = array_replace($gruppo->first(), ['importo' => (int) $netti->sum('importo')]);
                $importo = (int) $r['importo'];
                $competenza = $this->competenzaDellaRiga($r, $trattiConto, $esercizioInsieme);
                $baseRiga = $base($r, $competenza);
                $perTabella = fn (array $lista) => $this->perTabella($lista, $gemelleIntere[$k]->values(), $base, $competenza);

                // Ripiego: pagata da chi c'era nei giorni senza inquilino. R5: il tratto congelato può essere l'estensione
                // di più buchi — si ricostruiscono i giorni veri togliendo quelli delle righe risolte sul ruolo richiesto (l'inquilino),
                // non quelli delle altre righe di ripiego (1.11.0-beta.47), o la riga non si divide.
                $periodo = $this->periodoDellaRiga($r, $competenza);
                if ($periodo !== null && ! empty($r['titolarita_dal'])) {
                    $periodo = PeriodoDellaRiga::senzaGliAltri($periodo, $r, $riparto);
                }
                $pagatoDa = Anagrafica::whereKey($r['anagrafica_id'])->value('nome');
                if ($periodo === null) {
                    array_push($senza, ...$perTabella([$baseRiga + ['importo' => $importo, 'giorni' => $r['giorni_titolarita'] ?? null, 'modo' => 'proprietario', 'pagato_da' => $pagatoDa,
                        'nota' => 'i giorni di questa voce non si ricostruiscono dal riparto registrato: non si divide']]));
                    continue;
                }

                // R10: chi ha pagato cambia con le vendite (e con l'usufrutto) registrate dopo la generazione: il periodo si
                // spezza sulla catena di chi ha avuto la quota, e ogni pezzo si divide sui conduttori per conto suo. Dalla Fase 1-bis
                // della .47 la catena segue il ruolo della riga: sull'unità mista chi vende la metà piena resta usufruttuario
                // dell'altra, e la gemella dell'usufruttuario non passa a chi compra.
                $catene = $gruppo->map(fn ($g) => $this->catenaDeiPagatori($piano, (int) $r['anagrafica_id'], (int) $unita->id, $periodo, $g['ruolo_risolto'] ?? null))->values();
                $firma = fn (array $pezzi) => json_encode(array_map(fn ($pz) => [$pz['nome'], $pz['periodo']->toArray()], $pezzi));
                $dividi = function (Collection $righeDelGruppo) use ($catene, $firma, $periodo, $pagatoDa, $baseRiga, $partecipanti, $righeInquilino): array {
                    $v = [];
                    $sc = [];
                    if ($catene->map($firma)->unique()->count() === 1) {
                        $this->dividiIPezzi((int) $righeDelGruppo->sum('importo'), $catene->first(), $baseRiga, $partecipanti, $righeInquilino, $v, $sc);
                    } else {
                        $this->dividiLeGemelle($righeDelGruppo, $catene->all(), (int) $righeDelGruppo->sum('importo'), $periodo, $pagatoDa, $baseRiga, $partecipanti, $righeInquilino, $v, $sc);
                    }

                    return [$v, $sc];
                };
                [$vociNette, $senzaNette] = $dividi($netti);
                // Giro sulle correzioni: al conduttore la voce al lordo, e accanto la riga del già versato «dell'unità» che la sconta,
                // come per chi entra con il conguaglio (art. 9 L. 392/1978: le spese, e i criteri). I giorni senza conduttore al netto.
                [$vociLorde] = $nuGruppo === 0 ? [$vociNette] : $dividi($gruppo);
                foreach ($vociLorde as $anagraficaId => $lista) {
                    $voci[$anagraficaId] = [...($voci[$anagraficaId] ?? []), ...$perTabella($lista)];
                    if ($nuGruppo === 0) {
                        continue;
                    }
                    $netto = collect($vociNette[$anagraficaId] ?? [])->groupBy(fn ($v) => (string) ($v['pagato_da'] ?? ''))->map(fn ($g) => (int) $g->sum('importo'));
                    foreach (collect($lista)->groupBy(fn ($v) => (string) ($v['pagato_da'] ?? '')) as $chi => $g) {
                        $versato = (int) $g->sum('importo') - (int) ($netto[$chi] ?? 0);
                        if ($versato !== 0) {
                            $voci[$anagraficaId][] = [
                                'piano' => $piano->nome, 'piano_rate_id' => (int) $piano->id, 'conto_id' => (int) ($r['conto_id'] ?? 0),
                                'conto' => ($r['conto_nome'] ?? 'Voce') . ' — già versato', 'tabella_id' => 0, 'tabella' => null, 'millesimi' => null,
                                'quota_inquilino' => null, 'competenza' => null, 'nota' => null, 'importo' => -$versato, 'giorni' => null, 'tratti' => null,
                                'modo' => 'proprietario', 'pagato_da' => $g->first()['pagato_da'] ?? null,
                            ];
                        }
                    }
                }
                array_push($senza, ...$perTabella($senzaNette));
            }

            // Il già versato sulla chiave del conduttore (decisione 17): la sua quota nelle rate ne è già al netto. La parte «dell'unità»
            // dei giorni di chi è entrato con il conguaglio è già scritta accanto a lui (`dividiLeRate`), e quella dei ripieghi che la
            // persona ha pagato è già tolta da loro: qui non si conta una seconda volta.
            foreach ($righe->where('tipo', 'netting')->groupBy(fn ($r) => (int) ($r['anagrafica_id'] ?? 0) . '|' . (int) ($r['conto_id'] ?? 0)) as $k => $g) {
                $anagraficaId = (int) explode('|', (string) $k)[0];
                if (! isset($voci[$anagraficaId])) {
                    continue;
                }
                $primo = $g->first();
                $voci[$anagraficaId][] = [
                    'piano' => $piano->nome, 'piano_rate_id' => (int) $piano->id, 'conto_id' => (int) ($primo['conto_id'] ?? 0),
                    'conto' => ($primo['conto_nome'] ?? 'Voce') . ' — già versato', 'tabella_id' => 0, 'tabella' => null, 'millesimi' => null,
                    'quota_inquilino' => null, 'competenza' => null, 'nota' => null, 'importo' => (int) $g->sum('importo') + (int) ($spostato[$k] ?? 0), 'giorni' => null, 'modo' => 'rate', 'pagato_da' => null,
                ];
            }
        }
        // Il totale del ruolo inquilino è quello che il prospetto divide: le voci dei conduttori, già versato compreso, e quelle senza
        // conduttore (giro sulle correzioni della .47: il già versato «dell'unità» sui ripieghi entra qui).
        $totaleInquilino = (int) array_sum(array_map(fn (array $v) => array_sum(array_column($v, 'importo')), $voci)) + (int) array_sum(array_column($senza, 'importo'));

        $conduttori = collect($voci)->map(fn (array $v, int $anagraficaId) => $this->sezione($anagraficaId, $this->accorpa($v), $righeInquilino, $periodoEsercizio))
            ->sortBy(fn ($c) => ($c['periodo']['dal'] ?? '9999') . '|' . $c['nome'])->values()->all();
        $voceSenza = $this->accorpa($senza);
        $totaleSenza = (int) array_sum(array_column($voceSenza, 'importo'));
        $frasiDellUscita = $this->frasiDellUscita($voci, $senza, (int) $unita->id, count($pianiLetti) > 1);
        // Il «pagata da …» della fine di una locazione: chi è uscito (strada A′) o chi paga al posto dell'inquilino (strada B).
        $pagateAllaFine = collect([...array_merge([], ...array_values($voci)), ...$senza])->contains(fn ($v) => in_array($v['strada'] ?? null, ['A', 'B'], true) && ! empty($v['pagato_da']));

        return [
            'unita'         => ['id' => (int) $unita->id, 'etichetta' => $unita->etichetta_estesa],
            'esercizio'     => ['id' => (int) $esercizio->id, 'nome' => $esercizio->nome, 'dal' => $periodoEsercizio?->dal->toDateString(), 'al' => $periodoEsercizio?->al->toDateString()],
            'intestazione'  => [...$this->intestazione($pianiLetti, $esclusi, $pagateAllaFine), ...$frasiDellUscita],
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
     * D8 (decisione 17): la parte «dell'unità» del già versato di ogni persona su ogni voce, gruppo per gruppo come `ConguaglioPassaggio`
     * (Fase 1-bis e giro sulle correzioni della .47). Il conguaglio attribuisce a ogni gruppo di righe della persona sulla voce la sua
     * parte del già versato, in proporzione ai lordi, meno la sua parte di quello «della persona», che resta a chi l'ha versato. I gruppi
     * sono tutte le righe della persona sulla voce: quelle «nelle sue rate», le gemelle di ripiego e le righe di un altro ruolo (la parte
     * «Proprietario», che il prospetto non mostra ma che prende la sua parte del versato).
     *
     * @param Collection<string, Collection<int, array<string,mixed>>> $gruppiRate
     * @param Collection<string, Collection<int, array<string,mixed>>> $gemelle
     * @return array{0: array<string, int>, 1: array<string, array<int, int>>} la parte, in centesimi positivi, per gruppo «nelle sue rate» e, per gruppo di gemelle, riga per riga
     */
    private function nettingDellUnita(Collection $righe, Collection $riparto, Collection $gruppiRate, Collection $gemelle): array
    {
        $diChi = fn (Collection $g) => (int) ($g->first()['anagrafica_id'] ?? 0) . '|' . (int) ($g->first()['conto_id'] ?? 0);
        $altre = $riparto->filter(fn ($r) => ($r['ruolo_richiesto'] ?? null) !== 'inquilino')
            ->groupBy(fn ($r) => implode('|', [$r['anagrafica_id'] ?? 0, $r['conto_id'] ?? 0, $r['ruolo_risolto'] ?? '', $r['titolarita_dal'] ?? '', $r['titolarita_al'] ?? '', $r['competenza_dal'] ?? '', $r['competenza_al'] ?? '']));
        $nuRate = [];
        $nuGemelle = [];
        foreach ($righe->where('tipo', 'netting')->groupBy(fn ($r) => (int) ($r['anagrafica_id'] ?? 0) . '|' . (int) ($r['conto_id'] ?? 0)) as $k => $g) {
            $tot = abs((int) $g->sum('importo'));
            $persona = min($tot, abs((int) $g->where('riga_descrizione', CalcoloQuoteService::NETTING_DELLA_PERSONA)->sum('importo')));
            $pesi = [];
            foreach ($gruppiRate as $kg => $gr) {
                if ($diChi($gr) === $k) {
                    $pesi['R' . $kg] = (float) abs((int) $gr->sum('importo'));
                }
            }
            foreach ($gemelle as $kg => $gr) {
                if ($diChi($gr) === $k) {
                    // Giri sulle correzioni: una parte per ruolo (le tabelle sommate), come il conguaglio, che fa delle gemelle due gruppi.
                    foreach ($gr->values() as $i => $riga) {
                        $pesi['G' . $kg . '#' . $i] = (float) abs((int) $riga['importo']);
                    }
                }
            }
            foreach ($altre as $kg => $gr) {
                if ($diChi($gr) === $k) {
                    $pesi['A' . $kg] = (float) abs((int) $gr->sum('importo'));
                }
            }
            if ($tot - $persona === 0 || $pesi === []) {
                continue;
            }
            $diTutto = MoneyHelper::ripartisciPerQuote($tot, $pesi);
            $dellaPersona = MoneyHelper::ripartisciPerQuote($persona, $pesi);
            foreach ($pesi as $kp => $_) {
                $parte = (int) ($diTutto[$kp] ?? 0) - (int) ($dellaPersona[$kp] ?? 0);
                if ($kp[0] === 'R') {
                    $nuRate[substr($kp, 1)] = $parte;
                } elseif ($kp[0] === 'G') {
                    [$kg, $i] = explode('#', substr($kp, 1));
                    $nuGemelle[$kg][(int) $i] = $parte;
                }
            }
        }

        return [$nuRate, $nuGemelle];
    }

    /**
     * Le righe «nelle sue rate» di una voce e di un tratto, sulle sue tabelle. DL1: un cambio d'inquilino registrato dopo la
     * generazione, con il conguaglio, ha dato i giorni dopo l'uscita a chi è entrato; la voce si spezza come la coppia: a chi esce
     * i suoi giorni, a chi entra i suoi, pagati con il conguaglio. Senza cambi la catena è un pezzo solo, e le righe restano com'erano.
     *
     * Come il conguaglio (Fase 1-bis della .47): la voce si divide una volta, sommata sulle tabelle, e il pezzo di chi entra si
     * riparte poi fra le tabelle con i resti maggiori (DV1, decisione 59); il già versato «dell'unità» si divide con gli stessi
     * pezzi, così la parte di chi entra al netto è la sua riga del conguaglio. `$spostato` dice, per persona e voce, quanto
     * già versato è passato a chi è entrato: la riga «già versato» di chi esce lo toglie.
     *
     * Decisioni 32, 77 e 79 (1.11.0-beta.50): anche la fine di una locazione senza un nuovo inquilino, e il cambio d'inquilino con «Non
     * scriverlo», spezzano la voce: il pezzo «uscita» di `catenaDeiConduttori` lo divide `dividiDopoLUscita`, con la strada della fine.
     *
     * @param Collection<int, array<string,mixed>> $gruppo
     * @param array<string, int> $spostato
     */
    private function dividiLeRate(PianoRate $piano, int $immobileId, Collection $gruppo, int $nettingDellUnita, array $trattiConto, ?InsiemePeriodi $esercizio, \Closure $base, array &$voci, array &$spostato, array &$senza, Collection $partecipanti, Collection $righeInquilino): void
    {
        $primo = $gruppo->first();
        $anagraficaId = (int) $primo['anagrafica_id'];
        $competenza = $this->competenzaDellaRiga($primo, $trattiConto, $esercizio);
        $periodo = $this->periodoDellaRiga($primo, $competenza);
        if ($periodo === null) {
            foreach ($gruppo as $r) {
                $giorni = $r['giorni_titolarita'] ?? null;
                $voci[$anagraficaId][] = $base($r, $competenza) + ['importo' => (int) $r['importo'], 'giorni' => $giorni !== null ? (int) $giorni : null, 'modo' => 'rate', 'pagato_da' => null];
            }

            return;
        }
        $importo = (int) $gruppo->sum('importo');
        $lordi = $this->catenaDeiConduttori($piano, $anagraficaId, $immobileId, $periodo, $importo);
        $netti = $nettingDellUnita === 0 ? $lordi : $this->catenaDeiConduttori($piano, $anagraficaId, $immobileId, $periodo, $importo - $nettingDellUnita);
        $pesi = $gruppo->map(fn ($r) => (float) abs((int) $r['importo']))->all();
        $parti = [];
        $partiNette = [];
        foreach ($lordi as $i => $pz) {
            if ($i > 0) {
                $parti[$i] = $gruppo->count() === 1 ? [array_key_first($pesi) => (int) $pz['importo']] : MoneyHelper::ripartisciPerQuote((int) $pz['importo'], $pesi);
                // Il pezzo dopo l'uscita al netto del già versato «dell'unità», come la coppia (D8).
                $partiNette[$i] = $gruppo->count() === 1 ? [array_key_first($pesi) => (int) $netti[$i]['importo']] : MoneyHelper::ripartisciPerQuote((int) $netti[$i]['importo'], $pesi);
            }
        }
        foreach ($gruppo as $j => $r) {
            foreach ($lordi as $i => $pz) {
                $suo = $i > 0 ? (int) $parti[$i][$j] : (int) $r['importo'] - (int) array_sum(array_map(fn ($p) => (int) $p[$j], $parti));
                if (isset($pz['uscita'])) {
                    $this->dividiDopoLUscita($pz, $r, $competenza, $suo, (int) $partiNette[$i][$j], $base, $partecipanti, $righeInquilino, $voci, $senza);
                    continue;
                }
                // Ultimo giro della .50: il pezzo prima dell'uscita senza giorni (la fine prima della voce) non è una voce.
                if ($pz['tratti'] === null && $suo === 0) {
                    continue;
                }
                $voci[$pz['anagrafica_id']][] = $base($r, $competenza) + ['importo' => $suo, 'giorni' => $pz['giorni'], 'tratti' => $pz['tratti'], 'modo' => $pz['modo'], 'pagato_da' => null, 'conguaglio_del' => $pz['conguaglio_del']];
            }
        }
        foreach ($lordi as $i => $pz) {
            if (isset($pz['uscita'])) {
                // Strada B: la parte del già versato «dell'unità» dei giorni dopo l'uscita è passata con la coppia, e la riga «già versato»
                // di chi esce la toglie. Strada A′ e coinquilino: resta sua (le rate sono sue), e la restituzione è già al netto.
                if ($pz['uscita']['strada'] === 'B') {
                    $chiave = $anagraficaId . '|' . (int) ($primo['conto_id'] ?? 0);
                    // R2: dei giorni che nessuno copriva il già versato resta a chi esce, come nella strada A′.
                    $scoperti = isset($pz['uscita']['scoperti']) ? self::comune($pz['tratti'], $pz['uscita']['scoperti']) : null;
                    $passato = $scoperti === null ? (int) $pz['importo'] - (int) $netti[$i]['importo']
                        : self::copertaEScoperta((int) $pz['importo'], $pz['tratti'], $scoperti)[0] - self::copertaEScoperta((int) $netti[$i]['importo'], $pz['tratti'], $scoperti)[0];
                    $spostato[$chiave] = ($spostato[$chiave] ?? 0) + $passato;
                }
                continue;
            }
            // Decisione 84.1: anche il pezzo «regolato fra le parti» (83.3) si porta la parte del già versato dei suoi giorni, come nel cambio
            // scritto: le due catene danno gli stessi numeri.
            $passa = $i > 0 ? (int) $pz['importo'] - (int) $netti[$i]['importo'] : 0;
            if ($passa === 0) {
                continue;
            }
            $voci[$pz['anagrafica_id']][] = [
                'piano' => $piano->nome, 'piano_rate_id' => (int) $piano->id, 'conto_id' => (int) ($primo['conto_id'] ?? 0),
                'conto' => ($primo['conto_nome'] ?? 'Voce') . ' — già versato', 'tabella_id' => 0, 'tabella' => null, 'millesimi' => null,
                'quota_inquilino' => null, 'competenza' => null, 'nota' => null, 'importo' => -$passa, 'giorni' => null, 'modo' => $pz['modo'], 'pagato_da' => null, 'conguaglio_del' => $pz['conguaglio_del'],
            ];
            $chiave = $anagraficaId . '|' . (int) ($primo['conto_id'] ?? 0);
            $spostato[$chiave] = ($spostato[$chiave] ?? 0) + $passa;
        }
    }

    /**
     * Decisioni 32, 77 e 79 (1.11.0-beta.50): il pezzo dei giorni dopo l'uscita di una riga «nelle sue rate», alla fine di una locazione
     * senza un nuovo inquilino o con «Non scriverlo» (`uscitaDellaLocazione`). `$lordo` è il pezzo della voce, `$netto` lo stesso al netto
     * del già versato «dell'unità», come la coppia (D8).
     * - Strada B: i giorni li ha pagati con la coppia chi sta al posto dell'inquilino; si dividono come un ripiego, pezzo per pezzo di chi
     *   ha pagato, sui conduttori registrati dopo, e il resto è «senza conduttore».
     * - Strada A′: sono nelle rate di chi esce. La sua sezione li dice, dopo l'uscita, e dice la parte da restituirgli; la parte si divide
     *   come un ripiego pagato da lui: il conduttore registrato dopo gliela rimborsa, i giorni senza conduttore gli si restituiscono fra le
     *   parti. Se il conduttore registrato dopo è lui stesso (il rinnovo, il rientro), i suoi giorni sono già nelle sue rate: la voce
     *   torna «nelle sue rate» per quei giorni, e né la restituzione né il rimborso li contano (le righe della stessa persona si fondono).
     * - Coinquilino che resta (decisione 79): i giorni restano nelle sue rate, con la nota che chi li paga lo decidono le parti; nessuna
     *   divisione sui conduttori, nessun nome.
     *
     * @param array<string, mixed> $pz il pezzo di `catenaDeiConduttori`, con `uscita`
     * @param array<string, mixed> $r la riga del riparto
     */
    private function dividiDopoLUscita(array $pz, array $r, ?InsiemePeriodi $competenza, int $lordo, int $netto, \Closure $base, Collection $partecipanti, Collection $righeInquilino, array &$voci, array &$senza): void
    {
        $u = $pz['uscita'];
        $chi = (int) $pz['anagrafica_id'];
        $dal = CarbonImmutable::parse($u['del'])->format('d/m/Y');
        $suaVoce = fn (int $importo, ?int $giorni, ?InsiemePeriodi $tratti, string $modo, array $altro = []) => array_replace($base($r, $competenza),
            ['importo' => $importo, 'giorni' => $giorni, 'tratti' => $tratti, 'modo' => $modo, 'pagato_da' => null, 'conguaglio_del' => $pz['conguaglio_del']], $altro);
        $dellUscita = ['strada' => $u['strada'], 'scelta' => $u['scelta'], 'uscita_del' => $u['del']];

        if ($u['strada'] === 'C') {
            $voci[$chi][] = $suaVoce($lordo, (int) $pz['giorni'], $pz['tratti'], $pz['modo'], $dellUscita
                + ['nota' => sprintf('giorni dopo l\'uscita, dal %s: resta un coinquilino, e chi li paga lo decidono le parti', $dal)]);

            return;
        }

        if ($u['strada'] === 'B') {
            // Fase 1-bis della .50 (R2): i giorni dopo l'uscita che nessuno copriva non sono nella coppia. Restano nelle rate di chi esce, come
            // la strada A′, con la loro dicitura; il resto lo hanno pagato con la coppia i pagatori.
            $scoperti = isset($u['scoperti']) ? self::comune($pz['tratti'], $u['scoperti']) : null;
            if ($scoperti !== null) {
                [$netto, $nettoScoperto] = self::copertaEScoperta($netto, $pz['tratti'], $scoperti);
                [, $lordoScoperto] = self::copertaEScoperta($lordo, $pz['tratti'], $scoperti);
                $this->restaAChiEsce(array_replace($pz, ['tratti' => $scoperti, 'giorni' => $scoperti->giorni()]), $r, $competenza, $lordoScoperto, $nettoScoperto, $base, $partecipanti, $righeInquilino, $voci, $senza,
                    ['strada' => 'A', 'scelta' => Subentro::CONGUAGLIO_SCRITTO, 'uscita_del' => $u['del']], $u['chi_esce'] ?? null);
            }
            $pagatori = $u['pagatori'] ?? [];
            $importi = MoneyHelper::ripartisciPerQuote($netto, array_map(fn (array $p) => (float) $p['periodo']->giorni(), $pagatori));
            foreach ($pagatori as $k => $p) {
                $v = [];
                $this->dividiSuiConduttori((int) ($importi[$k] ?? 0), $p['periodo'], $p['nome'], $base($r, $competenza) + $dellUscita + ['oppure' => self::oppure($p['fermate'])],
                    $partecipanti, $righeInquilino, $v, $senza);
                foreach ($v as $anagraficaId => $lista) {
                    $oppure = self::oppure($this->fermateDopoIlConduttore($p['fermate'], (int) $anagraficaId, (int) ($r['immobile_id'] ?? 0), $p['periodo']));
                    $voci[$anagraficaId] = [...($voci[$anagraficaId] ?? []), ...array_map(fn (array $x) => array_replace($x, ['oppure' => $oppure]), $lista)];
                }
            }

            return;
        }

        // Strada A′.
        $this->restaAChiEsce($pz, $r, $competenza, $lordo, $netto, $base, $partecipanti, $righeInquilino, $voci, $senza, $dellUscita, $u['pagato_da'] ?? null);
    }

    /**
     * La strada A′ di `dividiDopoLUscita`: i giorni dopo l'uscita sono nelle rate di chi esce. La sua sezione li dice, e dice la parte da
     * restituirgli; la parte si divide come un ripiego pagato da lui (`$pagatoDa`). Serve anche ai giorni della strada B che nessuno
     * copriva (R2), con la loro dicitura (`$dellUscita`).
     *
     * @param array<string, mixed> $pz il pezzo di `catenaDeiConduttori`
     * @param array<string, mixed> $r la riga del riparto
     * @param array{strada: string, scelta: ?string, uscita_del: string} $dellUscita
     */
    private function restaAChiEsce(array $pz, array $r, ?InsiemePeriodi $competenza, int $lordo, int $netto, \Closure $base, Collection $partecipanti, Collection $righeInquilino, array &$voci, array &$senza, array $dellUscita, ?string $pagatoDa): void
    {
        $chi = (int) $pz['anagrafica_id'];
        $dal = CarbonImmutable::parse($dellUscita['uscita_del'])->format('d/m/Y');
        $suaVoce = fn (int $importo, ?int $giorni, ?InsiemePeriodi $tratti, string $modo, array $altro = []) => array_replace($base($r, $competenza),
            ['importo' => $importo, 'giorni' => $giorni, 'tratti' => $tratti, 'modo' => $modo, 'pagato_da' => null, 'conguaglio_del' => $pz['conguaglio_del']], $altro);
        $v = [];
        $sc = [];
        $this->dividiSuiConduttori($netto, $pz['tratti'], $pagatoDa, $base($r, $competenza) + $dellUscita, $partecipanti, $righeInquilino, $v, $sc);
        $suoi = $v[$chi] ?? [];
        unset($v[$chi]);
        $suoiNetti = (int) array_sum(array_column($suoi, 'importo'));
        $suoiLordi = $suoiNetti;
        if ($suoi !== [] && $lordo !== $netto) {
            $vl = [];
            $scl = [];
            $this->dividiSuiConduttori($lordo, $pz['tratti'], $pagatoDa, $base($r, $competenza), $partecipanti, $righeInquilino, $vl, $scl);
            $suoiLordi = (int) array_sum(array_column($vl[$chi] ?? [], 'importo'));
        }
        $nota = ['nota' => sprintf('giorni dopo l\'uscita, dal %s', $dal)];
        if ($lordo - $suoiLordi !== 0) {
            $voci[$chi][] = $suaVoce($lordo - $suoiLordi, null, null, $pz['modo'], $dellUscita + $nota);
        }
        if ($netto - $suoiNetti !== 0) {
            $voci[$chi][] = $suaVoce(-($netto - $suoiNetti), null, null, 'restituzione', $dellUscita + $nota + ['a_chi' => $pagatoDa]);
        }
        // Il rinnovo e il rientro: i giorni di chi è tornato sono suoi, nelle sue rate, e la voce si somma a quella prima dell'uscita.
        if ($suoi !== []) {
            $voci[$chi][] = $suaVoce($suoiLordi, $suoi[0]['giorni'], $suoi[0]['tratti'], $pz['modo']);
        }
        foreach ($v as $anagraficaId => $lista) {
            $voci[$anagraficaId] = [...($voci[$anagraficaId] ?? []), ...$lista];
        }
        foreach ($sc as $x) {
            $senza[] = array_replace($x, ['modo' => 'da_restituire']);
        }
    }

    /**
     * R2: un importo dei giorni dopo l'uscita diviso fra i giorni che la coppia copre e quelli che nessuno copriva, per giorni, con i resti
     * maggiori; a pari resto il centesimo resta a chi esce, con i giorni scoperti, come nella coppia.
     *
     * @return array{0: int, 1: int} [coperta, scoperta]
     */
    private static function copertaEScoperta(int $importo, InsiemePeriodi $tratti, InsiemePeriodi $scoperti): array
    {
        $g = $scoperti->giorni();
        if ($g >= $tratti->giorni()) {
            return [0, $importo];
        }
        $parti = MoneyHelper::ripartisciPerQuote($importo, ['scoperta' => (float) $g, 'coperta' => (float) ($tratti->giorni() - $g)]);

        return [(int) $parti['coperta'], (int) $parti['scoperta']];
    }

    /** I giorni comuni a due insiemi di tratti, o null. */
    private static function comune(InsiemePeriodi $a, InsiemePeriodi $b): ?InsiemePeriodi
    {
        $comune = null;
        foreach ($b->periodi() as $t) {
            $x = $a->intersezione($t);
            if ($x !== null) {
                $comune = $comune === null ? $x : $comune->unione($x);
            }
        }

        return $comune;
    }

    /**
     * Giro sulle correzioni della .47: il ripiego di una voce su più tabelle si divide una volta, sommato, come lo divide il conguaglio
     * (DV1, decisione 59); poi ogni voce si riparte fra le tabelle in proporzione alle righe, con i resti maggiori, così il prospetto
     * mostra ancora la tabella e i millesimi di ciascuna.
     *
     * @param list<array<string,mixed>> $lista
     * @return list<array<string,mixed>>
     */
    private function perTabella(array $lista, Collection $righe, \Closure $base, ?InsiemePeriodi $competenza): array
    {
        $perTabella = $righe->groupBy(fn ($r) => (int) ($r['tabella_id'] ?? 0));
        if ($perTabella->count() <= 1) {
            return $lista;
        }
        $pesi = $perTabella->map(fn (Collection $g) => (float) abs((int) $g->sum('importo')))->all();
        $campi = array_flip(['tabella_id', 'tabella', 'millesimi', 'quota_inquilino']);
        $esito = [];
        foreach ($lista as $v) {
            foreach (MoneyHelper::ripartisciPerQuote((int) $v['importo'], $pesi) as $tabella => $parte) {
                if ((int) $parte !== 0) {
                    $esito[] = array_replace($v, array_intersect_key($base($perTabella[$tabella]->first(), $competenza), $campi), ['importo' => (int) $parte]);
                }
            }
        }

        return $esito;
    }

    /** R10: i pezzi di chi ha pagato, divisi per giorni, e ciascuno sui conduttori dei suoi giorni. */
    private function dividiIPezzi(int $importo, array $pezzi, array $base, Collection $partecipanti, Collection $righeInquilino, array &$voci, array &$senza): void
    {
        $importiPezzi = MoneyHelper::ripartisciPerQuote($importo, array_map(fn ($pz) => (float) $pz['periodo']->giorni(), $pezzi));
        foreach ($pezzi as $i => $pz) {
            $this->dividiSuiConduttori((int) ($importiPezzi[$i] ?? 0), $pz['periodo'], $pz['nome'], $base, $partecipanti, $righeInquilino, $voci, $senza);
        }
    }

    /**
     * Fase 1-bis della .47 (P9): le gemelle dell'unità mista le cui catene si separano — chi vende la metà piena resta usufruttuario
     * dell'altra. Ciascuna si segue con la sua catena, e chi l'ha pagata è il suo. Il centesimo si arrotonda una volta, sulla parte
     * unita di ogni conduttore e dei giorni senza conduttore (DL5), e lo scarto resta a chi ha pagato in origine: chi è entrato dopo
     * riceve quanto il suo conguaglio. Se nei giorni di un conduttore chi ha pagato in origine non c'è più (le due gemelle sono passate
     * ad altri), restano le parti separate, e lo scarto va a una voce di chi ha pagato in origine: prima nei giorni senza conduttore,
     * altrimenti nella parte di un conduttore. I giorni di chi compare in più gemelle sono gli stessi giorni, e non si sommano (DL4).
     *
     * @param Collection<int, array<string,mixed>> $gemelle
     * @param list<list<array{nome: ?string, periodo: InsiemePeriodi}>> $catene
     */
    private function dividiLeGemelle(Collection $gemelle, array $catene, int $importo, InsiemePeriodi $periodo, ?string $pagatoDa, array $base, Collection $partecipanti, Collection $righeInquilino, array &$voci, array &$senza): void
    {
        $separate = [];
        $senzaSeparate = [];
        foreach ($gemelle as $i => $g) {
            $this->dividiIPezzi((int) $g['importo'], $catene[$i], $base, $partecipanti, $righeInquilino, $separate, $senzaSeparate);
        }
        $unite = [];
        $senzaUnite = [];
        $this->dividiSuiConduttori($importo, $periodo, $pagatoDa, $base, $partecipanti, $righeInquilino, $unite, $senzaUnite);

        // Le voci della stessa persona da più gemelle: gli importi si sommano, i giorni si uniscono (sono gli stessi giorni, DL4).
        $perChi = function (array $pezzi): array {
            $uniti = [];
            foreach ($pezzi as $v) {
                $chi = (string) ($v['pagato_da'] ?? '');
                if (! isset($uniti[$chi])) {
                    $uniti[$chi] = $v;
                    continue;
                }
                $tratti = $uniti[$chi]['tratti'] instanceof InsiemePeriodi && $v['tratti'] instanceof InsiemePeriodi ? $uniti[$chi]['tratti']->unione($v['tratti']) : null;
                $uniti[$chi] = array_replace($uniti[$chi], ['importo' => $uniti[$chi]['importo'] + (int) $v['importo'], 'tratti' => $tratti,
                    'giorni' => $tratti?->giorni() ?? max((int) $uniti[$chi]['giorni'], (int) $v['giorni'])]);
            }

            return $uniti;
        };
        // Conduttore per conduttore, e i giorni senza conduttore come un conduttore: lo scarto fra la parte unita e la somma delle separate
        // va a chi ha pagato in origine, se in quei giorni è fra chi ha pagato; altrimenti restano le separate, che sono i conguagli di
        // chi è entrato. Il resto che ne viene va a una voce di chi ha pagato in origine: mai perso, mai a una persona nuova (giri sulle
        // correzioni della .47).
        $allaUnita = function (array $sep, array $uni) use ($perChi, $pagatoDa): array {
            $suoi = $perChi($sep);
            if ($suoi === []) {
                return $perChi($uni);
            }
            if (isset($suoi[(string) $pagatoDa])) {
                $suoi[(string) $pagatoDa]['importo'] += (int) array_sum(array_column($uni, 'importo')) - (int) array_sum(array_column($suoi, 'importo'));
            }

            return $suoi;
        };
        $perConduttore = [];
        $allocato = 0;
        foreach (array_unique([...array_keys($separate), ...array_keys($unite)]) as $anagraficaId) {
            $perConduttore[$anagraficaId] = $allaUnita($separate[$anagraficaId] ?? [], $unite[$anagraficaId] ?? []);
            $allocato += (int) array_sum(array_column($perConduttore[$anagraficaId], 'importo'));
        }
        $suoiSenza = $allaUnita($senzaSeparate, $senzaUnite);
        $resto = $importo - $allocato - (int) array_sum(array_column($suoiSenza, 'importo'));
        if ($resto !== 0) {
            $chi = (string) $pagatoDa;
            if (isset($suoiSenza[$chi])) {
                $suoiSenza[$chi]['importo'] += $resto;
            } else {
                foreach ($perConduttore as $anagraficaId => $suoi) {
                    if (isset($suoi[$chi])) {
                        $perConduttore[$anagraficaId][$chi]['importo'] += $resto;
                        break;
                    }
                }
            }
        }
        foreach ($perConduttore as $anagraficaId => $suoi) {
            foreach ($suoi as $v) {
                if ((int) $v['importo'] !== 0) {
                    $voci[$anagraficaId][] = $v;
                }
            }
        }
        foreach ($suoiSenza as $v) {
            if ((int) $v['importo'] !== 0) {
                $senza[] = $v;
            }
        }
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
            $senza[] = $base + ['importo' => $importo, 'giorni' => $periodo->giorni(), 'tratti' => $periodo, 'modo' => 'proprietario', 'pagato_da' => $pagatoDa];

            return;
        }
        $trattiScoperti = $this->titolari->giorniScoperti($righeInquilino, $periodo);
        $scoperti = $trattiScoperti?->giorni() ?? 0;
        $coperti = $periodo->giorni() - $scoperti;
        $parti = MoneyHelper::ripartisciPerQuote($importo, ['conduttori' => (float) $coperti, 'nessuno' => (float) $scoperti]);
        $pesi = [];
        $giorniDi = [];
        // I giorni di ciascuno come insieme, oltre al conto: la voce che somma pezzi della stessa persona li unisce (giro sulle correzioni).
        $trattiDi = [];
        foreach ($partecipanti as $p) {
            $g = $this->titolari->giorniDiTitolarita($p, $periodo, $righeInquilino);
            if ($g > 0) {
                $id = (int) $p->anagrafica_id;
                $pesi[$id] = ($pesi[$id] ?? 0) + (float) $p->quota * $g;
                $giorniDi[$id] = ($giorniDi[$id] ?? 0) + $g;
                $tratto = $this->titolari->trattoEffettivo($p, $periodo, $righeInquilino);
                $suoi = $tratto !== null ? $periodo->intersezione($tratto) : null;
                if ($suoi !== null) {
                    $trattiDi[$id] = isset($trattiDi[$id]) ? $trattiDi[$id]->unione($suoi) : $suoi;
                }
            }
        }
        foreach ($pesi === [] ? [] : MoneyHelper::ripartisciPerQuote((int) ($parti['conduttori'] ?? 0), $pesi) as $anagraficaId => $cents) {
            $voci[(int) $anagraficaId][] = $base + ['importo' => (int) $cents, 'giorni' => $giorniDi[$anagraficaId], 'tratti' => $trattiDi[$anagraficaId] ?? null, 'modo' => 'proprietario', 'pagato_da' => $pagatoDa];
        }
        $resto = (int) ($parti['nessuno'] ?? 0) + ($pesi === [] ? (int) ($parti['conduttori'] ?? 0) : 0);
        if ($resto !== 0) {
            $senza[] = $base + ['importo' => $resto, 'giorni' => $pesi === [] ? $periodo->giorni() : $scoperti, 'tratti' => $pesi === [] ? $periodo : $trattiScoperti,
                'modo' => 'proprietario', 'pagato_da' => $pagatoDa];
        }
    }

    /**
     * R10 (Fase 1-bis): i pezzi del periodo per chi ha pagato davvero. Parte dal soggetto della riga (chi aveva la quota
     * alla generazione) e segue le vendite e le costituzioni d'usufrutto registrate sull'unità con decorrenza dentro il
     * periodo: prima della decorrenza ha pagato chi esce, dopo chi entra (le bozze gli passano o la coppia di conguaglio
     * lo riporta ai suoi giorni — decisioni 21 e 25). La vendita con riserva d'usufrutto (beta.38) non fa avanzare la
     * catena: le ordinarie le paga ancora chi vende, che resta usufruttuario (art. 1004 c.c.).
     *
     * Dalla 1.11.0-beta.44 anche la successione: dal giorno del decesso pagano gli eredi, insieme (decisione 65), e un pezzo
     * può avere più nomi. Si segue ciascuno: l'erede che poi vende lascia il posto a chi compra. Un passaggio si segue una
     * volta sola, e le decorrenze crescono, quindi la catena finisce.
     *
     * Dalla Fase 1-bis della 1.11.0-beta.47 la catena segue il ruolo della riga (`$ruolo`, il ruolo risolto): un passaggio la fa
     * avanzare solo se toglie a chi esce quel ruolo, e da lì il ruolo è quello che dà a chi entra. Sull'unità mista chi vende la
     * metà piena resta usufruttuario dell'altra, e la riga dell'usufruttuario resta sua; la costituzione toglie il pieno e dà
     * l'usufrutto, l'estinzione toglie l'usufrutto e dà il pieno (o l'usufrutto, con l'accrescimento), a tutti i nudi che tornano pieni.
     * Senza ruolo, ogni passaggio. Il ruolo è di ciascuno (giro sulle correzioni): vedi `$ruoloDi` e la riserva qui sotto.
     *
     * @return list<array{nome: ?string, periodo: InsiemePeriodi}>
     */
    private function catenaDeiPagatori(PianoRate $piano, int $anagraficaId, int $immobileId, InsiemePeriodi $periodo, ?string $ruolo = null): array
    {
        // Giro sulle correzioni della .47: la catena non dipende dalla voce, e il prospetto la chiedeva per ogni riga di ripiego.
        return $this->catene[implode('|', [$piano->id, $anagraficaId, $immobileId, $ruolo ?? '-', json_encode($periodo->toArray())])]
            ??= $this->calcolaCatenaDeiPagatori($piano, $anagraficaId, $immobileId, $periodo, $ruolo);
    }

    /** @return list<array{nome: ?string, periodo: InsiemePeriodi}> */
    private function calcolaCatenaDeiPagatori(PianoRate $piano, int $anagraficaId, int $immobileId, InsiemePeriodi $periodo, ?string $ruolo): array
    {
        $ruoli = fn (Subentro $p): array => match (true) {
            $p->estinzioneUsufrutto() => ['usufruttuario', empty($p->registro['accrescimento'] ?? null) ? 'proprietario' : 'usufruttuario'],
            $p->tipo_passaggio === 'usufrutto' => ['proprietario', 'usufruttuario'],
            default => [(string) $p->tipologia, (string) $p->tipologia],
        };
        $pezzi = [];
        $chi = [$anagraficaId];
        // Giro sulle correzioni della .47: il ruolo è di ciascuno, non della catena. Fra due eredi la riserva dell'uno non ferma la
        // vendita dell'altro, e chi entra prende il ruolo che il passaggio gli dà.
        $ruoloDi = [$anagraficaId => $ruolo];
        $dal = $periodo->dal();
        $visti = [];
        $riserve = [];
        $nomi = fn (array $ids) => collect($ids)->map(fn (int $id) => Anagrafica::whereKey($id)->value('nome'))->filter()->join(', ', ' e ') ?: null;
        while (true) {
            $candidati = Subentro::where('immobile_id', $immobileId)->whereIn('tipo_passaggio', ['vendita', 'usufrutto', 'successione'])
                ->whereIn('anagrafica_uscente_id', $chi)->whereNotNull('anagrafica_entrante_id')->whereNotIn('id', [...array_keys($visti), ...array_keys($riserve)])
                ->whereDate('decorrenza', '<=', $periodo->al()->toDateString())->orderBy('decorrenza')->orderBy('id')->get();
            $passaggio = null;
            foreach ($candidati as $p) {
                $uscente = (int) $p->anagrafica_uscente_id;
                $giorno = $p->decorrenza->toDateString();
                if ($p->riservaUsufrutto()) {
                    // La riserva non fa avanzare la catena: chi vende resta, usufruttuario. Gira il suo ruolo solo se il riparto non l'ha
                    // vista, cioè se è registrata dopo la generazione del piano, anche con la decorrenza prima del periodo: allora riguarda
                    // la riga del pieno che il piano ha generato. Registrata prima, il riparto gli ha già dato la riga dell'usufruttuario,
                    // e una sua riga del pieno viene da altro (l'altra metà ricevuta dopo, per successione o per acquisto).
                    if (($visti === [] || $giorno >= $dal->toDateString()) && ($ruoloDi[$uscente] ?? null) === 'proprietario' && $piano->quoteCeranoAl($p)) {
                        $ruoloDi[$uscente] = 'usufruttuario';
                    }
                    $riserve[(int) $p->id] = true;
                    continue;
                }
                // Dopo il primo passaggio anche lo stesso giorno: due eredi possono vendere la loro parte insieme.
                if (($visti === [] ? $giorno > $dal->toDateString() : $giorno >= $dal->toDateString()) && ($ruolo === null || ($ruoloDi[$uscente] ?? null) === $ruoli($p)[0])) {
                    $passaggio = $p;
                    break;
                }
            }
            if ($passaggio === null) {
                break;
            }
            $visti[(int) $passaggio->id] = true;
            $pezzo = $periodo->intersezione(new PeriodoCompetenza($dal, $passaggio->decorrenza->toImmutable()->subDay()));
            if ($pezzo !== null) {
                $pezzi[] = ['nome' => $nomi($chi), 'periodo' => $pezzo];
            }
            $entranti = $this->chiEntra($passaggio);
            $chi = array_values(array_unique([...array_diff($chi, [(int) $passaggio->anagrafica_uscente_id]), ...$entranti]));
            unset($ruoloDi[(int) $passaggio->anagrafica_uscente_id]);
            foreach ($entranti as $e) {
                $ruoloDi[$e] = $ruolo === null ? null : $ruoli($passaggio)[1];
            }
            $dal = $passaggio->decorrenza->toImmutable();
        }
        $ultimo = $periodo->intersezione(new PeriodoCompetenza($dal, $periodo->al()));
        if ($ultimo !== null) {
            $pezzi[] = ['nome' => $nomi($chi), 'periodo' => $ultimo];
        }

        return $pezzi;
    }

    /**
     * Chi entra nella catena dei pagatori con un passaggio. Nell'estinzione senza l'accrescimento sono tutti i nudi che tornano pieni,
     * come li conta il conguaglio (`ConguaglioPassaggio::nudiDellEstinzione`): il passaggio ne nomina uno solo come chi entra (giro sulle
     * correzioni della .47, la vedova usufruttuaria con due figli nudi proprietari). Altrimenti `Subentro::entranti()`: gli eredi della
     * successione, gli usufruttuari dell'accrescimento, o chi entra.
     *
     * @return list<int>
     */
    private function chiEntra(Subentro $passaggio): array
    {
        if ($passaggio->estinzioneUsufrutto() && empty($passaggio->registro['accrescimento'] ?? null)) {
            $nudi = array_keys(app(\App\Services\Subentro\ConguaglioPassaggio::class)->nudiDellEstinzione($passaggio));
            if ($nudi !== []) {
                return array_map('intval', $nudi);
            }
        }

        return $passaggio->entranti();
    }

    /**
     * DL1: i pezzi di una riga «nelle sue rate» quando l'inquilino è cambiato con «Fine locazione» e il nuovo inquilino, registrata
     * dopo la generazione e con il conguaglio (il passaggio ha preso il piano, decisione 42; né rinuncia né conguaglio annullato).
     * Sul box locato insieme il passaggio trovato è il figlio della pertinenza: la rinuncia e l'annullamento stanno sul padre
     * (Fase 1-bis della .47), i piani presi sul figlio, perché sono per unità.
     * Si divide come il conguaglio: `ProRataTemporis::dividi` sulla parte che resta, un passaggio alla volta (il secondo cambio
     * divide la parte del primo entrato, come il conguaglio dal predecessore). Il primo pezzo resta «nelle sue rate»; gli altri
     * sono pagati con il conguaglio. Dalla 1.11.0-beta.50 (decisione 77) la catena finisce anche alla fine senza un nuovo inquilino, o
     * con «Non scriverlo»: l'ultimo pezzo porta `uscita` (la strada, la scelta, il giorno, chi ha pagato; `uscitaDellaLocazione`), e
     * `dividiLeRate` lo divide. Ultimo giro della .50: anche la fine che cade prima della voce, o nel suo primo giorno (`finePrimaDellaVoce`:
     * con «Scrivi» nell'ultimo giro, con ogni scelta dalla chiusura della .50); allora il primo pezzo non ha giorni né importo, e la voce è
     * tutta il pezzo dopo l'uscita.
     *
     * @return list<array{anagrafica_id: int, importo: int, giorni: int, modo: string, conguaglio_del: ?string, tratti: ?InsiemePeriodi}>
     */
    private function catenaDeiConduttori(PianoRate $piano, int $anagraficaId, int $immobileId, InsiemePeriodi $periodo, int $importo): array
    {
        $pezzi = [];
        $chi = $anagraficaId;
        $resto = $importo;
        $restante = $periodo;
        $modo = 'rate';
        // La data del cambio d'inquilino che ha dato i giorni a chi è entrato: il prospetto la scrive accanto alla voce.
        $del = null;
        $visti = [];
        while (true) {
            // Decisioni 32 e 77 (1.11.0-beta.50): anche la fine senza un nuovo inquilino. Sul box il passaggio trovato è il figlio, e la
            // scelta sta nel registro del padre (`capo()`).
            $passaggio = Subentro::where('immobile_id', $immobileId)->where('tipo_passaggio', 'fine_locazione')
                ->where('anagrafica_uscente_id', $chi)->whereNotIn('id', $visti)
                ->whereDate('decorrenza', '>', $restante->dal()->toDateString())->whereDate('decorrenza', '<=', $restante->al()->toDateString())
                ->orderBy('decorrenza')->orderBy('id')->first();
            // Ultimo giro della .50: una voce con la competenza tutta dal giorno dell'uscita in poi (un'entrata o una spesa dei soli mesi
            // dopo) non ha la fine dopo il suo primo giorno, ma prende la strada di quella fine: con «Scrivi» la coppia l'ha portata tutta a
            // chi paga al posto dell'inquilino, con «Non scriverlo» resta a chi è uscito e gli si restituisce (chiusura della .50).
            if ($passaggio === null && $pezzi === []) {
                $passaggio = $this->finePrimaDellaVoce($piano, $chi, $immobileId, $restante);
            }
            $capo = $passaggio?->capo();
            // Decisione 83.3 (1.11.0-beta.50): il cambio d'inquilino «regolato fra le parti» (la casella di prima della .50, o il conguaglio
            // annullato) seguito da una fine di chi era entrato con la coppia scritta su questa unità: la catena continua, e il pezzo di chi
            // era entrato è «regolato fra le parti». Senza una fine scritta dopo resta la lettura delle decisioni 43 e 46.
            $regolato = $passaggio !== null && $this->regolatoConUnaFineDopo($passaggio, $capo, $piano, $immobileId, $restante);
            if ($passaggio === null || ($capo->conguaglioAnnullato() && ! $regolato)) {
                // Dopo «Annulla il conguaglio» le parti hanno regolato fra loro (decisioni 43 e 46): il pezzo che resta lo dice, per il pannello.
                if ($passaggio !== null) {
                    $regolatoIl = $passaggio->decorrenza->toDateString();
                }
                break;
            }
            $chiave = implode('|', ['uscita', $piano->id, $passaggio->id, $immobileId, $chi]);
            if (! array_key_exists($chiave, $this->uscite)) {
                $this->uscite[$chiave] = $this->uscitaDellaLocazione($piano, $passaggio, $capo, $immobileId, $chi);
            }
            $uscita = $this->uscite[$chiave];
            if ($uscita !== null) {
                $d = ProRataTemporis::dividi($resto, $restante, $passaggio->decorrenza);
                // Con la fine prima della voce, o nel suo primo giorno, il pezzo prima dell'uscita non ha giorni: tiene il posto, a zero.
                $giorniPrima = $passaggio->decorrenza->toDateString() > $restante->dal()->toDateString()
                    ? $restante->intersezione(new PeriodoCompetenza($restante->dal(), $passaggio->decorrenza->toImmutable()->subDay())) : null;
                $pezzi[] = ['anagrafica_id' => $chi, 'importo' => (int) $d['uscente'], 'giorni' => (int) $d['giorni_uscente'], 'modo' => $modo, 'conguaglio_del' => $del,
                    'tratti' => $giorniPrima];
                $dopo = $restante->intersezione(new PeriodoCompetenza($passaggio->decorrenza->toImmutable(), $restante->al()));
                if ($dopo !== null) {
                    if ($uscita['strada'] === 'B') {
                        // R2: i giorni che nessuno copriva, dal registro della coppia, non li ha pagati nessuno con la coppia.
                        $scoperti = $this->scopertiDellaCoppia($capo, $immobileId, (int) $piano->gestione_id, $dopo);
                        $coperti = $dopo;
                        foreach ($scoperti?->periodi() ?? [] as $t) {
                            $coperti = $coperti?->meno($t);
                        }
                        $uscita['scoperti'] = $scoperti;
                        $uscita['pagatori'] = $coperti === null ? [] : ($this->uscite[implode('|', ['pagatori', $piano->gestione_id, $capo->id, $immobileId, json_encode($coperti->toArray())])]
                            ??= $this->pagatoriDopoLUscita($piano, $capo, $immobileId, $coperti));
                    }
                    $pezzi[] = ['anagrafica_id' => $chi, 'importo' => (int) $d['entrante'], 'giorni' => $dopo->giorni(), 'modo' => $modo, 'conguaglio_del' => $del, 'tratti' => $dopo,
                        'uscita' => $uscita];
                }

                return $pezzi;
            }
            if (! $regolato && ($passaggio->anagrafica_entrante_id === null || $capo->conguaglioRinunciato() || ! $piano->presoNelConguaglioDa($passaggio))) {
                // Il cambio d'inquilino che le parti hanno regolato fra loro (decisioni 43 e 46): il pezzo che resta lo dice, per il pannello.
                if ($passaggio->anagrafica_entrante_id !== null && $capo->conguaglioRinunciato() && ! $capo->conguaglioNonScritto()) {
                    $regolatoIl = $passaggio->decorrenza->toDateString();
                }
                break;
            }
            $visti[] = (int) $passaggio->id;
            $d = ProRataTemporis::dividi($resto, $restante, $passaggio->decorrenza);
            $pezzi[] = ['anagrafica_id' => $chi, 'importo' => (int) $d['uscente'], 'giorni' => (int) $d['giorni_uscente'], 'modo' => $modo, 'conguaglio_del' => $del,
                'tratti' => $restante->intersezione(new PeriodoCompetenza($restante->dal(), $passaggio->decorrenza->toImmutable()->subDay()))];
            $dopo = $restante->intersezione(new PeriodoCompetenza($passaggio->decorrenza->toImmutable(), $restante->al()));
            if ($dopo === null) {
                return $pezzi;
            }
            $chi = (int) $passaggio->anagrafica_entrante_id;
            $resto = (int) $d['entrante'];
            $restante = $dopo;
            $modo = $regolato ? 'regolato' : 'conguaglio';
            $del = $passaggio->decorrenza->toDateString();
        }
        $pezzi[] = ['anagrafica_id' => $chi, 'importo' => $resto, 'giorni' => $restante->giorni(), 'modo' => $modo, 'conguaglio_del' => $del, 'tratti' => $restante]
            + (isset($regolatoIl) ? ['regolato_il' => $regolatoIl] : []);

        return $pezzi;
    }

    /**
     * Ultimo giro della .50 (punto 1): la fine di chi ha la riga, senza un nuovo inquilino, che cade prima della voce o nel suo primo giorno:
     * una voce con la competenza tutta dal giorno dell'uscita in poi (l'«Affitto antenna» di entrata dei soli mesi dopo, o una spesa). Con
     * «Scrivi» la coppia ha portato anche quella voce a chi paga al posto dell'inquilino, pezzo per pezzo, con il suo segno: la voce prende la
     * strada B, come le spese di tutto l'anno, e non resta «nelle rate» di chi è uscito.
     *
     * Chiusura della .50 (punto 2, decisione 77): la voce prende la strada della fine, qualunque sia, come le voci che comprendono l'uscita.
     * Con «Non scriverlo», senza la scelta (una fine di prima della .50) o su un piano che la coppia non ha preso è la strada A′: la voce
     * resta nelle rate di chi è uscito e gli si restituisce (Ines restituisce € 29,26 a Luca, non Luca le rimborsa € 90,74); con un
     * coinquilino che resta (strada C) resta nelle sue rate, con la nota. Fino all'ultimo giro si chiamava `fineScrittaPrimaDellaVoce` e
     * valeva solo con la strada B: con le altre la voce restava «nelle rate» di chi è uscito senza la restituzione. L'ultima fine prima
     * della voce; null se non c'è o se il suo conguaglio è stato annullato, e la voce resta com'era.
     */
    private function finePrimaDellaVoce(PianoRate $piano, int $chi, int $immobileId, InsiemePeriodi $periodo): ?Subentro
    {
        $fine = Subentro::where('immobile_id', $immobileId)->where('tipo_passaggio', 'fine_locazione')->where('anagrafica_uscente_id', $chi)
            ->whereNull('anagrafica_entrante_id')->whereDate('decorrenza', '<=', $periodo->dal()->toDateString())
            ->orderByDesc('decorrenza')->orderByDesc('id')->first();
        $capo = $fine?->capo();
        if ($fine === null || $capo->conguaglioAnnullato()) {
            return null;
        }
        $chiave = implode('|', ['uscita', $piano->id, $fine->id, $immobileId, $chi]);
        if (! array_key_exists($chiave, $this->uscite)) {
            $this->uscite[$chiave] = $this->uscitaDellaLocazione($piano, $fine, $capo, $immobileId, $chi);
        }

        return $this->uscite[$chiave] !== null ? $fine : null;
    }

    /**
     * Fase 1-bis della 1.11.0-beta.50 (R7, R8): chi ha pagato, su un piano e un'unità, la parte a carico dell'inquilino nelle rate di una
     * persona uscita, dal giorno dato in poi. È la catena del prospetto (`catenaDeiConduttori`, `uscitaDellaLocazione`), perché il pannello
     * dell'inizio di una locazione nomini chi il prospetto farà rimborsare, piano per piano: la strada B dice chi ha la coppia, la strada A′
     * chi è uscito, il coinquilino che le parti decidono, il cambio d'inquilino regolato fra le parti che lo hanno regolato loro.
     *
     * Giro stretto della .50 (G18): con `per_modo` i nomi divisi per come hanno pagato (chi ha preso la coppia, chi è uscito e l'ha nelle sue
     * rate), e con `fini_scritte` le fini che hanno scritto la coppia dei giorni (il giorno e chi è uscito), perché il pannello dica la fine
     * giusta quando non è quella di chi esce.
     *
     * Chiusura della .50 (punto 4): con `presi_da` chi ha preso con la coppia tutti i giorni dopo l'uscita, non solo quelli dal giorno dato
     * (Ugo fino al 31/8 ed Elsa dal 1/9, mentre i giorni di Luca dal 1/10 li ha pagati solo Elsa): la frase che dice «i giorni dopo la sua
     * uscita li hanno presi con il conguaglio …» nomina tutti quelli che li hanno presi, e fa rimborsare solo chi ha pagato i giorni di chi
     * entra (`pagato_da`).
     *
     * @return array{come: string, pagato_da: list<string>, presi_da: list<string>, per_modo: array<string, list<string>>, fini_scritte: list<array{del: string, chi_esce: ?string}>} `come`: scritto, non_scritto, regolato, coinquilino, a_mano o misto
     */
    public function chiHaPagatoDopo(PianoRate $piano, int $anagraficaId, int $immobileId, InsiemePeriodi $periodo, CarbonImmutable $dal): array
    {
        if ($dal->gt($periodo->al())) {
            return ['come' => 'a_mano', 'pagato_da' => [], 'presi_da' => [], 'per_modo' => [], 'fini_scritte' => []];
        }
        $dopo = new PeriodoCompetenza($dal->lt($periodo->dal()) ? $periodo->dal() : $dal, $periodo->al());
        $come = [];
        $nomi = [];
        $presi = [];
        $perModo = [];
        $fini = [];
        foreach ($this->catenaDeiConduttori($piano, $anagraficaId, $immobileId, $periodo, 0) as $pz) {
            $tratti = $pz['tratti'] ?? null;
            if (! $tratti instanceof InsiemePeriodi || $tratti->intersezione($dopo) === null) {
                continue;
            }
            $u = $pz['uscita'] ?? null;
            if ($u !== null && $u['strada'] === 'C') {
                $come[] = 'coinquilino';
            } elseif ($u !== null && $u['strada'] === 'A' && ! empty($u['rinunciato'])) {
                $come[] = 'regolato';
            } elseif ($u !== null && $u['strada'] === 'A') {
                $come[] = 'non_scritto';
                $nomi[] = $perModo['non_scritto'][] = (string) ($u['pagato_da'] ?? '');
            } elseif ($u !== null) {
                foreach ($u['pagatori'] ?? [] as $p) {
                    $presi[] = (string) ($p['nome'] ?? '');
                    if ($p['periodo']->intersezione($dopo) !== null) {
                        $come[] = 'scritto';
                        $nomi[] = $perModo['scritto'][] = (string) ($p['nome'] ?? '');
                        $fini[] = ['del' => (string) $u['del'], 'chi_esce' => $u['chi_esce'] ?? null];
                    }
                }
                // R2: i giorni che nessuno copriva sono rimasti nelle rate di chi è uscito.
                if (($u['scoperti'] ?? null) instanceof InsiemePeriodi && $u['scoperti']->intersezione($dopo) !== null) {
                    $come[] = 'non_scritto';
                    $nomi[] = $perModo['non_scritto'][] = (string) ($u['chi_esce'] ?? '');
                }
            } elseif ($pz['modo'] === 'regolato' || (isset($pz['regolato_il']) && $pz['regolato_il'] <= $dopo->al->toDateString())) {
                $come[] = 'regolato';
            } elseif ($pz['modo'] === 'conguaglio') {
                $come[] = 'scritto';
                $nomi[] = $presi[] = $perModo['scritto'][] = (string) Anagrafica::whereKey((int) $pz['anagrafica_id'])->value('nome');
            } else {
                $come[] = 'a_mano';
            }
        }
        $come = array_values(array_unique($come));

        return ['come' => match (count($come)) { 0 => 'a_mano', 1 => $come[0], default => 'misto' }, 'pagato_da' => array_values(array_unique(array_filter($nomi))),
            'presi_da' => array_values(array_unique(array_filter($presi))),
            'per_modo' => array_map(fn (array $n) => array_values(array_unique(array_filter($n))), $perModo), 'fini_scritte' => array_values(array_unique($fini, SORT_REGULAR))];
    }

    /**
     * Chiusura della .50 (punti 2 e 3): il verso della parte di un conduttore che entra dal giorno dato nelle righe di una persona uscita,
     * piano per piano, come la divide il prospetto: la catena di `catenaDeiConduttori` riga per riga, con l'importo di ogni pezzo diviso per
     * giorni. `scritto` è la parte dei giorni che chi ha preso la coppia ha pagato con il conguaglio (la strada B, i giorni coperti, e il
     * cambio d'inquilino scritto); `non_scritto` quella rimasta nelle rate di chi è uscito (la strada A′, e con «Scrivi» i giorni che nessuno
     * copriva). Positiva la rimborsa chi entra; negativa (un'entrata che in quei giorni supera la spesa) è un suo credito, e chi ha pagato
     * gli restituisce la differenza. Serve il segno, non la cifra: il prospetto arrotonda voce per voce, al netto del già versato
     * «dell'unità», e divide i giorni fra i conduttori con la loro quota. I giorni di un coinquilino che resta e quelli regolati fra le parti
     * non hanno un verso, e non contano.
     *
     * @param list<array{periodo: InsiemePeriodi, importo: int}> $righe le righe del riparto di chi è uscito: il periodo e l'importo
     * @return array{scritto: float, non_scritto: float}
     */
    public function partiDopo(PianoRate $piano, int $anagraficaId, int $immobileId, array $righe, CarbonImmutable $dal): array
    {
        $parti = ['scritto' => 0.0, 'non_scritto' => 0.0];
        foreach ($righe as $r) {
            $periodo = $r['periodo'];
            if ((int) $r['importo'] === 0 || $dal->gt($periodo->al())) {
                continue;
            }
            $dopo = new PeriodoCompetenza($dal->lt($periodo->dal()) ? $periodo->dal() : $dal, $periodo->al());
            $giorni = fn (?InsiemePeriodi $x): int => $x?->intersezione($dopo)?->giorni() ?? 0;
            foreach ($this->catenaDeiConduttori($piano, $anagraficaId, $immobileId, $periodo, (int) $r['importo']) as $pz) {
                $tratti = $pz['tratti'] ?? null;
                if (! $tratti instanceof InsiemePeriodi || (int) $pz['importo'] === 0 || $giorni($tratti) === 0) {
                    continue;
                }
                $alGiorno = (int) $pz['importo'] / $tratti->giorni();
                $u = $pz['uscita'] ?? null;
                if ($u === null) {
                    if ($pz['modo'] === 'conguaglio') {
                        $parti['scritto'] += $alGiorno * $giorni($tratti);
                    }
                } elseif ($u['strada'] === 'A' && empty($u['rinunciato'])) {
                    $parti['non_scritto'] += $alGiorno * $giorni($tratti);
                } elseif ($u['strada'] === 'B') {
                    // R2: i giorni che nessuno copriva sono rimasti nelle rate di chi è uscito.
                    $scoperti = ($u['scoperti'] ?? null) instanceof InsiemePeriodi ? $giorni(self::comune($tratti, $u['scoperti'])) : 0;
                    $parti['non_scritto'] += $alGiorno * $scoperti;
                    $parti['scritto'] += $alGiorno * ($giorni($tratti) - $scoperti);
                }
            }
        }

        return $parti;
    }

    /**
     * Decisione 83.3: un cambio d'inquilino «regolato fra le parti» — la casella di prima della .50 (la rinuncia senza la scelta) o il
     * conguaglio annullato — dopo il quale chi era entrato è uscito a sua volta, nei giorni del pezzo, con una fine che ha scritto la coppia
     * su questa unità e gestione (righe in saldi, conguaglio non annullato). Allora i giorni dopo la sua uscita li ha pagati chi ha la
     * coppia, e la catena li segue.
     *
     * Le righe della coppia si scrivono con l'id del padre (`RegistraSubentroAction::scriviCoppie`): sulla pertinenza la fine trovata è il
     * figlio, e le righe si cercano con il suo capo, come in `uscitaDellaLocazione` (giro stretto della .50, G3).
     */
    private function regolatoConUnaFineDopo(Subentro $passaggio, Subentro $capo, PianoRate $piano, int $immobileId, InsiemePeriodi $restante): bool
    {
        if ($passaggio->anagrafica_entrante_id === null || $capo->conguaglioNonScritto() || ! ($capo->conguaglioAnnullato() || $capo->conguaglioRinunciato())) {
            return false;
        }

        return Subentro::where('immobile_id', $immobileId)->where('tipo_passaggio', 'fine_locazione')->where('anagrafica_uscente_id', (int) $passaggio->anagrafica_entrante_id)
            ->whereDate('decorrenza', '>', $passaggio->decorrenza->toDateString())->whereDate('decorrenza', '<=', $restante->al()->toDateString())
            ->get()->contains(function (Subentro $fine) use ($immobileId, $piano) {
                $capoDellaFine = $fine->capo();

                return ! $capoDellaFine->conguaglioAnnullato()
                    && DB::table('saldi')->where('subentro_id', $capoDellaFine->id)->where('immobile_id', $immobileId)->where('gestione_id', $piano->gestione_id)->exists();
            });
    }

    /**
     * Decisioni 32, 77 e 79 (1.11.0-beta.50): che cosa è successo ai giorni dopo l'uscita di chi lascia l'unità, piano per piano. Null
     * quando vale la catena del cambio d'inquilino di prima (la coppia scritta con chi entra, o la casella «le parti hanno regolato» di un
     * passaggio registrato prima della .50, decisioni 43 e 46).
     * - `C`: resta un coinquilino (decisione 79): nessuna coppia e nessuna scelta, i giorni restano nelle rate di chi esce e chi li paga
     *   lo decidono le parti. Vale per l'unità, anche quando la scelta c'è per le altre del passaggio.
     * - `B`: «Scrivi il conguaglio» su un piano che la coppia ha preso, con le righe della coppia sull'unità: i giorni li ha pagati chi sta
     *   al posto dell'inquilino. Dove nessuno ci stava la coppia non si è scritta, e vale la strada A′.
     * - `A`, la strada A′: le rate restano a chi esce, e i giorni dopo l'uscita gli si restituiscono. Con «Non scriverlo» (anche nel cambio
     *   d'inquilino in un passo: non è la rinuncia della decisione 43), e senza la scelta — la fine registrata prima della .50, o su un
     *   piano che la coppia non ha preso e poi emesso senza ricalcolo (decisione 40) —, dove la scelta resta nulla: «nessun conguaglio».
     *
     * @return array{strada: string, scelta: ?string, del: string, chi_esce: ?string, pagato_da?: ?string}|null
     */
    private function uscitaDellaLocazione(PianoRate $piano, Subentro $passaggio, Subentro $capo, int $immobileId, int $chi): ?array
    {
        $scelta = $capo->registro['conguaglio']['scelta'] ?? null;
        $nome = Anagrafica::whereKey($chi)->value('nome');
        $uscita = ['scelta' => null, 'del' => $passaggio->decorrenza->toDateString(), 'chi_esce' => $nome];
        if ($passaggio->anagrafica_entrante_id !== null) {
            return $scelta === Subentro::CONGUAGLIO_NON_SCRITTO ? ['strada' => 'A', 'scelta' => $scelta, 'pagato_da' => $nome] + $uscita : null;
        }
        if ($this->coinquilinoCheResta($capo, $immobileId, $chi, CarbonImmutable::parse($passaggio->decorrenza->toDateString()))) {
            return ['strada' => 'C'] + $uscita;
        }
        // Punto 4 della lista della .50: sull'unità dove nessuno stava al posto dell'inquilino dopo l'uscita la coppia non si è scritta, e
        // i giorni sono rimasti nelle rate di chi esce: la strada B solo con le righe della coppia su questa unità e gestione.
        if ($scelta === Subentro::CONGUAGLIO_SCRITTO && $piano->presoNelConguaglioDa($passaggio)
            && DB::table('saldi')->where('subentro_id', $capo->id)->where('immobile_id', $immobileId)->where('gestione_id', $piano->gestione_id)->where('saldo_iniziale', '>', 0)->exists()) {
            return ['strada' => 'B', 'scelta' => $scelta] + $uscita;
        }

        return ['strada' => 'A', 'scelta' => $scelta === Subentro::CONGUAGLIO_NON_SCRITTO ? $scelta : null, 'pagato_da' => $nome] + $uscita
            // Una fine di prima della .50 con la rinuncia della casella (la nota, senza la scelta): per il pannello le parti hanno regolato.
            + ($scelta === null && $capo->conguaglioRinunciato() ? ['rinunciato' => true] : []);
    }

    /**
     * Fase 1-bis della .50 (R2): i giorni dopo l'uscita in cui nessuno stava al posto dell'inquilino, per un'unità e una gestione, dal
     * registro della coppia (`conguaglio.periodi`, la chiave `scoperti`), dentro i giorni dati. Null se la coppia li copriva tutti.
     */
    private function scopertiDellaCoppia(Subentro $capo, int $immobileId, int $gestioneId, InsiemePeriodi $dopo): ?InsiemePeriodi
    {
        $scoperti = collect($capo->registro['conguaglio']['periodi'] ?? [])
            ->filter(fn ($v) => (int) ($v['gestione_id'] ?? 0) === $gestioneId && (int) ($v['immobile_id'] ?? 0) === $immobileId)
            ->flatMap(fn ($v) => (array) ($v['scoperti'] ?? []))->filter(fn ($t) => ! empty($t['dal']) && ! empty($t['al']))->values();

        return $scoperti->isEmpty() ? null : self::comune($dopo, new InsiemePeriodi(...$scoperti->map(fn ($t) => new PeriodoCompetenza($t['dal'], $t['al']))->all()));
    }

    /**
     * Decisione 79 (2): sull'unità resta un altro inquilino, che c'era già il giorno prima dell'uscita e c'è ancora quel giorno. Lo dice
     * il registro del passaggio, dove il pannello ha scritto la fermata del coinquilino unità per unità; per i passaggi che non l'hanno
     * (registrati prima della .50, o senza coppie da proporre) si guardano le righe. Chi entra quel giorno, anche registrato dopo, non è un
     * coinquilino che resta: è il cambio d'inquilino in due passi. La regola è una sola, con il pannello e lo storico
     * (`ChiPagaAlPostoDellInquilino::coinquiliniDelPassaggio`).
     */
    private function coinquilinoCheResta(Subentro $capo, int $immobileId, int $chi, CarbonImmutable $decorrenza): bool
    {
        return ChiPagaAlPostoDellInquilino::coinquiliniDelPassaggio($capo, $immobileId, $chi, $decorrenza) !== [];
    }

    /**
     * Strada B: chi ha pagato i giorni dopo l'uscita, a pezzi. Chi ha preso la coppia su questa unità e gestione, dalle righe del passaggio
     * (le coppie delle pertinenze stanno sul padre); più persone in un pezzo solo, come gli eredi della catena dei pagatori. Decisione 77
     * (3): un passaggio registrato dopo, che ha cambiato chi sta al posto dell'inquilino senza spostare la coppia, si è fermato e lo ha
     * scritto nel suo registro (`fermata_dopo_scrivi`). Il denaro non si è mosso: dal giorno della fermata i giorni li ha pagati ancora chi
     * ha la coppia, o chi è entrato se le parti hanno regolato la fermata fra loro: il pezzo porta le sue `fermate`, e le voci li nominano
     * tutti e due (`oppure`).
     *
     * Ultimo giro della .50 (punto 5): con più persone nella coppia i pezzi si tagliano anche per giorni, sui giorni che ciascuno pagava al
     * posto dell'inquilino, con la regola della coppia (`ChiPagaAlPostoDellInquilino::pesi`, la catena del ripiego sulle righe dell'unità):
     * chi ha venduto prima della fine ha pagato i giorni fino alla vendita, chi ha comprato quelli dopo, e un conduttore rimborsa solo chi ha
     * pagato i suoi giorni. Dove la catena di oggi nomina chi non ha una riga nella coppia (un passaggio registrato dopo la fine, che non
     * ha spostato la coppia) i giorni restano a chi li pagava prima, con le fermate; senza un prima, a tutti quelli della coppia.
     *
     * @return list<array{nome: ?string, fermate: list<array{passaggio_id: int, testo: string}>, periodo: InsiemePeriodi}>
     */
    private function pagatoriDopoLUscita(PianoRate $piano, Subentro $capo, int $immobileId, InsiemePeriodi $dopo): array
    {
        // Giro stretto della .50 (G2): con i pezzi con il segno una coppia può avere parti di segno diverso (un'entrata che in certi giorni
        // supera la spesa). Chi ha pagato sono le persone con una riga nella coppia, a debito o a credito, tranne chi è uscito.
        $conRighe = DB::table('saldi')->where('subentro_id', $capo->id)->where('immobile_id', $immobileId)->where('gestione_id', $piano->gestione_id)
            ->where('saldo_iniziale', '!=', 0)->where('anagrafica_id', '!=', (int) $capo->anagrafica_uscente_id)->orderBy('id')->pluck('anagrafica_id')
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
        $nomiDi = fn (array $ids) => collect($ids)->map(fn (int $id) => Anagrafica::whereKey($id)->value('nome'))->filter()->join(', ', ' e ') ?: null;
        $nomi = $nomiDi($conRighe);
        $data = fn ($giorno) => CarbonImmutable::parse(substr((string) $giorno, 0, 10))->format('d/m/Y');
        $fermate = Subentro::where('condominio_id', $capo->condominio_id)->whereNull('subentro_padre_id')->whereKeyNot($capo->id)->orderBy('decorrenza')->orderBy('id')->get()
            ->flatMap(fn (Subentro $s) => collect($s->registro['fermata_dopo_scrivi'] ?? [])
                ->filter(fn ($f) => (int) ($f['fine_id'] ?? 0) === (int) $capo->id && (int) ($f['immobile_id'] ?? 0) === $immobileId
                    && (int) ($f['gestione_id'] ?? 0) === (int) $piano->gestione_id && ! empty($f['dal']))
                ->map(fn ($f) => [
                    'dal' => CarbonImmutable::parse(substr((string) $f['dal'], 0, 10)),
                    'passaggio_id' => (int) $s->id,
                    'testo' => sprintf('%s, se le parti hanno regolato fra loro la parte del conguaglio che %s del %s non ha spostato',
                        collect($f['verso'] ?? [])->filter()->join(', ', ' e ') ?: ($s->entrante?->nome ?? 'chi è entrato'), self::passaggioInParole($s), $data($s->decorrenza)),
                ]))
            ->values();

        $pezzi = [];
        $da = $dopo->dal();
        $tagli = $fermate->pluck('dal');
        // Punto 5: con più persone nella coppia, i giorni in cui cambia chi sta al posto dell'inquilino (le righe dell'unità, tranne gli
        // inquilini) tagliano i pezzi, e ogni pezzo nomina chi pagava i suoi giorni.
        $chiPaga = count($conRighe) > 1 ? new ChiPagaAlPostoDellInquilino($this->titolari) : null;
        $righeUnita = $chiPaga?->righe($immobileId) ?? collect();
        foreach ($righeUnita->filter(fn ($r) => ($r->tipologia ?? null) !== 'inquilino' && (bool) ($r->attivo ?? false)) as $r) {
            foreach ([$r->data_inizio, $r->data_fine === null ? null : CarbonImmutable::parse(substr((string) $r->data_fine, 0, 10))->addDay()] as $g) {
                if ($g !== null) {
                    $tagli->push(CarbonImmutable::parse(substr((string) $g, 0, 10)));
                }
            }
        }
        $tagli = $tagli->filter(fn (CarbonImmutable $g) => $g->gt($dopo->dal()) && $g->lte($dopo->al()))
            ->map(fn (CarbonImmutable $g) => $g->toDateString())->unique()->sort()->values();
        $diPrima = null;
        foreach ([...$tagli->all(), null] as $taglio) {
            $al = $taglio === null ? $dopo->al() : CarbonImmutable::parse($taglio)->subDay();
            $pezzo = $al->lt($da) ? null : $dopo->intersezione(new PeriodoCompetenza($da, $al));
            if ($pezzo !== null) {
                $nome = $nomi;
                if ($chiPaga !== null) {
                    $chi = array_keys($chiPaga->pesi($righeUnita, $pezzo));
                    $persone = $chi !== [] && array_diff($chi, $conRighe) === [] ? array_values(array_intersect($conRighe, $chi)) : ($diPrima ?? $conRighe);
                    $diPrima = $persone;
                    $nome = $nomiDi($persone);
                }
                $suoi = ['nome' => $nome, 'fermate' => $fermate->filter(fn (array $f) => $f['dal']->lte($da))->map(fn (array $f) => ['passaggio_id' => $f['passaggio_id'], 'testo' => $f['testo']])->values()->all(), 'periodo' => $pezzo];
                $ultimo = array_key_last($pezzi);
                // Due pezzi di fila con le stesse persone e le stesse fermate sono uno solo.
                if ($ultimo !== null && $pezzi[$ultimo]['nome'] === $suoi['nome'] && $pezzi[$ultimo]['fermate'] === $suoi['fermate']) {
                    $pezzi[$ultimo]['periodo'] = $pezzi[$ultimo]['periodo']->unione($pezzo);
                } else {
                    $pezzi[] = $suoi;
                }
            }
            if ($taglio !== null) {
                $da = CarbonImmutable::parse($taglio);
            }
        }

        return $pezzi;
    }

    /**
     * Le fermate che riguardano i giorni di un conduttore: quelle dei passaggi registrati prima del suo inizio. La cifra di una fermata
     * conta solo i giorni senza un conduttore registrato (decisione 79, nota delle misure): chi era già conduttore quando il passaggio si
     * è fermato non era fra quei giorni, e per lui ha pagato solo chi ha il conguaglio. Un conduttore censito senza un passaggio c'era già.
     *
     * @param list<array{passaggio_id: int, testo: string}> $fermate
     * @return list<array{passaggio_id: int, testo: string}>
     */
    private function fermateDopoIlConduttore(array $fermate, int $anagraficaId, int $immobileId, InsiemePeriodi $periodo): array
    {
        if ($fermate === []) {
            return [];
        }
        $righe = DB::table('anagrafica_immobile')->where('immobile_id', $immobileId)->where('anagrafica_id', $anagraficaId)->where('tipologia', 'inquilino')->get()
            ->filter(fn ($riga) => $periodo->intersezione(new PeriodoCompetenza(substr((string) ($riga->data_inizio ?? '1900-01-01'), 0, 10), substr((string) ($riga->data_fine ?? '2999-12-31'), 0, 10))) !== null)
            ->pluck('id');
        $inizio = $righe->isEmpty() ? null : Subentro::whereIn('riga_entrante_id', $righe->all())->min('id');

        return $inizio === null ? [] : array_values(array_filter($fermate, fn (array $f) => (int) $inizio > (int) $f['passaggio_id']));
    }

    /** Il testo `oppure` di una voce: chi è entrato con le fermate che la riguardano, null se non ce ne sono. */
    private static function oppure(array $fermate): ?string
    {
        $testi = array_values(array_unique(array_column($fermate, 'testo')));

        return $testi === [] ? null : implode('; ', $testi);
    }

    /** «la vendita», «la costituzione d'usufrutto», …: il passaggio che ha dichiarato una fermata, nelle frasi del prospetto. */
    private static function passaggioInParole(Subentro $s): string
    {
        return match (true) {
            $s->tipo_passaggio === 'vendita' => 'la vendita',
            $s->estinzioneUsufrutto() => 'l\'estinzione dell\'usufrutto',
            $s->tipo_passaggio === 'usufrutto' => 'la costituzione d\'usufrutto',
            $s->successione() => 'la successione',
            default => 'il passaggio',
        };
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
     * Una riga per (piano, voce, tabella, modo, chi ha pagato, cambio d'inquilino): due tratti della stessa voce si sommano, i
     * giorni pure. Dalla 1.11.0-beta.50 anche la fine della locazione e la sua strada sono nella chiave: il ripiego di prima e la coppia
     * della fine sullo stesso pagatore restano due voci, con la loro dicitura, e così i giorni coperti da una fermata.
     *
     * @param list<array<string,mixed>> $voci
     * @return list<array<string,mixed>>
     */
    private function accorpa(array $voci): array
    {
        return collect($voci)
            // R20: la competenza nella chiave — due righe della stessa voce con competenze diverse restano due.
            ->groupBy(fn ($v) => $v['piano_rate_id'] . '|' . $v['conto_id'] . '|' . $v['tabella_id'] . '|' . $v['modo'] . '|' . $v['pagato_da'] . '|' . ($v['conguaglio_del'] ?? '') . '|' . $v['conto'] . '|' . json_encode($v['competenza']) . '|' . ($v['nota'] ?? '')
                . '|' . ($v['strada'] ?? '') . '|' . ($v['scelta'] ?? '') . '|' . ($v['uscita_del'] ?? '') . '|' . ($v['oppure'] ?? ''))
            ->map(function (Collection $g) {
                $prima = $g->first();
                $giorni = $g->pluck('giorni')->filter(fn ($x) => $x !== null);
                // Giro sulle correzioni della .47: con i tratti, i giorni sono la loro unione — due pezzi della stessa persona sugli
                // stessi giorni (due catene che finiscono sullo stesso pagatore) non fanno 368 giorni in un anno; due tratti disgiunti
                // si sommano. Senza i tratti (le righe senza competenza), la somma di prima.
                $tratti = $g->pluck('tratti');
                $unione = $tratti->isNotEmpty() && $tratti->every(fn ($t) => $t instanceof InsiemePeriodi)
                    ? $tratti->reduce(fn (?InsiemePeriodi $u, InsiemePeriodi $t) => $u === null ? $t : $u->unione($t)) : null;

                return array_diff_key(array_replace($prima, [
                    'importo' => (int) $g->sum('importo'),
                    'importo_formattato' => MoneyHelper::format((int) $g->sum('importo')),
                    'giorni' => $unione?->giorni() ?? ($giorni->isEmpty() ? null : (int) $giorni->sum()),
                ]), ['tratti' => true]);
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
        // DL1: la parte pagata con il conguaglio di un cambio d'inquilino è un pagamento diretto come le rate, ma non è una rata: il
        // piede la dice a parte (Fase 1-bis della .47), e il conduttore non la cerca fra le sue rate.
        $rate = (int) array_sum(array_map(fn ($v) => $v['modo'] === 'rate' ? $v['importo'] : 0, $voci));
        $conguaglio = (int) array_sum(array_map(fn ($v) => $v['modo'] === 'conguaglio' ? $v['importo'] : 0, $voci));
        // Decisione 83.3: la parte regolata fra le parti al cambio d'inquilino non è da rimborsare a nessuno.
        $regolato = (int) array_sum(array_map(fn ($v) => $v['modo'] === 'regolato' ? $v['importo'] : 0, $voci));

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
            'con_il_conguaglio' => $conguaglio,
            'con_il_conguaglio_formattato' => MoneyHelper::format($conguaglio),
            'regolato_fra_le_parti' => $regolato,
            'regolato_fra_le_parti_formattato' => MoneyHelper::format($regolato),
            'da_rimborsare' => $totale - $rate - $conguaglio - $regolato,
            'da_rimborsare_formattato' => MoneyHelper::format($totale - $rate - $conguaglio - $regolato),
        ];
    }

    /**
     * Decisioni 32, 77 e 79 (1.11.0-beta.50): le frasi della fine di una locazione, una per fine e per strada, solo se il prospetto ha voci
     * dei giorni dopo l'uscita: la scelta («scritto», «non scritto»), o nessun conguaglio quando la scelta non c'è, o il coinquilino che
     * resta (senza nomi: il programma non indovina chi paga). In più la fermata dopo «Scrivi», quando una voce la nomina.
     *
     * @param array<int, list<array<string, mixed>>> $voci
     * @param list<array<string, mixed>> $senza
     * @return list<string>
     */
    private function frasiDellUscita(array $voci, array $senza, ?int $immobileId = null, bool $piuPiani = false): array
    {
        $dellUscita = collect([...array_merge([], ...array_values($voci)), ...$senza])->filter(fn ($v) => ! empty($v['strada']) && ! empty($v['uscita_del']));
        $restituire = 'Nella sua sezione sono da restituire; per i giorni di un conduttore entrato dopo glieli rimborsa lui, per quelli senza conduttore si regolano fra le parti.';
        $chiave = fn ($v) => $v['uscita_del'] . '|' . $v['strada'] . '|' . ($v['scelta'] ?? '');
        // R18 della Fase 1-bis della .50: con più piani la stessa fine può avere due strade, una per piano (il preventivo che la coppia ha
        // preso, il riscaldamento no). Ogni frase dice di quale piano parla, e solo di quello.
        // Giro stretto della .50 (G17): con «Scrivi» e una parte dei giorni dopo l'uscita senza nessuno al posto dell'inquilino (R2) la stessa
        // fine ha due strade, B per i giorni coperti e A′ per gli altri. Si dicono in una frase sola: la strada B per i giorni in cui qualcuno
        // stava al posto dell'inquilino, gli altri rimasti a chi è uscito.
        $scopertiScritti = fn ($v) => $v['strada'] === 'A' && ($v['scelta'] ?? null) === Subentro::CONGUAGLIO_SCRITTO;
        $insieme = array_values(array_intersect($dellUscita->filter($scopertiScritti)->pluck('uscita_del')->unique()->all(), $dellUscita->where('strada', 'B')->pluck('uscita_del')->unique()->all()));
        $fusa = fn ($v) => in_array($v['uscita_del'], $insieme, true) && ($v['strada'] === 'B' || $scopertiScritti($v));
        $pianiDi = $dellUscita->groupBy(fn ($v) => $fusa($v) ? $v['uscita_del'] . '|B|' . Subentro::CONGUAGLIO_SCRITTO : $chiave($v))
            ->map(fn (Collection $g) => $g->pluck('piano')->filter()->unique()->values()->all());
        $frasi = $dellUscita->reject(fn ($v) => $fusa($v) && $v['strada'] === 'A')->unique($chiave)
            ->sortBy(fn ($v) => $v['uscita_del'] . '|' . $v['strada'])
            ->map(function ($v) use ($restituire, $pianiDi, $chiave, $piuPiani, $insieme) {
                $fuse = $v['strada'] === 'B' && in_array($v['uscita_del'], $insieme, true);
                $piani = $pianiDi[$fuse ? $v['uscita_del'] . '|B|' . Subentro::CONGUAGLIO_SCRITTO : $chiave($v)] ?? [];
                $dove = $piuPiani && $piani !== [] ? sprintf(', per %s «%s»,', count($piani) === 1 ? 'il piano' : 'i piani', implode('», «', $piani)) : '';

                return sprintf(match (true) {
                    $fuse => 'Alla fine della locazione del %s%s il conguaglio è stato scritto: i giorni dopo l\'uscita in cui qualcuno stava al posto dell\'inquilino li ha presi con la coppia chi ci stava, che li ha pagati («pagata da …»), e un conduttore entrato dopo glieli rimborsa per i suoi giorni; negli altri nessuno stava al posto dell\'inquilino, e sono rimasti nelle rate di chi è uscito, che li ha pagati. ' . $restituire,
                    $v['strada'] === 'B' => 'Alla fine della locazione del %s%s il conguaglio è stato scritto: i giorni dopo l\'uscita li ha presi con la coppia chi paga al posto dell\'inquilino, che li ha pagati («pagata da …»); un conduttore entrato dopo glieli rimborsa per i suoi giorni.',
                    // R2: con «Scrivi», i giorni in cui nessuno stava al posto dell'inquilino sono rimasti nelle rate di chi è uscito.
                    ($v['scelta'] ?? null) === Subentro::CONGUAGLIO_SCRITTO => 'Alla fine della locazione del %s%s il conguaglio è stato scritto, ma in una parte dei giorni dopo l\'uscita nessuno stava al posto dell\'inquilino: quei giorni sono rimasti nelle rate di chi è uscito, che li ha pagati. ' . $restituire,
                    $v['strada'] === 'C' => 'Alla fine della locazione del %s%s resta un coinquilino: il conguaglio dei giorni dopo l\'uscita non c\'è, quei giorni restano nelle rate di chi è uscito, e chi li paga lo decidono le parti.',
                    ($v['scelta'] ?? null) === Subentro::CONGUAGLIO_NON_SCRITTO => 'Alla fine della locazione del %s%s il conguaglio non è stato scritto: i giorni dopo l\'uscita sono rimasti nelle rate di chi è uscito, che li ha pagati. ' . $restituire,
                    default => 'Alla fine della locazione del %s%s nessun conguaglio ha spostato i giorni dopo l\'uscita: sono rimasti nelle rate di chi è uscito, che li ha pagati. ' . $restituire,
                }, CarbonImmutable::parse($v['uscita_del'])->format('d/m/Y'), $dove);
            })
            ->values()->all();
        // R19 della Fase 1-bis della .50: una fine di prima della .50, senza la scelta, che il prospetto legge come strada A′. Dalla .42 alla
        // .49 il pannello indicava per quei giorni un saldo manuale dal Wallet: se c'è un saldo manuale sull'unità e sulla gestione, può averli
        // già regolati, e la restituzione non si fa due volte.
        // Giro stretto della .50 (G19): la scelta nulla nella voce non vuol dire una fine di prima della .50 (con «Scrivi» un piano che la coppia
        // non ha preso ha la scelta nulla): conta il registro della fine, che dalla .50 ha la scelta. E il saldo è uno scritto dal Wallet
        // (`manuale`), non quello dell'importatore.
        $senzaScelta = $dellUscita->filter(fn ($v) => $v['strada'] === 'A' && ($v['scelta'] ?? null) === null
            && $immobileId !== null && $this->finePrimaDellaCinquanta($immobileId, (string) $v['uscita_del']));
        if ($immobileId !== null && $senzaScelta->isNotEmpty()) {
            $gestioni = PianoRate::whereIn('id', $senzaScelta->pluck('piano_rate_id')->filter()->unique()->all())->pluck('gestione_id')->all();
            if ($gestioni !== [] && DB::table('saldi')->whereNull('subentro_id')->where('origine', 'manuale')->where('immobile_id', $immobileId)->whereIn('gestione_id', $gestioni)->exists()) {
                $frasi[] = 'Sull\'unità c\'è un saldo manuale scritto dal Wallet sulla stessa gestione: se è stato scritto per i giorni dopo l\'uscita, come il pannello indicava nelle versioni precedenti alla fine di una locazione, quei giorni sono già regolati, e la parte da restituire non si restituisce una seconda volta.';
            }
        }
        if ($dellUscita->contains(fn ($v) => ! empty($v['oppure']))) {
            $frasi[] = 'Dopo la fine con il conguaglio scritto un passaggio ha cambiato chi paga al posto dell\'inquilino senza spostare il conguaglio, e lo ha detto: dal giorno di quel passaggio la voce nomina chi ha il conguaglio, che ha pagato quei giorni, e chi è entrato, se le parti hanno regolato fra loro quella parte.';
        }

        return $frasi;
    }

    /**
     * G19: la fine della locazione dell'unità in quel giorno è di prima della .50 (il suo registro non ha la scelta sul conguaglio). Sulla
     * pertinenza la fine trovata è il figlio, e la scelta sta nel registro del padre.
     */
    private function finePrimaDellaCinquanta(int $immobileId, string $giorno): bool
    {
        $fine = Subentro::where('immobile_id', $immobileId)->where('tipo_passaggio', 'fine_locazione')->whereDate('decorrenza', substr($giorno, 0, 10))->orderByDesc('id')->first();

        return $fine !== null && ! isset($fine->capo()->registro['conguaglio']);
    }

    /**
     * Le frasi in testa: cosa il documento non è, su quali numeri si regge, come divide, come si legge. Con `$conLaFine` (1.11.0-beta.50)
     * il «pagata da …» non è solo il ripiego: è anche chi è uscito o chi paga al posto dell'inquilino alla fine di una locazione.
     *
     * @param list<array{nome: string, fonte: string}> $piani
     * @param list<array{nome: string, motivo: string}> $esclusi
     * @return list<string>
     */
    private function intestazione(array $piani, array $esclusi, bool $conLaFine = false): array
    {
        $nomi = implode('», «', array_column($piani, 'nome'));
        $frasi = [
            // R21 (Fase 1-bis): la regola come norma, con le fonti di §3.1 delle pertinenze.
            'Non è un riparto e non è una richiesta di pagamento del condominio. Verso il condominio risponde il proprietario: l\'amministratore può esigere i contributi solo dai condòmini, non dal conduttore, anche quando le rate sono intestate a lui (artt. 1123 c.c. e 63 disp. att. c.c.; Cass. 17039/2007). È il prospetto con cui il proprietario regola con il conduttore le spese che il riparto pone a carico dell\'inquilino.',
            $piani === []
                ? 'Nessun piano rate approvato in questo esercizio: il prospetto è vuoto.'
                : sprintf('Gli importi sono quelli del preventivo, dal piano rate approvato («%s»): il conguaglio definitivo si fa sul rendiconto approvato dell\'esercizio.', $nomi),
            'Le spese si dividono per giorni di conduzione, con la stessa regola del riparto, e non per consumi: dove c\'è la contabilizzazione del calore il risultato è un altro.',
            'Accanto a ogni voce: «nelle sue rate» se la voce è già intestata al conduttore nelle rate — un pagamento diretto, che non lo rende debitore del condominio (Cass. 19650/2006); «con il conguaglio del cambio d\'inquilino del …» se un cambio d\'inquilino registrato dopo la generazione del piano ha dato quei giorni al nuovo conduttore con il conguaglio — anche questo è un pagamento diretto; '
                . ($conLaFine
                    ? '«pagata da …» se l\'ha pagata qualcun altro: un titolare perché nei giorni del piano nessun inquilino era registrato, oppure, alla fine di una locazione, chi è uscito o chi paga al posto dell\'inquilino, come dicono le frasi qui sotto — è la parte da rimborsare.'
                    : '«pagata da …» se l\'ha pagata un titolare perché nei giorni del piano nessun inquilino era registrato — è la parte da rimborsare.'),
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
