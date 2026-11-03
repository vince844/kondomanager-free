<?php

namespace App\Services\Riparto;

use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\TitolaritaImmobile;
use App\Services\CalcoloQuoteService;
use App\Services\Subentro\ChiPagaAlPostoDellInquilino;
use App\Services\Subentro\FermataDopoScrivi;
use App\Services\Subentro\RegolaDelPiano;
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
 * un coinquilino che resta, nelle fini registrate dalle beta .50–.54 con la fermata del coinquilino, restano nelle rate di chi esce, e chi
 * li paga lo decidono le parti (strada C). Dopo «Annulla il conguaglio» le parti hanno regolato fra loro, e il prospetto non li tocca
 * (decisioni 43 e 46). Nel cambio d'inquilino con «Non scriverlo» i giorni di chi esce li rimborsa chi è entrato, non un coinquilino che
 * restava (Coda 358).
 *
 * Dalla 1.11.0-beta.55 (Coda 245) dove la locazione è continuata con un coinquilino e la fine ha la regola del piano nel registro, la parte
 * dell'inquilino di tutto l'anello si legge con la regola del piano (strada P, `ProspettoRegolaDelPiano`): a ogni conduttore la sua parte per
 * quota e per giorni, accanto ciò che ne ha nelle sue rate, i conguagli dei passaggi e chi rimborsa chi, come lo dice il passaggio che l'ha
 * lasciato fra le parti. Anche sul piano della 1.10 senza riparto registrato, con l'anello delle quote pure degli inquilini (decisione 115.1); se
 * lì la regola del piano non si ricalcola più, il piano resta fuori e si dice perché. Dopo una fermata (Coda 352) i giorni della quota che il
 * passaggio non ha spostato li ha pagati chi è rimasto a carico della fermata, e la voce lo dice.
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
        private readonly ProspettoRegolaDelPiano $delPiano = new ProspettoRegolaDelPiano(),
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
        // Coda 359 (decisione 115.3): i piani approvati dell'esercizio che il prospetto non sa leggere, per la testa e per la stampa: gli esclusi di
        // `pianiDellEsercizio` (in bozza, di un altro esercizio) non sono fra questi.
        $approvatiFuori = [];
        // Coda 245 (1.11.0-beta.55): i gruppi (la fine con la regola del piano e la gestione) dei piani letti con la regola del piano.
        $gruppiDelPiano = [];
        $conduttoriIds = $righeInquilino->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        foreach ($piani as $piano) {
            $dettaglio = DettaglioRiparto::perPiano($piano);
            $fonte = $dettaglio['fonte']['tipo'];
            if ($fonte === DettaglioRiparto::ANTEPRIMA) {
                $esclusi[] = ['nome' => $piano->nome, 'motivo' => 'senza rate generate'];
                $approvatiFuori[] = (string) $piano->nome;
                continue;
            }
            $righe = collect($dettaglio['righe'])->filter(fn ($r) => (int) ($r['immobile_id'] ?? 0) === (int) $unita->id);
            $riparto = $righe->where('tipo', 'riparto');
            // Coda 245 (1.11.0-beta.55): sull'anello di una fine con la regola del piano (la strada P) la parte dell'inquilino si legge con la regola del piano, dalle
            // righe congelate del riparto o, sul piano della 1.10 senza riparto registrato, dalle quote pure degli inquilini nelle rate (decisione 115.1): il riparto
            // ricostruito con i titolari di oggi non serve, e il controllo che segue non la riguarda.
            $anello = $this->anelloDelPiano($piano, (int) $unita->id);

            // R2 (Fase 1-bis): un piano della 1.10 senza riparto registrato si ricostruisce con i titolari di oggi. Se
            // le persone del riparto ricostruito non sono quelle che hanno le rate, le voci non si riconducono alle rate:
            // il piano esce, e si dice perché. Non si tira a indovinare.
            if ($anello === null && $fonte === DettaglioRiparto::RICOSTRUITO && ! $this->stessePersone($riparto, (int) $piano->id, (int) $unita->id)) {
                $esclusi[] = ['nome' => $piano->nome, 'motivo' => 'generato da una versione precedente senza riparto registrato, e i titolari dell\'unità sono cambiati dopo la generazione: le voci non si possono ricondurre alle rate'];
                $approvatiFuori[] = (string) $piano->nome;
                continue;
            }
            $letto = ['id' => (int) $piano->id, 'nome' => $piano->nome, 'tipo' => $piano->tipo, 'fonte' => $fonte, 'dedotto' => $piano->esercizio_id === null];

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

            // Coda 245 (1.11.0-beta.55): sull'anello di una fine con la regola del piano (la strada P) tutta la parte «Inquilino» del piano si legge con la
            // regola del piano, voce per voce, e i passaggi sull'anello dicono che cosa hanno scritto e che cosa hanno lasciato fra le parti.
            if ($anello !== null) {
                $p = $this->delPiano->voci($piano, (int) $unita->id, $anello['passaggi'], $riparto, $base, $conduttoriIds, $anello['cambi']);
                if (isset($p['ferma'])) {
                    // Decisione 115.1: sul piano senza riparto registrato la regola del piano non si ricalcola più (la parte dell'inquilino non si separa): il piano
                    // resta fuori, con il perché e le cifre della fine che apre l'anello.
                    $esclusi[] = ['nome' => $piano->nome, 'motivo' => 'generato da una versione precedente senza riparto registrato, e '
                        . RegolaDelPiano::pianoFermo($p['ferma'], $anello['passaggi'], fn (CarbonImmutable $d) => $d->format('d/m/Y'))];
                    $approvatiFuori[] = (string) $piano->nome;
                    continue;
                }
                $pianiLetti[] = $letto + ['del_piano' => true];
                $chiave = (int) $anello['fine']->id . '|' . (int) $piano->gestione_id;
                $gruppiDelPiano[$chiave] ??= ['passaggi' => $anello['passaggi'], 'cambi' => $anello['cambi'], 'gestione_id' => (int) $piano->gestione_id, 'nessuno' => [], 'paganti' => []];
                foreach ($p['conduttori'] as $anagraficaId => $lista) {
                    $voci[$anagraficaId] = [...($voci[$anagraficaId] ?? []), ...$lista];
                }
                array_push($senza, ...$p['senza']);
                $gruppiDelPiano[$chiave]['paganti'] = array_values(array_unique([...$gruppiDelPiano[$chiave]['paganti'], ...$p['paganti']]));
                foreach ($p['nessuno'] as $anagraficaId => $cifra) {
                    $gruppiDelPiano[$chiave]['nessuno'][$anagraficaId] = ($gruppiDelPiano[$chiave]['nessuno'][$anagraficaId] ?? 0) + $cifra;
                }
                continue;
            }
            $pianiLetti[] = $letto;
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
        // Coda 245: per ogni gruppo della regola del piano, che cosa hanno fatto i passaggi sull'anello, persona per persona; chi non è conduttore sta al
        // posto dell'inquilino nei giorni senza conduttore.
        $delPiano = [];
        $senzaDelPiano = null;
        $frasiDelPiano = [];
        foreach ($gruppiDelPiano as $g) {
            foreach ($this->delPiano->composizione($g['passaggi'], (int) $unita->id, $g['gestione_id'], $g['nessuno'], $g['paganti'], $g['cambi']) as $anagraficaId => $c) {
                if (in_array((int) $anagraficaId, $conduttoriIds, true)) {
                    $delPiano[$anagraficaId] = array_merge_recursive($delPiano[$anagraficaId] ?? ['conguagli' => [], 'regolati' => [], 'rimborsi' => []], $c);
                    $voci[$anagraficaId] ??= [];
                    continue;
                }
                $chi = ['chi' => (string) Anagrafica::whereKey((int) $anagraficaId)->value('nome')];
                $senzaDelPiano ??= ['conguagli' => [], 'regolati' => [], 'rimborsi' => []];
                foreach (['conguagli', 'regolati', 'rimborsi'] as $k) {
                    array_push($senzaDelPiano[$k], ...array_map(fn (array $x) => $x + $chi, $c[$k]));
                }
            }
            array_push($frasiDelPiano, ...$this->delPiano->frasi($g['passaggi'], (int) $unita->id, $g['gestione_id']));
        }
        // Una fine con la regola del piano su più gestioni dell'unità ha un anello per gestione, e la stessa frase: si dice una volta.
        $frasiDelPiano = array_values(array_unique($frasiDelPiano));

        // Il totale del ruolo inquilino è quello che il prospetto divide: le voci dei conduttori, già versato compreso, e quelle senza
        // conduttore (giro sulle correzioni della .47: il già versato «dell'unità» sui ripieghi entra qui).
        $totaleInquilino = (int) array_sum(array_map(fn (array $v) => array_sum(array_column($v, 'importo')), $voci)) + (int) array_sum(array_column($senza, 'importo'));

        $conduttori = collect($voci)->map(fn (array $v, int $anagraficaId) => $this->sezione($anagraficaId, $this->accorpa($v), $righeInquilino, $periodoEsercizio, $delPiano[$anagraficaId] ?? null))
            ->sortBy(fn ($c) => ($c['periodo']['dal'] ?? '9999') . '|' . $c['nome'])->values()->all();
        $voceSenza = $this->accorpa($senza);
        $totaleSenza = (int) array_sum(array_column($voceSenza, 'importo'));
        if ($gruppiDelPiano !== []) {
            // Chi sta al posto dell'inquilino nei giorni senza conduttore, con la regola del piano: la sua parte, i conguagli e chi rimborsa chi, come un
            // conduttore. I giorni che non copre nessuno restano a chi è uscito (da restituire), e non entrano qui.
            $senzaDelPiano ??= ['conguagli' => [], 'regolati' => [], 'rimborsi' => []];
            $dellaRegola = array_filter($voceSenza, fn ($v) => ($v['strada'] ?? null) === 'P' && $v['modo'] === 'piano');
            $senzaDelPiano['da_rimborsare'] = (int) array_sum(array_column($dellaRegola, 'importo')) - (int) array_sum(array_column($dellaRegola, 'nelle_rate'))
                - (int) array_sum(array_column($senzaDelPiano['conguagli'], 'importo')) - (int) array_sum(array_column($senzaDelPiano['regolati'], 'importo'));
        }
        $frasiDellUscita = [...$this->frasiDellUscita($voci, $senza, (int) $unita->id, count($pianiLetti) > 1), ...$frasiDelPiano];
        // Il «pagata da …» della fine di una locazione: chi è uscito (strada A′) o chi paga al posto dell'inquilino (strada B).
        $pagateAllaFine = collect([...array_merge([], ...array_values($voci)), ...$senza])->contains(fn ($v) => in_array($v['strada'] ?? null, ['A', 'B'], true) && ! empty($v['pagato_da']));
        // Coda 364: e chi è entrato con il conguaglio di un cambio d'inquilino, per i giorni di un conduttore entrato dopo. Coda 366: o con la parte regolata fra le parti.
        $pagateDopoIlCambio = self::modiDegliEntratiDopo(array_merge([], ...array_values($voci)));

        return [
            'unita'         => ['id' => (int) $unita->id, 'etichetta' => $unita->etichetta_estesa],
            'esercizio'     => ['id' => (int) $esercizio->id, 'nome' => $esercizio->nome, 'dal' => $periodoEsercizio?->dal->toDateString(), 'al' => $periodoEsercizio?->al->toDateString()],
            'intestazione'  => [...$this->intestazione($pianiLetti, $esclusi, $pagateAllaFine, $gruppiDelPiano !== [], $this->soloConLaRegolaDelPiano($voci, $senza), $approvatiFuori, $pagateDopoIlCambio), ...$frasiDellUscita],
            'piani'         => $pianiLetti,
            'piani_esclusi' => $esclusi,
            'piani_approvati_fuori' => $approvatiFuori,
            'conduttori'    => $conduttori,
            'senza_conduttore' => ['voci' => $voceSenza, 'totale' => $totaleSenza] + ($senzaDelPiano !== null ? ['regola_del_piano' => $senzaDelPiano] : []),
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
     * Coda 369: dopo un cambio della catena, dei giorni dopo l'uscita di chi era entrato che restano a lui (la strada A′, e nella B i giorni che nessuno copriva) il
     * già versato gli va accanto, e la riga «già versato» di chi ha la riga lo toglie.
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
                if (isset($pz['ripreso']) && $suo !== 0) {
                    $this->vociDelRipreso($pz, $r, $competenza, $suo, (int) $partiNette[$i][$j], $base, $voci);
                }
                if (isset($pz['uscita'])) {
                    $this->dividiDopoLUscita($pz, $r, $competenza, $suo, (int) $partiNette[$i][$j], $base, $partecipanti, $righeInquilino, $voci, $senza);
                    continue;
                }
                // Ultimo giro della .50: il pezzo prima dell'uscita senza giorni (la fine prima della voce) non è una voce.
                if ($pz['tratti'] === null && $suo === 0) {
                    continue;
                }
                $voci[$pz['anagrafica_id']][] = $base($r, $competenza) + ['importo' => $suo, 'giorni' => $pz['giorni'], 'tratti' => $pz['tratti'], 'modo' => $pz['modo'], 'pagato_da' => null, 'conguaglio_del' => $pz['conguaglio_del']];
                // Coda 366: anche il pezzo «regolato fra le parti» della catena della decisione 83.3 (il conguaglio annullato, poi l'uscita «Scrivi» di chi era entrato):
                // chi è stato conduttore nei suoi giorni li divide come nel cambio scritto, e le due catene danno gli stessi numeri (decisione 84.1).
                if ($pz['modo'] === 'conguaglio' || $pz['modo'] === 'regolato') {
                    $this->chiEntraDopoIlCambio($pz, $r, $competenza, (int) $partiNette[$i][$j], $immobileId, $base, $partecipanti, $righeInquilino, $voci);
                }
            }
        }
        foreach ($lordi as $i => $pz) {
            $passa = $i > 0 ? (int) $pz['importo'] - (int) $netti[$i]['importo'] : 0;
            if (isset($pz['uscita'])) {
                // Strada B: la parte del già versato «dell'unità» dei giorni dopo l'uscita è passata con la coppia, e la riga «già versato»
                // di chi ha la riga la toglie. Strada A′ e coinquilino: resta a chi esce, e la restituzione è già al netto.
                if ($pz['uscita']['strada'] === 'B') {
                    $chiave = $anagraficaId . '|' . (int) ($primo['conto_id'] ?? 0);
                    // R2: dei giorni che nessuno copriva il già versato resta a chi esce, come nella strada A′.
                    // Coda 413: nel pezzo che chi esce aveva ceduto (`ripreso`) anche il già versato dei giorni scoperti è di chi li aveva, con la sua voce al lordo (`vociDelRipreso`).
                    $scoperti = isset($pz['uscita']['scoperti']) && ! isset($pz['ripreso']) ? self::comune($pz['tratti'], $pz['uscita']['scoperti']) : null;
                    $passato = $scoperti === null ? (int) $pz['importo'] - (int) $netti[$i]['importo']
                        : self::copertaEScoperta((int) $pz['importo'], $pz['tratti'], $scoperti)[0] - self::copertaEScoperta((int) $netti[$i]['importo'], $pz['tratti'], $scoperti)[0];
                    $spostato[$chiave] = ($spostato[$chiave] ?? 0) + $passato;
                    $passa -= $passato;
                }
                // Coda 369: se chi esce ha la riga, la parte che gli resta è già nella sua riga «già versato» (le rate sono sue). Se era entrato con il conguaglio di un
                // cambio della catena, o con la parte regolata fra le parti, quei giorni li ha pagati con la coppia, al netto: la parte gli va accanto, come per il suo
                // pezzo prima dell'uscita, e la riga di chi ha la riga la toglie.
                if ($pz['modo'] === 'rate') {
                    continue;
                }
            }
            // Decisione 84.1: anche il pezzo «regolato fra le parti» (83.3) si porta la parte del già versato dei suoi giorni, come nel cambio
            // scritto: le due catene danno gli stessi numeri.
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
     * Coda 364 (1.11.0-beta.55): il pezzo di chi è entrato con il conguaglio scritto di un cambio d'inquilino (DL1). I giorni li ha pagati lui, con il
     * conguaglio; se nei suoi giorni è entrato dopo un altro conduttore (un inizio di locazione, anche il rientro di chi era uscito), la parte di quei
     * giorni si divide fra chi c'è per quota e per giorni, al netto del già versato «dell'unità», come la strada A′ con «Non scriverlo» e la strada B
     * del cambio in due passi: chi è entrato dopo la rimborsa a chi ha il conguaglio («pagata da …»), e nella sezione di chi ha il conguaglio è da
     * ricevere, o da restituire se in quei giorni l'entrata supera la spesa (`entrato_dopo`). La sua voce «con il conguaglio» resta quella della coppia.
     * Come nella strada A′ (Code 358 e 360) non dividono i coinquilini di chi aveva la riga al primo cambio della catena, né chi ne ha preso il posto:
     * i loro giorni sono nelle loro rate. Senza nessun altro nei giorni del pezzo la voce resta com'è; una parte da zero non è una voce.
     * Coda 366: anche il pezzo «regolato fra le parti» della decisione 83.3, che chi è entrato ha pagato con l'accordo fra le parti e non con il conguaglio:
     * le voci che scrive lo portano (`regolato`), e la stampa non dice «con il conguaglio».
     * Coda 367: un pezzo con `regolato_il` (un passaggio di chi è entrato regolato fra le parti, senza una fine «Scrivi» dopo) si divide solo nei giorni prima di quel giorno.
     * Coda 406 (decisione 144): con `regolato_fino` anche nei giorni dopo, perché l'accordo copre solo i giorni della parte con cui è fatto (`giorniDellaParte`).
     *
     * @param array<string, mixed> $pz il pezzo di `catenaDeiConduttori`, con `cambio`
     * @param array<string, mixed> $r la riga del riparto
     */
    private function chiEntraDopoIlCambio(array $pz, array $r, ?InsiemePeriodi $competenza, int $netto, int $immobileId, \Closure $base, Collection $partecipanti, Collection $righeInquilino, array &$voci): void
    {
        $chi = (int) $pz['anagrafica_id'];
        $cambio = $pz['cambio'];
        $restano = $this->uscite['restano|' . $cambio['passaggio']->id] ??= $this->coinquiliniEChiNeHaPresoIlPosto($immobileId, (int) $cambio['uscente'], $cambio['passaggio']);
        $modo = $pz['modo'] === 'regolato' ? ['regolato' => true] : [];
        // Coda 367 (decisioni 43, 46 e 83.3): dal giorno di un passaggio di chi è entrato che le parti hanno regolato fra loro (`regolato_il`: il conguaglio annullato, o la
        // casella di prima della .50), senza una fine «Scrivi» dopo, il prospetto non va oltre l'accordo: si divide solo la parte dei giorni prima, e il resto resta nella voce.
        $tratti = $pz['tratti'];
        if (isset($pz['regolato_il']) && isset($pz['regolato_fino'])) {
            // Coda 406 (decisione 144): l'accordo copre i giorni della parte con cui è fatto, fino a `regolato_fino`; prima e dopo i giorni si dividono, per giorni (a pari resto il
            // centesimo resta a chi divide, come con `regolato_il` da solo).
            $accordo = $tratti->intersezione(new PeriodoCompetenza(CarbonImmutable::parse($pz['regolato_il']), CarbonImmutable::parse($pz['regolato_fino'])));
            $fuori = $tratti;
            foreach ($accordo?->periodi() ?? [] as $t) {
                $fuori = $fuori?->meno($t);
            }
            if ($fuori === null) {
                return;
            }
            if ($accordo !== null) {
                $netto = (int) MoneyHelper::ripartisciPerQuote($netto, ['fuori' => (float) $fuori->giorni(), 'accordo' => (float) $accordo->giorni()])['fuori'];
            }
            $tratti = $fuori;
        } elseif (isset($pz['regolato_il'])) {
            if ($pz['regolato_il'] <= $tratti->dal()->toDateString()) {
                return;
            }
            $netto = (int) ProRataTemporis::dividi($netto, $tratti, $pz['regolato_il'])['uscente'];
            $tratti = $tratti->intersezione(new PeriodoCompetenza($tratti->dal(), CarbonImmutable::parse($pz['regolato_il'])->subDay()));
        }
        $v = [];
        $sc = [];
        $this->dividiSuiConduttori($netto, $tratti, (string) Anagrafica::whereKey($chi)->value('nome'), $base($r, $competenza) + ['conguaglio_del' => $pz['conguaglio_del']] + $modo,
            $partecipanti->reject(fn ($p) => in_array((int) $p->anagrafica_id, $restano, true))->values(), $righeInquilino, $v, $sc);
        unset($v[$chi]);
        // I giorni senza nessun conduttore restano nella voce di chi ha il conguaglio, come prima.
        foreach ($v as $anagraficaId => $lista) {
            $parte = (int) array_sum(array_column($lista, 'importo'));
            if ($parte === 0) {
                continue;
            }
            $voci[$anagraficaId] = [...($voci[$anagraficaId] ?? []), ...$lista];
            $voci[$chi][] = array_replace($base($r, $competenza), ['importo' => -$parte, 'giorni' => null, 'tratti' => null, 'modo' => 'entrato_dopo', 'pagato_da' => null,
                'conguaglio_del' => $pz['conguaglio_del'], 'da_chi' => (string) Anagrafica::whereKey((int) $anagraficaId)->value('nome')] + $modo);
        }
    }

    /**
     * Decisioni 32, 77 e 79 (1.11.0-beta.50): il pezzo dei giorni dopo l'uscita di una riga «nelle sue rate», alla fine di una locazione
     * senza un nuovo inquilino o con «Non scriverlo» (`uscitaDellaLocazione`). `$lordo` è il pezzo della voce, `$netto` lo stesso al netto
     * del già versato «dell'unità», come la coppia (D8).
     * - Strada B: i giorni li ha pagati con la coppia chi sta al posto dell'inquilino; si dividono come un ripiego, pezzo per pezzo di chi
     *   ha pagato, sui conduttori registrati dopo, e il resto è «senza conduttore». Coda 352: un pezzo dopo una fermata ha più persone che
     *   l'hanno pagato (`quote`), e ogni voce si divide fra loro.
     * - Strada A′: sono nelle rate di chi esce. La sua sezione li dice, dopo l'uscita, e dice la parte da restituirgli; la parte si divide
     *   come un ripiego pagato da lui: il conduttore registrato dopo gliela rimborsa, i giorni senza conduttore gli si restituiscono fra le
     *   parti. Se il conduttore registrato dopo è lui stesso (il rinnovo, il rientro), i suoi giorni sono già nelle sue rate: la voce
     *   torna «nelle sue rate» per quei giorni, e né la restituzione né il rimborso li contano (le righe della stessa persona si fondono).
     *   Nel cambio d'inquilino con «Non scriverlo» i coinquilini che restano (`restano`) non dividono i giorni in cui un altro conduttore sta
     *   al posto di chi esce, e dividono quelli in cui sono soli.
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
            $voci[$chi][] = $suaVoce($lordo, (int) $pz['giorni'], $pz['tratti'], $pz['modo'], $dellUscita + ['legate' => $u['legate'] ?? null]
                + ['nota' => sprintf('giorni dopo l\'uscita, dal %s: resta un coinquilino, e chi li paga lo decidono le parti%s', $dal,
                    ($u['legate'] ?? null) !== null ? '; saldi del Wallet legati a quella fine: ' . $u['legate'] : '')]);

            return;
        }

        if ($u['strada'] === 'B') {
            // Fase 1-bis della .50 (R2): i giorni dopo l'uscita che nessuno copriva non sono nella coppia. Restano nelle rate di chi esce, come
            // la strada A′, con la loro dicitura; il resto lo hanno pagato con la coppia i pagatori.
            $scoperti = isset($u['scoperti']) ? self::comune($pz['tratti'], $u['scoperti']) : null;
            if ($scoperti !== null) {
                [$netto, $nettoScoperto] = self::copertaEScoperta($netto, $pz['tratti'], $scoperti);
                [, $lordoScoperto] = self::copertaEScoperta($lordo, $pz['tratti'], $scoperti);
                // Coda 413: nel pezzo che chi esce aveva ceduto (`ripreso`, Coda 380) i giorni scoperti non sono nelle sue rate: li rimborsa a chi era uscito con «Non scriverlo»
                // (`vociDelRipreso`), e chi entra in quei giorni li rimborsa a lui.
                $this->restaAChiEsce(array_replace($pz, ['tratti' => $scoperti, 'giorni' => $scoperti->giorni()]), $r, $competenza, $lordoScoperto, $nettoScoperto, $base, $partecipanti, $righeInquilino, $voci, $senza,
                    ['strada' => 'A', 'scelta' => Subentro::CONGUAGLIO_SCRITTO, 'uscita_del' => $u['del']], $u['chi_esce'] ?? null, isset($pz['ripreso']));
            }
            $pagatori = $u['pagatori'] ?? [];
            $importi = MoneyHelper::ripartisciPerQuote($netto, array_map(fn (array $p) => (float) $p['periodo']->giorni(), $pagatori));
            foreach ($pagatori as $k => $p) {
                $v = [];
                $sc = [];
                $this->dividiSuiConduttori((int) ($importi[$k] ?? 0), $p['periodo'], $p['nome'], $base($r, $competenza) + $dellUscita + ['oppure' => self::oppure($p['fermate'])],
                    $partecipanti, $righeInquilino, $v, $sc);
                // Coda 352: un pezzo dopo una fermata ha più persone che lo hanno pagato (`quote`): ogni voce si divide fra loro, la parte di chi riceve per prima.
                $perChi = fn (array $x, ?int $anagraficaId) => ! isset($p['quote']) ? [$x] : array_values(array_filter(array_map(fn (array $q, $c) => array_replace($x, ['importo' => (int) $c,
                    'pagato_da' => $q['nome'], 'oppure' => self::oppure($anagraficaId === null ? $q['fermate'] : $this->fermateDopoIlConduttore($q['fermate'], $anagraficaId, (int) ($r['immobile_id'] ?? 0), $p['periodo']))]),
                    $p['quote'], MoneyHelper::ripartisciPerQuote((int) $x['importo'], array_column($p['quote'], 'quota'))), fn (array $y) => (int) $y['importo'] !== 0));
                foreach ($sc as $x) {
                    array_push($senza, ...$perChi($x, null));
                }
                foreach ($v as $anagraficaId => $lista) {
                    $oppure = self::oppure($this->fermateDopoIlConduttore($p['fermate'], (int) $anagraficaId, (int) ($r['immobile_id'] ?? 0), $p['periodo']));
                    $voci[$anagraficaId] = [...($voci[$anagraficaId] ?? []), ...array_merge([], ...array_map(fn (array $x) => $perChi(array_replace($x, ['oppure' => $oppure]), (int) $anagraficaId), $lista))];
                }
            }

            return;
        }

        // Strada A′. Coda 358: nel cambio d'inquilino i coinquilini che restavano non dividono i giorni di chi esce (`uscitaDellaLocazione`, `restano`); le
        // righe d'inquilino restano tutte, così i giorni che coprono non diventano «senza conduttore».
        if (! isset($u['restano'])) {
            $this->restaAChiEsce($pz, $r, $competenza, $lordo, $netto, $base, $partecipanti, $righeInquilino, $voci, $senza, $dellUscita, $u['pagato_da'] ?? null);

            return;
        }
        $conduttori = $partecipanti->reject(fn ($p) => in_array((int) $p->anagrafica_id, $u['restano'], true))->values();
        // Solo nei giorni in cui un altro conduttore sta al posto di chi esce: chi è entrato con il cambio, chi gli è subentrato, chi entra dopo, o chi esce stesso, al
        // suo rientro. Nei giorni in cui chi resta è solo (chi è entrato è già uscito, e nessuno ha preso il suo posto) per il motore la parte dell'inquilino è sua, anche
        // quella nelle rate di chi esce, e la divide come gli altri; i giorni senza nessuno restano «senza conduttore», come prima.
        $altri = null;
        foreach ($conduttori as $p) {
            $tratto = $this->titolari->trattoEffettivo($p, $pz['tratti'], $righeInquilino);
            $suoi = $tratto !== null ? $pz['tratti']->intersezione($tratto) : null;
            if ($suoi !== null) {
                $altri = $altri === null ? $suoi : $altri->unione($suoi);
            }
        }
        $soli = $pz['tratti'];
        foreach ($altri?->periodi() ?? [] as $t) {
            $soli = $soli?->meno($t);
        }
        if ($soli === null || $altri === null) {
            $this->restaAChiEsce($pz, $r, $competenza, $lordo, $netto, $base, $soli === null ? $conduttori : $partecipanti, $righeInquilino, $voci, $senza, $dellUscita, $u['pagato_da'] ?? null);

            return;
        }
        [$nettoAltri, $nettoSoli] = self::copertaEScoperta($netto, $pz['tratti'], $soli);
        [$lordoAltri, $lordoSoli] = self::copertaEScoperta($lordo, $pz['tratti'], $soli);
        $this->restaAChiEsce(array_replace($pz, ['tratti' => $altri, 'giorni' => $altri->giorni()]), $r, $competenza, $lordoAltri, $nettoAltri, $base, $conduttori, $righeInquilino, $voci, $senza,
            $dellUscita, $u['pagato_da'] ?? null);
        $this->restaAChiEsce(array_replace($pz, ['tratti' => $soli, 'giorni' => $soli->giorni()]), $r, $competenza, $lordoSoli, $nettoSoli, $base, $partecipanti, $righeInquilino, $voci, $senza,
            $dellUscita, $u['pagato_da'] ?? null);
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
    private function restaAChiEsce(array $pz, array $r, ?InsiemePeriodi $competenza, int $lordo, int $netto, \Closure $base, Collection $partecipanti, Collection $righeInquilino, array &$voci, array &$senza, array $dellUscita, ?string $pagatoDa, bool $ceduti = false): void
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
        // Coda 376: il pezzo A′ chiuso alla seconda uscita di chi è uscito (Coda 370) dice anche fino a quando; gli altri arrivano alla fine della voce.
        $nota = ['nota' => isset($pz['fino_al'])
            ? sprintf('giorni dopo l\'uscita, dal %s al %s', $dal, CarbonImmutable::parse($pz['fino_al'])->format('d/m/Y'))
            : sprintf('giorni dopo l\'uscita, dal %s', $dal)];
        if ($lordo - $suoiLordi !== 0 && ! $ceduti) {
            $voci[$chi][] = $suaVoce($lordo - $suoiLordi, null, null, $pz['modo'], $dellUscita + $nota);
        }
        if ($netto - $suoiNetti !== 0) {
            $voci[$chi][] = $suaVoce(-($netto - $suoiNetti), null, null, 'restituzione', $dellUscita + $nota + ['a_chi' => $pagatoDa]);
        }
        // Il rinnovo e il rientro: i giorni di chi è tornato sono suoi, nelle sue rate, e la voce si somma a quella prima dell'uscita. Coda 413: non nei giorni che aveva ceduti (`$ceduti`), che
        // paga a chi li aveva (`vociDelRipreso`): lì la sua parte resta a lui perché nessuno gliela rimborsa.
        if ($suoi !== [] && ! $ceduti) {
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
     * Coda 379: dopo un passaggio che le parti hanno regolato fra loro (decisioni 43 e 46), senza la catena della 83.3, il pezzo che resta va fino alla prossima uscita di chi lo ha,
     * con un conguaglio suo (`uscitaDopoLAccordo`), e la catena riparte da lì; prima andava sempre fino alla fine della voce.
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
        // Coda 252.1 (1.11.0-beta.51): il giorno del passaggio che la catena ha appena seguito. La ricerca della fine «prima della voce»
        // riparte da lì su chi è entrato, un passaggio alla volta, e non torna a una fine già vista né a una prima.
        $dopoIl = null;
        // Coda 364 (1.11.0-beta.55): il primo cambio d'inquilino della catena, con chi aveva la riga: i pezzi dopo lo portano, perché chi entra dopo divida
        // i giorni di chi è entrato con il conguaglio senza i coinquilini di chi aveva la riga (`chiEntraDopoIlCambio`).
        $cambio = [];
        // Coda 370: l'uscita di chi è uscito con la strada A′ dopo il suo rientro, con la coppia scritta o (Coda 375) regolata fra le parti: la catena riparte da lì (sotto).
        $riuscita = null;
        // Coda 380: dopo un cambio «Non scriverlo», l'uscita con la coppia scritta di chi è entrato (chi ne ha preso il posto), che aveva già ceduto quei giorni (sotto).
        $ripreso = null;
        while (true) {
            // Coda 405: il passo che riparte da un'uscita già trovata (`$riuscita`) non cerca un passaggio prima della voce: lo ha già letto.
            $seguita = $riuscita !== null;
            // Decisioni 32 e 77 (1.11.0-beta.50): anche la fine senza un nuovo inquilino. Sul box il passaggio trovato è il figlio, e la
            // scelta sta nel registro del padre (`capo()`).
            $passaggio = $riuscita ?? Subentro::where('immobile_id', $immobileId)->where('tipo_passaggio', 'fine_locazione')
                ->where('anagrafica_uscente_id', $chi)->whereNotIn('id', $visti)
                ->whereDate('decorrenza', '>', $restante->dal()->toDateString())->whereDate('decorrenza', '<=', $restante->al()->toDateString())
                ->orderBy('decorrenza')->orderBy('id')->first();
            // Ultimo giro della .50: una voce con la competenza tutta dal giorno dell'uscita in poi (un'entrata o una spesa dei soli mesi
            // dopo) non ha la fine dopo il suo primo giorno, ma prende la strada di quella fine: con «Scrivi» la coppia l'ha portata tutta a
            // chi paga al posto dell'inquilino, con «Non scriverlo» resta a chi è uscito e gli si restituisce (chiusura della .50).
            // Coda 252.1: a ogni passo della catena, non solo al primo. Una voce con la competenza tutta dopo un cambio d'inquilino in un passo non è di chi è
            // uscito: con «Scrivi» (e il piano preso dalla coppia) è di chi è entrato, e la fine di chi è entrato, se viene prima della voce,
            // è la prossima da seguire.
            $riuscita = null;
            if ($passaggio === null) {
                $passaggio = $this->finePrimaDellaVoce($piano, $chi, $immobileId, $restante, $visti, $dopoIl);
            } elseif (! $seguita) {
                // Coda 405, la voce che comincia dopo un passaggio: se prima della voce (dopo il passo di prima) chi ha il pezzo è uscito con un passaggio e, dopo un suo rientro, esce
                // di nuovo dentro la voce, la catena legge prima il passaggio prima della voce, come quando cade nella voce: i passaggi si leggono nell'ordine delle date. Un passaggio
                // che le parti hanno regolato fra loro resta un accordo (decisioni 43, 46 e 83.3: chi è entrato non divide i giorni dell'accordo, e l'uscita dopo si legge con
                // `uscitaDopoLAccordo`); un cambio «Scrivi» ha dato la voce a chi è entrato (Coda 252.1), e la sua uscita dopo si legge come la sua (Coda 380). Prima la ricerca
                // prendeva l'uscita dentro la voce e saltava il passaggio prima: chi era entrato rimborsava i giorni dell'accordo, o una voce che aveva pagato con la coppia, e la fine
                // senza la scelta restituiva i giorni dopo.
                $prima = $this->finePrimaDellaVoce($piano, $chi, $immobileId, $restante, $visti, $dopoIl);
                if ($prima !== null) {
                    $passaggio = $prima;
                }
            }
            $capo = $passaggio?->capo();
            // Decisione 83.3 (1.11.0-beta.50): il cambio d'inquilino «regolato fra le parti» (la casella di prima della .50, o il conguaglio
            // annullato) seguito da una fine di chi era entrato con la coppia scritta su questa unità: la catena continua, e il pezzo di chi
            // era entrato è «regolato fra le parti». Senza una fine scritta dopo resta la lettura delle decisioni 43 e 46.
            $regolato = $passaggio !== null && $this->regolatoConUnaFineDopo($passaggio, $capo, $piano, $immobileId, $restante);
            // Coda 379 (decisioni 43, 46 e 83.3): l'accordo fra le parti di un passaggio regolato fra loro (il conguaglio annullato, o la casella di prima della .50 nel cambio), senza
            // la catena della 83.3, non va oltre la prossima uscita di chi ha il pezzo, dopo un suo rientro, con il conguaglio (`uscitaDopoLAccordo`): il pezzo dell'accordo finisce il
            // giorno prima, con `regolato_il`, e la catena riparte da quell'uscita, che legge come ogni uscita (la strada B o la A′, il cambio, o un altro accordo). Prima la catena si
            // fermava all'accordo e il pezzo andava fino alla fine della voce: chi entrava dopo non rimborsava i giorni che chi paga al posto dell'inquilino aveva preso con la coppia di
            // quell'uscita, o che erano rimasti nelle rate di chi è uscito con «Non scriverlo».
            $dopoLAccordo = $passaggio !== null && ! $regolato && self::regolatoFraLeParti($passaggio, $capo)
                ? $this->uscitaDopoLAccordo($chi, $immobileId, $restante, $passaggio, $visti) : null;
            if ($dopoLAccordo !== null) {
                $d = ProRataTemporis::dividi($resto, $restante, $dopoLAccordo->decorrenza);
                // L'uscita dopo l'accordo può cadere prima della voce o nel suo primo giorno (`finePrimaDellaVoce`): allora il pezzo dell'accordo non ha giorni, e tiene il posto, a zero.
                $tratti = $dopoLAccordo->decorrenza->toDateString() > $restante->dal()->toDateString()
                    ? $restante->intersezione(new PeriodoCompetenza($restante->dal(), $dopoLAccordo->decorrenza->toImmutable()->subDay())) : null;
                $pezzi[] = ['anagrafica_id' => $chi, 'importo' => (int) $d['uscente'], 'giorni' => (int) $d['giorni_uscente'], 'modo' => $modo, 'conguaglio_del' => $del,
                    'tratti' => $tratti] + ($tratti !== null ? ['regolato_il' => $passaggio->decorrenza->toDateString()] + $this->giorniDellaParte($passaggio, $chi, $immobileId, $tratti, $modo) : [])
                    + $cambio;
                $visti[] = (int) $passaggio->id;
                $dopoIl = $passaggio->decorrenza->toDateString();
                $resto = (int) $d['entrante'];
                $restante = $restante->intersezione(new PeriodoCompetenza($dopoLAccordo->decorrenza->toImmutable(), $restante->al()));
                $riuscita = $dopoLAccordo;
                continue;
            }
            if ($passaggio === null || ($capo->conguaglioAnnullato() && ! $regolato)) {
                // Dopo «Annulla il conguaglio» le parti hanno regolato fra loro (decisioni 43 e 46): il pezzo che resta lo dice, per il pannello.
                if ($passaggio !== null) {
                    $regolatoIl = $passaggio->decorrenza->toDateString();
                    $accordo = $passaggio;
                }
                break;
            }
            $chiave = implode('|', ['uscita', $piano->id, $passaggio->id, $immobileId, $chi]);
            if (! array_key_exists($chiave, $this->uscite)) {
                $this->uscite[$chiave] = $this->uscitaDellaLocazione($piano, $passaggio, $capo, $immobileId, $chi);
            }
            $uscita = $this->uscite[$chiave];
            // Coda 365: dopo un cambio d'inquilino della catena, il cambio con «Non scriverlo» di chi è entrato lascia i giorni dopo la sua uscita nel conguaglio del
            // primo cambio, e chi non li divide si legge lì, come per il pezzo di chi è entrato (`chiEntraDopoIlCambio`): i coinquilini di chi aveva la riga, e chi ne ha
            // preso il posto. Chi è entrato dopo il primo cambio con un inizio (o chi rientra) non è un coinquilino di chi aveva la riga, e divide anche questi giorni.
            // Su una copia: la cache è per passaggio e persona, e una catena che parte da chi esce tiene chi non divide del suo cambio.
            if ($uscita !== null && $cambio !== [] && $uscita['strada'] === 'A' && $passaggio->anagrafica_entrante_id !== null) {
                $restano = $this->uscite['restano|' . $cambio['cambio']['passaggio']->id]
                    ??= $this->coinquiliniEChiNeHaPresoIlPosto($immobileId, (int) $cambio['cambio']['uscente'], $cambio['cambio']['passaggio']);
                $uscita = array_diff_key($uscita, ['restano' => true]) + ($restano !== [] ? ['restano' => $restano] : []);
            }
            if ($uscita !== null) {
                $d = ProRataTemporis::dividi($resto, $restante, $passaggio->decorrenza);
                // Con la fine prima della voce, o nel suo primo giorno, il pezzo prima dell'uscita non ha giorni: tiene il posto, a zero.
                $giorniPrima = $passaggio->decorrenza->toDateString() > $restante->dal()->toDateString()
                    ? $restante->intersezione(new PeriodoCompetenza($restante->dal(), $passaggio->decorrenza->toImmutable()->subDay())) : null;
                $pezzi[] = ['anagrafica_id' => $chi, 'importo' => (int) $d['uscente'], 'giorni' => (int) $d['giorni_uscente'], 'modo' => $modo, 'conguaglio_del' => $del,
                    'tratti' => $giorniPrima] + $cambio;
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
                    // Coda 370: con la strada A′ i giorni dopo l'uscita restano a chi esce solo fino alla sua uscita dopo il rientro, se quella ha scritto la coppia su
                    // questa unità e gestione (una fine o un cambio «Scrivi»): i giorni dopo li ha presi con la coppia chi paga al posto dell'inquilino, o chi entra, e la
                    // catena riparte da quell'uscita (la strada B della fine, con i pagatori dal suo registro, o il pezzo «con il conguaglio» di chi entra). Prima il pezzo
                    // andava fino alla fine della voce, e il prospetto restituiva a chi era uscito, o faceva rimborsare da chi entrava dopo, i giorni che il Wallet gli
                    // aveva già tolto con la coppia: una seconda volta. Coda 375: anche se le parti hanno regolato fra loro quell'uscita («Annulla il conguaglio»): la catena
                    // riparte da lì e la legge come ogni passaggio regolato fra le parti (con la fine «Scrivi» dopo di chi era entrato la segue, decisione 83.3; altrimenti i
                    // giorni restano dov'erano fino alla prossima uscita di chi è uscito con un conguaglio suo, Coda 379, o fino alla fine della voce). Con «Non scriverlo» no: i
                    // giorni dopo restano nella strada A′ della prima uscita.
                    // Le ricerche partono dal giorno dell'uscita, non dal primo giorno della voce: con l'uscita prima della voce (`finePrimaDellaVoce`) anche l'uscita dopo può
                    // cadere prima della voce, o nel suo primo giorno, e il pezzo di mezzo non ha giorni.
                    $riuscita = $uscita['strada'] === 'A' ? $this->riuscitaCheChiudeIlPezzo($piano, $chi, $immobileId, $dopo, [...$visti, (int) $passaggio->id], $passaggio->decorrenza->toDateString()) : null;
                    // Coda 380: il cambio con «Non scriverlo» lascia i giorni dopo l'uscita a chi esce (Sara), e chi entra (Ines) glieli rimborsa con il prospetto. Se chi entra li
                    // aveva già ceduti a chi esce con un passaggio prima nella catena (il cambio «Scrivi» Ines → Sara) ed esce poi con la coppia scritta su questa unità e gestione,
                    // quella coppia gli dà a credito nel Wallet i giorni dopo la sua uscita, che non erano più suoi, e li dà a chi paga al posto dell'inquilino, o a chi entra
                    // con il cambio. Da quell'uscita la catena la segue come la sua (la strada B, o il pezzo «con il conguaglio» di chi entra: decisioni 45, nota del 12/10, e 77.4),
                    // e i giorni dopo restano da restituire a chi era uscito con «Non scriverlo», a carico di chi ne ha preso il posto, che li ha avuti a credito (`vociDelRipreso`).
                    // Prima il pezzo A′ andava fino alla fine della voce: chi entrava dopo rimborsava chi era uscito con «Non scriverlo», e nessuno rimborsava la coppia.
                    if ($riuscita === null && $ripreso === null && $uscita['strada'] === 'A' && $passaggio->anagrafica_entrante_id !== null) {
                        $entra = (int) $passaggio->anagrafica_entrante_id;
                        $suoPezzo = collect($pezzi)->first(fn (array $p) => (int) $p['anagrafica_id'] === $entra);
                        $poi = $suoPezzo !== null ? $this->uscitaConLaCoppia($piano, $entra, $immobileId, $dopo, [...$visti, (int) $passaggio->id], $passaggio->decorrenza->toDateString()) : null;
                        if ($poi !== null) {
                            $e = ProRataTemporis::dividi((int) $d['entrante'], $dopo, $poi->decorrenza);
                            $pezzi[] = self::pezzoDiMezzo($chi, (int) $e['uscente'], $modo, $del, $dopo, $poi, ['uscita' => $uscita]);
                            $ripreso = ['dal' => $poi->decorrenza->toDateString(), 'da' => $chi, 'modo' => $modo, 'conguaglio_del' => $del, 'uscita' => $uscita,
                                'chi' => $entra, 'modo_di_chi' => (string) $suoPezzo['modo'], 'del_di_chi' => $suoPezzo['conguaglio_del']];
                            $visti[] = (int) $passaggio->id;
                            $dopoIl = $passaggio->decorrenza->toDateString();
                            $resto = (int) $e['entrante'];
                            $restante = $dopo->intersezione(new PeriodoCompetenza($poi->decorrenza->toImmutable(), $dopo->al()));
                            $chi = $entra;
                            $modo = (string) $suoPezzo['modo'];
                            $del = $suoPezzo['conguaglio_del'];
                            $riuscita = $poi;
                            continue;
                        }
                    }
                    if ($riuscita !== null) {
                        $e = ProRataTemporis::dividi((int) $d['entrante'], $dopo, $riuscita->decorrenza);
                        // Coda 376: il pezzo A′ chiuso alla seconda uscita dice fino a quando (`restaAChiEsce`).
                        $pezzi[] = self::pezzoDiMezzo($chi, (int) $e['uscente'], $modo, $del, $dopo, $riuscita,
                            ['uscita' => $uscita, 'fino_al' => $riuscita->decorrenza->toImmutable()->subDay()->toDateString()]);
                        $visti[] = (int) $passaggio->id;
                        $dopoIl = $passaggio->decorrenza->toDateString();
                        $resto = (int) $e['entrante'];
                        $restante = $dopo->intersezione(new PeriodoCompetenza($riuscita->decorrenza->toImmutable(), $dopo->al()));
                        continue;
                    }
                    $pezzi[] = ['anagrafica_id' => $chi, 'importo' => (int) $d['entrante'], 'giorni' => $dopo->giorni(), 'modo' => $modo, 'conguaglio_del' => $del, 'tratti' => $dopo,
                        'uscita' => $uscita];
                }

                return self::conIlRipreso($pezzi, $ripreso);
            }
            // Coda 413: un cambio d'inquilino che non ha preso il piano (le rate non emesse quel giorno: «si ricalcola ancora») lascia la voce dov'è, nelle rate di chi ha il pezzo (decisione 43);
            // se un'uscita dopo di chi ha il pezzo l'ha presa, la catena la segue da lì, come se il cambio non ci fosse. Prima si fermava al cambio, e i giorni dopo l'uscita che quell'uscita ha scritto
            // nel Wallet restavano nelle sue rate: chi entrava dopo non li rimborsava a chi li aveva presi con la coppia. Decisioni 156.2 e 158: lo stesso se il piano non si ricalcola più (emesso o
            // fermo per un incasso, senza ricalcolo: decisione 40), anche se l'ha preso poi il passaggio di un altro: le quote di chi ha il pezzo non dipendono da lui, e l'uscita dopo si legge
            // come se il cambio non ci fosse (la strada A′ della fine che non l'ha preso). Se il piano si ricalcola ancora si ferma come prima.
            if (! $regolato && $passaggio->anagrafica_entrante_id !== null && ! $capo->conguaglioRinunciato() && ! $piano->presoNelConguaglioDa($passaggio)
                && ($this->presoDaUnUscitaDopo($piano, $chi, $immobileId, $restante, $passaggio, $visti) || $this->nonSiRicalcolaPiu($piano))) {
                $visti[] = (int) $passaggio->id;

                continue;
            }
            if (! $regolato && ($passaggio->anagrafica_entrante_id === null || $capo->conguaglioRinunciato() || ! $piano->presoNelConguaglioDa($passaggio))) {
                // Il cambio d'inquilino che le parti hanno regolato fra loro (decisioni 43 e 46): il pezzo che resta lo dice, per il pannello.
                if ($passaggio->anagrafica_entrante_id !== null && $capo->conguaglioRinunciato() && ! $capo->conguaglioNonScritto()) {
                    $regolatoIl = $passaggio->decorrenza->toDateString();
                    $accordo = $passaggio;
                }
                break;
            }
            $visti[] = (int) $passaggio->id;
            $dopoIl = $passaggio->decorrenza->toDateString();
            $d = ProRataTemporis::dividi($resto, $restante, $passaggio->decorrenza);
            // Con il cambio prima della voce, o nel suo primo giorno, il pezzo di chi esce non ha giorni: tiene il posto, a zero.
            $pezzi[] = ['anagrafica_id' => $chi, 'importo' => (int) $d['uscente'], 'giorni' => (int) $d['giorni_uscente'], 'modo' => $modo, 'conguaglio_del' => $del,
                'tratti' => $passaggio->decorrenza->toDateString() > $restante->dal()->toDateString()
                    ? $restante->intersezione(new PeriodoCompetenza($restante->dal(), $passaggio->decorrenza->toImmutable()->subDay())) : null] + $cambio;
            $dopo = $restante->intersezione(new PeriodoCompetenza($passaggio->decorrenza->toImmutable(), $restante->al()));
            if ($dopo === null) {
                return self::conIlRipreso($pezzi, $ripreso);
            }
            $cambio = $cambio ?: ['cambio' => ['passaggio' => $passaggio, 'uscente' => $chi]];
            $chi = (int) $passaggio->anagrafica_entrante_id;
            $resto = (int) $d['entrante'];
            $restante = $dopo;
            $modo = $regolato ? 'regolato' : 'conguaglio';
            $del = $passaggio->decorrenza->toDateString();
        }
        $pezzi[] = ['anagrafica_id' => $chi, 'importo' => $resto, 'giorni' => $restante->giorni(), 'modo' => $modo, 'conguaglio_del' => $del, 'tratti' => $restante]
            + (isset($regolatoIl) ? ['regolato_il' => $regolatoIl] + $this->giorniDellaParte($accordo, $chi, $immobileId, $restante, $modo) : []) + $cambio;

        return self::conIlRipreso($pezzi, $ripreso);
    }

    /**
     * Coda 406 (decisione 144): un accordo fra le parti copre solo i giorni della parte con cui è fatto. Nel cambio d'inquilino regolato fra le parti sono i giorni di chi è
     * entrato con quel cambio, fino alla sua uscita (con il cambio che fa rientrare chi ha il pezzo, o con la sua fine; non con il cambio verso un altro, che ne prende il posto nell'accordo:
     * allora, dalla Coda 413, fino all'uscita di chi ne ha preso il posto, nello stesso modo, decisioni 148.1 e 153.3); nella fine senza chi entra, i giorni dopo l'uscita fino al
     * rientro di chi è uscito. Dopo, i giorni sono di nuovo di chi ha il pezzo, con il suo conguaglio, e chi entra in quei giorni, che dell'accordo non è parte, li divide come
     * nei giorni di un cambio con il conguaglio scritto (decisione 117, Coda 364): `regolato_fino` è l'ultimo giorno dell'accordo dentro i giorni del pezzo
     * (`chiEntraDopoIlCambio`, e il pannello con `chiHaPagatoDopo`). Senza un'uscita della parte (o un rientro) dentro il pezzo l'accordo va fino alla fine, come prima. Solo per
     * i pezzi di chi è entrato con un cambio d'inquilino (con il conguaglio o regolato fra le parti), che chi entra dopo divide: i giorni di chi ha la riga sono nelle sue rate.
     *
     * @return array{regolato_fino?: string}
     */
    private function giorniDellaParte(Subentro $accordo, int $chi, int $immobileId, InsiemePeriodi $tratti, string $modo): array
    {
        if ($modo === 'rate') {
            return [];
        }
        $parte = $accordo->anagrafica_entrante_id !== null ? (int) $accordo->anagrafica_entrante_id : null;
        $dal = $accordo->decorrenza->toDateString();
        while (true) {
            $dopo = Subentro::where('immobile_id', $immobileId)->whereDate('decorrenza', '>', $dal)->whereDate('decorrenza', '<=', $tratti->al()->toDateString());
            $fine = ($parte !== null
                ? $dopo->where('tipo_passaggio', 'fine_locazione')->where('anagrafica_uscente_id', $parte)
                : $dopo->whereIn('tipo_passaggio', ['fine_locazione', 'inizio_locazione'])->where('anagrafica_entrante_id', $chi))
                ->orderBy('decorrenza')->orderBy('id')->first();
            // Coda 413 (decisioni 148.1 e 153.3): il cambio con cui la parte passa i suoi giorni a un altro (Zoe → Elsa) non li riporta a chi ha il pezzo: chi ne prende il posto entra
            // nell'accordo, e l'accordo finisce con la sua uscita, come con quella della parte (la sua fine, o un cambio verso chi ha il pezzo). Prima l'accordo non finiva più.
            if ($fine !== null && $parte !== null && $fine->anagrafica_entrante_id !== null && (int) $fine->anagrafica_entrante_id !== $chi) {
                $parte = (int) $fine->anagrafica_entrante_id;
                $dal = $fine->decorrenza->toDateString();

                continue;
            }

            return $fine !== null ? ['regolato_fino' => $fine->decorrenza->toImmutable()->subDay()->toDateString()] : [];
        }
    }

    /**
     * Coda 413: un'uscita di chi ha il pezzo dopo il passaggio dato, dentro i giorni che restano, che ha preso il piano nel conguaglio (`PianoRate::presoNelConguaglioDa`): la fine o il cambio
     * d'inquilino con cui la catena prosegue quando il passaggio dato non l'ha preso.
     *
     * @param list<int> $visti
     */
    private function presoDaUnUscitaDopo(PianoRate $piano, int $chi, int $immobileId, InsiemePeriodi $restante, Subentro $passaggio, array $visti): bool
    {
        return Subentro::where('immobile_id', $immobileId)->where('tipo_passaggio', 'fine_locazione')->where('anagrafica_uscente_id', $chi)
            ->whereNotIn('id', [...$visti, (int) $passaggio->id])
            ->whereDate('decorrenza', '>', $passaggio->decorrenza->toDateString())->whereDate('decorrenza', '<=', $restante->al()->toDateString())
            ->orderBy('decorrenza')->orderBy('id')->get()
            ->contains(fn (Subentro $s) => $piano->presoNelConguaglioDa($s));
    }

    /**
     * Decisioni 156.2 e 158: il piano non si ricalcola più (`PianoRate::eImmutabile`: emesso, con un incasso o preso da un passaggio). Le sue quote sono rimaste a chi le aveva alla generazione
     * (decisione 40), e un cambio d'inquilino che non l'ha preso non le sposta, anche se l'ha preso poi il passaggio di un altro. Una volta per piano in un calcolo.
     */
    private function nonSiRicalcolaPiu(PianoRate $piano): bool
    {
        return $this->uscite['piano_fermo|' . $piano->id] ??= $piano->eImmutabile();
    }

    /**
     * Coda 380: i pezzi della catena dal giorno dell'uscita con la coppia di chi ha preso il posto di chi è uscito con «Non scriverlo» portano `ripreso`
     * (`catenaDeiConduttori`): `dividiLeRate` aggiunge le voci di `vociDelRipreso`. Senza, i pezzi come sono.
     *
     * @param list<array<string, mixed>> $pezzi
     * @param array<string, mixed>|null $ripreso
     * @return list<array<string, mixed>>
     */
    private static function conIlRipreso(array $pezzi, ?array $ripreso): array
    {
        if ($ripreso === null) {
            return $pezzi;
        }

        return array_map(fn (array $pz) => ($pz['tratti'] ?? null) instanceof InsiemePeriodi && $pz['tratti']->dal()->toDateString() >= $ripreso['dal']
            ? $pz + ['ripreso' => $ripreso] : $pz, $pezzi);
    }

    /**
     * Il pezzo A′ fra un'uscita e l'uscita dopo di chi è uscito (Coda 370), o di chi ne ha preso il posto (Coda 380), con le chiavi date (`uscita`, `fino_al`). Se l'uscita dopo cade prima della
     * voce o nel suo primo giorno il pezzo non ha giorni: tiene il posto, a zero e senza `uscita`, come il pezzo prima di un'uscita prima della voce.
     *
     * @param array<string, mixed> $chiavi
     * @return array<string, mixed>
     */
    private static function pezzoDiMezzo(int $chi, int $importo, string $modo, ?string $del, InsiemePeriodi $dopo, Subentro $poi, array $chiavi): array
    {
        if ($poi->decorrenza->toDateString() <= $dopo->dal()->toDateString()) {
            return ['anagrafica_id' => $chi, 'importo' => 0, 'giorni' => 0, 'modo' => $modo, 'conguaglio_del' => $del, 'tratti' => null];
        }
        $tratti = $dopo->intersezione(new PeriodoCompetenza($dopo->dal(), $poi->decorrenza->toImmutable()->subDay()));

        return ['anagrafica_id' => $chi, 'importo' => $importo, 'giorni' => $tratti->giorni(), 'modo' => $modo, 'conguaglio_del' => $del, 'tratti' => $tratti] + $chiavi;
    }

    /**
     * Coda 380: la prima uscita di chi è entrato con un cambio «Non scriverlo», dentro i giorni dopo quel cambio, che ha scritto la coppia su questa unità e gestione
     * (le righe in `saldi` con l'id del capo, come in `riuscitaCheChiudeIlPezzo`): una fine o un cambio «Scrivi», non annullati. Null se non c'è. Si cerca dal giorno del cambio (`$dal`),
     * anche prima della voce.
     *
     * @param list<int> $visti
     */
    private function uscitaConLaCoppia(PianoRate $piano, int $chi, int $immobileId, InsiemePeriodi $dopo, array $visti, string $dal): ?Subentro
    {
        return Subentro::where('immobile_id', $immobileId)->where('tipo_passaggio', 'fine_locazione')->where('anagrafica_uscente_id', $chi)->whereNotIn('id', $visti)
            ->whereDate('decorrenza', '>', $dal)->whereDate('decorrenza', '<=', $dopo->al()->toDateString())
            ->orderBy('decorrenza')->orderBy('id')->get()
            ->first(fn (Subentro $s) => ! $s->capo()->conguaglioAnnullato()
                && DB::table('saldi')->where('subentro_id', $s->capo()->id)->where('immobile_id', $immobileId)->where('gestione_id', $piano->gestione_id)->exists());
    }

    /**
     * Coda 380: le voci di un pezzo con `ripreso`, oltre a quelle del pezzo. I giorni sono di chi era uscito con «Non scriverlo» (`da`): li ha pagati, e gli si
     * restituiscono, come nella strada A′ (le voci si sommano alle sue). Glieli rimborsa chi ne aveva preso il posto (`chi`), perché la coppia della sua uscita glieli ha dati
     * a credito nel Wallet e li ha passati a chi viene dopo: nella sua sezione la parte da rimborsare e il credito, che non cambia il suo totale.
     *
     * Decisione 150.2, con il già versato «dell'unità» (decisione 17, D8): chi era uscito ha la voce al lordo e accanto la riga «— già versato» di quei giorni, come il pezzo dei giorni dopo
     * la sua uscita in `dividiLeRate` (trappola 7 della catena del prospetto): li ha pagati al netto, con il conguaglio, e la restituzione è al netto. Il credito di chi ne aveva preso il posto
     * è al netto, come la coppia nel Wallet. Così il totale a carico di ognuno torna con il Wallet; senza il già versato lordo e netto sono uguali, e le voci sono quelle di prima.
     *
     * @param array<string, mixed> $pz
     * @param array<string, mixed> $r la riga del riparto
     */
    private function vociDelRipreso(array $pz, array $r, ?InsiemePeriodi $competenza, int $lordo, int $netto, \Closure $base, array &$voci): void
    {
        $rp = $pz['ripreso'];
        $u = $rp['uscita'];
        $dellUscita = ['strada' => $u['strada'], 'scelta' => $u['scelta'], 'uscita_del' => $u['del']];
        $nota = ['nota' => sprintf('giorni dopo l\'uscita, dal %s', CarbonImmutable::parse($u['del'])->format('d/m/Y'))];
        $fine = CarbonImmutable::parse($rp['dal'])->format('d/m/Y');
        $voce = fn (array $altro) => array_replace($base($r, $competenza), ['giorni' => null, 'tratti' => null, 'pagato_da' => null, 'conguaglio_del' => null], $altro);
        $voci[$rp['da']][] = $voce(['importo' => $lordo, 'modo' => $rp['modo'], 'conguaglio_del' => $rp['conguaglio_del']] + $dellUscita + $nota);
        if ($lordo !== $netto) {
            // La riga ha la forma di quella di `dividiLeRate`, e si somma alla sua.
            $voci[$rp['da']][] = $voce(['conto' => ($r['conto_nome'] ?? 'Voce') . ' — già versato', 'tabella_id' => 0, 'tabella' => null, 'millesimi' => null, 'quota_inquilino' => null,
                'competenza' => null, 'importo' => -($lordo - $netto), 'modo' => $rp['modo'], 'conguaglio_del' => $rp['conguaglio_del']]);
        }
        $voci[$rp['da']][] = $voce(['importo' => -$netto, 'modo' => 'restituzione', 'conguaglio_del' => $rp['conguaglio_del'], 'a_chi' => $u['pagato_da'] ?? null] + $dellUscita + $nota);
        // Coda 413: con i giorni che nessuno copriva dopo l'uscita di chi ne aveva preso il posto (R2: la coppia di quell'uscita non li ha), il suo credito nel Wallet è solo la parte dei
        // giorni coperti, e la riga lo dice con la cifra della coppia. Gli altri giorni li rimborsa lo stesso a chi era uscito con «Non scriverlo» (decisione 145: tutti i giorni dopo quell'uscita),
        // in una riga a parte, e chi entra in quei giorni li rimborsa a lui (`restaAChiEsce`, senza le righe «nelle sue rate»: le sue rate non li hanno, li aveva ceduti). Prima il credito era
        // tutto il pezzo, e i giorni scoperti stavano anche «nelle sue rate»: i totali tornavano, la riga del credito no.
        $scoperti = ($pz['uscita']['scoperti'] ?? null) instanceof InsiemePeriodi && ($pz['tratti'] ?? null) instanceof InsiemePeriodi ? self::comune($pz['tratti'], $pz['uscita']['scoperti']) : null;
        [$coperto, $scoperto] = $scoperti !== null ? self::copertaEScoperta($netto, $pz['tratti'], $scoperti) : [$netto, 0];
        if ($coperto !== 0) {
            $voci[$rp['chi']][] = $voce(['importo' => $coperto, 'modo' => 'proprietario', 'pagato_da' => $u['pagato_da'] ?? null] + $dellUscita
                + ['nota' => sprintf('giorni dopo la sua uscita del %s, passati ad altri con il conguaglio di quella uscita', $fine)]);
            // Coda 413 (P-2, decisione 156.4): se chi ne aveva preso il posto aveva il pezzo «regolato fra le parti» (la catena della 83.3), il credito non è un accordo fra le parti ma la coppia
            // della sua uscita nel Wallet: la riga ha il modo `credito`, che la stampa chiama «a suo credito nel Wallet» e il piede conta a parte. Prima portava il modo del pezzo, e nel piede la
            // parte regolata fra le parti si azzerava con il credito.
            $voci[$rp['chi']][] = $voce(['importo' => -$coperto, 'modo' => $rp['modo_di_chi'] === 'regolato' ? 'credito' : $rp['modo_di_chi'], 'conguaglio_del' => $rp['del_di_chi']]
                + ['nota' => sprintf('il conguaglio della sua uscita del %s, a suo %s nel Wallet', $fine, $coperto > 0 ? 'credito' : 'debito')]);
        }
        if ($scoperto !== 0) {
            $voci[$rp['chi']][] = $voce(['importo' => $scoperto, 'modo' => 'proprietario', 'pagato_da' => $u['pagato_da'] ?? null] + $dellUscita
                + ['nota' => sprintf('giorni dopo la sua uscita del %s in cui nessuno stava al posto dell\'inquilino, rimasti fuori dalla coppia di quella uscita', $fine)]);
        }
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
     * valeva solo con la strada B: con le altre la voce restava «nelle rate» di chi è uscito senza la restituzione. La prima fine prima
     * della voce che la catena legge, nell'ordine delle date (sotto); null se non c'è (e la voce resta com'era). Il conguaglio annullato dava null fino alla Coda 367: vedi sotto.
     *
     * Coda 252.1 (1.11.0-beta.51): anche il cambio d'inquilino in un passo (la fine con chi entra) se la voce è tutta dopo. Con «Scrivi» e il
     * piano preso dalla coppia la voce è di chi è entrato; con «Non scriverlo» resta nelle rate di chi è uscito (la strada A′); su un piano che la
     * coppia non ha preso resta com'è (decisione 43); regolata fra le parti la legge `catenaDeiConduttori` (Code 367 e 371, sotto). `$visti` e
     * `$dopoIl` fanno proseguire la ricerca su chi è entrato: i passaggi già seguiti non tornano, e si cerca dopo il giorno dell'ultimo.
     *
     * Coda 367: anche il passaggio regolato fra le parti, il conguaglio annullato o la casella di prima della .50: `catenaDeiConduttori` lo legge come quando cade
     * nella voce, e la voce non passa a chi è entrato dopo l'accordo. Coda 371: a ogni passo della catena, anche sul primo pezzo (chi aveva la riga): con una fine
     * «Scrivi» dopo di chi era entrato la catena la segue (decisione 83.3), e la voce che comincia dopo il cambio va a chi ha la coppia, come le voci di tutto l'anno.
     *
     * Con più uscite di chi ha il pezzo prima della voce (un'uscita, un rientro, un'altra uscita) la catena le legge nell'ordine delle date, come nella voce: la prima che si legge,
     * poi le altre dalle ricerche che partono dal giorno del passaggio letto (`riuscitaCheChiudeIlPezzo`, `uscitaConLaCoppia`, `uscitaDopoLAccordo`). Prima si prendeva l'ultima, e una voce
     * che comincia il giorno dell'ultima uscita, o dopo, saltava i passaggi di prima: il cambio «Scrivi» che l'aveva data a chi è entrato, il ritorno con «Non scriverlo» che la lasciava a lui
     * (Coda 380, decisione 145), o la fine «Scrivi» che l'aveva data a chi paga al posto dell'inquilino quando l'ultima fine non ha scritto niente.
     *
     * @param list<int> $visti
     */
    private function finePrimaDellaVoce(PianoRate $piano, int $chi, int $immobileId, InsiemePeriodi $periodo, array $visti = [], ?string $dopoIl = null): ?Subentro
    {
        foreach (Subentro::where('immobile_id', $immobileId)->where('tipo_passaggio', 'fine_locazione')->where('anagrafica_uscente_id', $chi)
            ->whereNotIn('id', $visti ?: [0])->when($dopoIl !== null, fn ($q) => $q->whereDate('decorrenza', '>', $dopoIl))
            ->whereDate('decorrenza', '<=', $periodo->dal()->toDateString())
            ->orderBy('decorrenza')->orderBy('id')->get() as $fine) {
            if ($this->siLeggePrimaDellaVoce($piano, $fine, $immobileId, $chi)) {
                return $fine;
            }
        }

        return null;
    }

    /** Un'uscita di chi ha il pezzo, prima della voce o nel suo primo giorno, che la catena legge (`finePrimaDellaVoce`). */
    private function siLeggePrimaDellaVoce(PianoRate $piano, Subentro $fine, int $immobileId, int $chi): bool
    {
        $capo = $fine->capo();
        // Coda 367 (decisioni 43, 46 e 83.3): il passaggio che le parti hanno regolato fra loro (il conguaglio annullato di un cambio d'inquilino o di una fine, o la
        // casella di prima della .50) torna alla catena, che lo legge come quando cade nella voce: un cambio con una fine «Scrivi» dopo di chi era entrato la segue,
        // altrimenti si ferma e il pezzo porta `regolato_il`, perché chi è entrato dopo non divida i giorni dell'accordo (`chiEntraDopoIlCambio`). La casella di prima
        // della .50 alla fine senza chi entra la leggeva già (la strada A′, `rinunciato`). Coda 371: anche sul primo pezzo, quello di chi aveva la riga. Senza una fine
        // «Scrivi» dopo la voce resta com'era, nelle sue rate (decisioni 43 e 46), e il pezzo porta `regolato_il` per il pannello; con la fine la coppia ha portato
        // la voce a chi paga al posto dell'inquilino, e chi entra dopo gliela rimborsa: altrimenti la voce restava «nelle rate» di chi aveva la riga, e chi l'ha
        // presa con la coppia non la riaveva da nessuno.
        if ($capo->conguaglioAnnullato() || ($capo->conguaglioRinunciato() && ! $capo->conguaglioNonScritto())) {
            return true;
        }
        $chiave = implode('|', ['uscita', $piano->id, $fine->id, $immobileId, $chi]);
        if (! array_key_exists($chiave, $this->uscite)) {
            $this->uscite[$chiave] = $this->uscitaDellaLocazione($piano, $fine, $capo, $immobileId, $chi);
        }
        // Il cambio con chi entra: la voce passa a lui solo con «Scrivi» e il piano preso; altrimenti la strada è quella di `uscitaDellaLocazione`.
        if ($fine->anagrafica_entrante_id !== null && $this->uscite[$chiave] === null) {
            return ($capo->registro['conguaglio']['scelta'] ?? null) === Subentro::CONGUAGLIO_SCRITTO && $piano->presoNelConguaglioDa($fine);
        }

        return $this->uscite[$chiave] !== null;
    }

    /**
     * Fase 1-bis della 1.11.0-beta.50 (R7, R8): chi ha pagato, su un piano e un'unità, la parte a carico dell'inquilino nelle rate di una
     * persona uscita, dal giorno dato in poi. È la catena del prospetto (`catenaDeiConduttori`, `uscitaDellaLocazione`), perché il pannello
     * dell'inizio di una locazione nomini chi il prospetto farà rimborsare, piano per piano: la strada B dice chi ha la coppia, la strada A′
     * chi è uscito, il coinquilino delle beta (strada C) che le parti decidono, il cambio d'inquilino regolato fra le parti che lo hanno regolato
     * loro. Sull'anello di una fine con la regola del piano (strada P) `piano_scritto` o `piano_non_scritto`: chi rimborsa chi lo dicono i passaggi
     * sull'anello (`ProspettoRegolaDelPiano`), non i giorni.
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
     * Coda 364 (1.11.0-beta.55): con `cambi_scritti` i cambi d'inquilino che hanno dato quei giorni con il conguaglio scritto a chi è entrato (il giorno e chi è
     * uscito), perché il pannello del rientro di chi era uscito dica il cambio, come dice la fine «Scrivi».
     *
     * Coda 413: con `regolato_con_il_conguaglio` chi ha i giorni «regolati fra le parti» con il conguaglio scritto di un cambio (Sara, entrata con il cambio Ines → Sara «Scrivi», che è poi
     * uscita con un passaggio regolato fra le parti): l'uscita regolata è la sua, e il pannello la nomina. Vuoto se un pezzo «regolato» è di chi ha le righe o della catena 83.3.
     *
     * @return array{come: string, pagato_da: list<string>, presi_da: list<string>, per_modo: array<string, list<string>>, fini_scritte: list<array{del: string, chi_esce: ?string}>, cambi_scritti: list<array{del: string, chi_esce: string}>, regolato_con_il_conguaglio: list<string>} `come`: scritto, non_scritto, regolato, coinquilino, piano_scritto, piano_non_scritto, a_mano o misto
     */
    public function chiHaPagatoDopo(PianoRate $piano, int $anagraficaId, int $immobileId, InsiemePeriodi $periodo, CarbonImmutable $dal): array
    {
        if ($dal->gt($periodo->al())) {
            return ['come' => 'a_mano', 'pagato_da' => [], 'presi_da' => [], 'per_modo' => [], 'fini_scritte' => [], 'cambi_scritti' => [], 'regolato_con_il_conguaglio' => []];
        }
        $dopo = new PeriodoCompetenza($dal->lt($periodo->dal()) ? $periodo->dal() : $dal, $periodo->al());
        $come = [];
        $nomi = [];
        $presi = [];
        $perModo = [];
        $fini = [];
        $cambi = [];
        $regolatiConIlConguaglio = [];
        $regolatiAltrimenti = false;
        // Coda 364: chi aveva i giorni prima del pezzo, cioè chi è uscito con il cambio che li ha dati a chi ha il pezzo.
        $prima = null;
        foreach ($this->catenaDeiConduttori($piano, $anagraficaId, $immobileId, $periodo, 0) as $pz) {
            [$daChi, $prima] = [$prima, (int) $pz['anagrafica_id']];
            $tratti = $pz['tratti'] ?? null;
            if (! $tratti instanceof InsiemePeriodi || $tratti->intersezione($dopo) === null) {
                continue;
            }
            $u = $pz['uscita'] ?? null;
            if ($u !== null && $u['strada'] === 'C') {
                $come[] = 'coinquilino';
            } elseif ($u !== null && $u['strada'] === 'P') {
                // Coda 245: la regola del piano, non i giorni dopo l'uscita; chi rimborsa chi lo dicono i passaggi sull'anello.
                $come[] = ($u['scelta'] ?? null) === Subentro::CONGUAGLIO_SCRITTO ? 'piano_scritto' : 'piano_non_scritto';
            } elseif ($u !== null && $u['strada'] === 'A' && ! empty($u['rinunciato'])) {
                $come[] = 'regolato';
            } elseif ($u !== null && $u['strada'] === 'A') {
                $come[] = 'non_scritto';
                $nomi[] = $perModo['non_scritto'][] = (string) ($u['pagato_da'] ?? '');
            } elseif ($u !== null) {
                foreach (array_merge([], ...array_map(fn (array $p) => array_map(fn (array $q) => $q + ['periodo' => $p['periodo']], $p['quote'] ?? [$p]), $u['pagatori'] ?? [])) as $p) {
                    // Per persona, una volta sola, e chi è morto con l'arretrato agli eredi con i suoi eredi, che hanno la sua riga: il pezzo con più persone è un nome solo («Ugo e Anna»),
                    // e le frasi del pannello nominano chi ha preso i giorni, non i pezzi del prospetto (che si ripetono: «Ugo e Anna» e poi «Ugo»). Coda 352: un pezzo dopo una fermata
                    // nomina anche chi è rimasto a carico della fermata, per la quota che la fermata non ha spostato.
                    $persone = isset($p['persone']) ? array_map(fn (int $id) => (string) Anagrafica::whereKey($id)->value('nome'), $p['persone']) : [(string) ($p['nome'] ?? '')];
                    array_push($presi, ...$persone);
                    if ($p['periodo']->intersezione($dopo) !== null) {
                        $come[] = 'scritto';
                        foreach ($persone as $persona) {
                            $nomi[] = $perModo['scritto'][] = $persona;
                        }
                        $fini[] = ['del' => (string) $u['del'], 'chi_esce' => $u['chi_esce'] ?? null];
                    }
                }
                // R2: i giorni che nessuno copriva sono rimasti nelle rate di chi è uscito.
                if (($u['scoperti'] ?? null) instanceof InsiemePeriodi && $u['scoperti']->intersezione($dopo) !== null) {
                    $come[] = 'non_scritto';
                    $nomi[] = $perModo['non_scritto'][] = (string) ($u['chi_esce'] ?? '');
                }
            } elseif ($pz['modo'] === 'regolato' || (isset($pz['regolato_il']) && $pz['regolato_il'] <= $dopo->al->toDateString()
                // Coda 406 (decisione 144): dopo l'ultimo giorno dell'accordo i giorni sono di chi ha il pezzo, con il suo conguaglio, e chi entra glieli rimborsa.
                && ! (isset($pz['regolato_fino']) && $pz['regolato_fino'] < $dopo->dal->toDateString()))) {
                $come[] = 'regolato';
                // Coda 413: il pezzo «regolato» di chi è entrato con il conguaglio scritto di un cambio (Sara, dopo Ines → Sara «Scrivi»): l'uscita che le parti hanno regolato è la sua, non
                // quella di chi ha le righe, e il pannello la nomina (`regolato_con_il_conguaglio`). Il pezzo di chi ha le righe, o quello regolato della catena 83.3, resta com'era.
                if ($pz['modo'] === 'conguaglio') {
                    $regolatiConIlConguaglio[] = (string) Anagrafica::whereKey((int) $pz['anagrafica_id'])->value('nome');
                } else {
                    $regolatiAltrimenti = true;
                }
                // Coda 406: chi entra nei giorni dell'accordo e resta dopo la sua fine rimborsa i giorni dopo, e il pannello dice tutti e due i modi (`misto`).
                if ($pz['modo'] === 'conguaglio' && isset($pz['regolato_fino']) && $pz['regolato_fino'] < $dopo->al->toDateString()) {
                    $come[] = 'scritto';
                    $nomi[] = $presi[] = $perModo['scritto'][] = (string) Anagrafica::whereKey((int) $pz['anagrafica_id'])->value('nome');
                    $cambi[] = ['del' => (string) $pz['conguaglio_del'], 'chi_esce' => (string) Anagrafica::whereKey((int) $daChi)->value('nome')];
                }
            } elseif ($pz['modo'] === 'conguaglio') {
                $come[] = 'scritto';
                $nomi[] = $presi[] = $perModo['scritto'][] = (string) Anagrafica::whereKey((int) $pz['anagrafica_id'])->value('nome');
                $cambi[] = ['del' => (string) $pz['conguaglio_del'], 'chi_esce' => (string) Anagrafica::whereKey((int) $daChi)->value('nome')];
            } else {
                $come[] = 'a_mano';
            }
        }
        $come = array_values(array_unique($come));

        return ['come' => match (count($come)) { 0 => 'a_mano', 1 => $come[0], default => 'misto' }, 'pagato_da' => array_values(array_unique(array_filter($nomi))),
            'presi_da' => array_values(array_unique(array_filter($presi))),
            'per_modo' => array_map(fn (array $n) => array_values(array_unique(array_filter($n))), $perModo), 'fini_scritte' => array_values(array_unique($fini, SORT_REGULAR)),
            'cambi_scritti' => array_values(array_unique($cambi, SORT_REGULAR)),
            'regolato_con_il_conguaglio' => $regolatiAltrimenti ? [] : array_values(array_unique(array_filter($regolatiConIlConguaglio)))];
    }

    /**
     * Chiusura della .50 (punti 2 e 3): il verso della parte di un conduttore che entra dal giorno dato nelle righe di una persona uscita,
     * piano per piano, come la divide il prospetto: la catena di `catenaDeiConduttori` riga per riga, con l'importo di ogni pezzo diviso per
     * giorni. `scritto` è la parte dei giorni che chi ha preso la coppia ha pagato con il conguaglio (la strada B, i giorni coperti, e il
     * cambio d'inquilino scritto); `non_scritto` quella rimasta nelle rate di chi è uscito (la strada A′, e con «Scrivi» i giorni che nessuno
     * copriva). Positiva la rimborsa chi entra; negativa (un'entrata che in quei giorni supera la spesa) è un suo credito, e chi ha pagato
     * gli restituisce la differenza. Serve il segno, non la cifra: il prospetto arrotonda voce per voce, al netto del già versato
     * «dell'unità», e divide i giorni fra i conduttori con la loro quota. I giorni di un coinquilino che resta e quelli regolati fra le parti
     * non hanno un verso, e non contano; neanche quelli della regola del piano (strada P, Coda 245), che non si divide per giorni.
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
     * Coda 252.3 (1.11.0-beta.51): lo stesso conto di `partiDopo`, per pagatore. Chi paga al posto dell'inquilino può essere più d'uno (Ugo fino
     * al 31/8, Elsa dal 1/9), e la loro parte nei giorni di chi entra può avere il segno contrario: un'entrata che supera la spesa in certi giorni
     * è un credito di chi entra, e chi ha pagato quei giorni gli restituisce la differenza, mentre l'altro pagatore ha una spesa e gli si rimborsa.
     * La fonte è la stessa del prospetto (la catena dei conduttori e i periodi dei pagatori): la frase del pannello e la sezione di chi entra
     * dicono gli stessi nomi, versi e cifre.
     *
     * @param list<array{periodo: InsiemePeriodi, importo: int}> $righe le righe del riparto di chi è uscito: il periodo e l'importo
     * @return array<string, float> nome del pagatore → la sua parte nei giorni di chi entra, con il segno (positiva la rimborsa chi entra, negativa la restituisce)
     */
    public function partiPerPagatore(PianoRate $piano, int $anagraficaId, int $immobileId, array $righe, CarbonImmutable $dal): array
    {
        $parti = [];
        $nomeDi = fn (int $id): string => (string) Anagrafica::whereKey($id)->value('nome');
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
                        $nome = $nomeDi((int) $pz['anagrafica_id']);
                        $parti[$nome] = ($parti[$nome] ?? 0.0) + $alGiorno * $giorni($tratti);
                    }
                } elseif ($u['strada'] === 'A' && empty($u['rinunciato'])) {
                    $nome = (string) ($u['pagato_da'] ?? $u['chi_esce'] ?? '');
                    $parti[$nome] = ($parti[$nome] ?? 0.0) + $alGiorno * $giorni($tratti);
                } elseif ($u['strada'] === 'B') {
                    // R2: i giorni che nessuno copriva sono rimasti nelle rate di chi è uscito; gli altri li hanno pagati i pagatori, ognuno i suoi.
                    $scoperti = ($u['scoperti'] ?? null) instanceof InsiemePeriodi ? self::comune($tratti, $u['scoperti']) : null;
                    if ($scoperti !== null) {
                        $nome = (string) ($u['chi_esce'] ?? '');
                        $parti[$nome] = ($parti[$nome] ?? 0.0) + $alGiorno * $giorni($scoperti);
                    }
                    foreach ($u['pagatori'] ?? [] as $p) {
                        $suoi = self::comune($tratti, $p['periodo']);
                        // Coda 352: dopo una fermata, ognuno per la parte del pezzo che ha pagato.
                        foreach ($p['quote'] ?? [['nome' => $p['nome'] ?? '', 'quota' => 1.0]] as $q) {
                            $nome = (string) ($q['nome'] ?? '');
                            if ($nome !== '' && $giorni($suoi) > 0) {
                                $parti[$nome] = ($parti[$nome] ?? 0.0) + $alGiorno * $giorni($suoi) * (float) $q['quota'];
                            }
                        }
                    }
                }
            }
        }

        return array_filter($parti, fn (float $c, string $nome) => $nome !== '' && (int) round($c) !== 0, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Coda 370: la prima uscita di chi è uscito con la strada A′, dentro i giorni dopo quell'uscita (quindi dopo il suo rientro), che chiude il pezzo A′. Lo chiude
     * l'uscita che ha scritto la coppia su questa unità e gestione: le righe in `saldi` con l'id del capo, come per la strada B (`uscitaDellaLocazione`) e per la
     * decisione 83.3 (`regolatoConUnaFineDopo`).
     *
     * Coda 375: lo chiude anche l'uscita che le parti hanno regolato fra loro: il conguaglio annullato, o la casella di prima della .50 (la rinuncia senza la scelta),
     * lette come nella catena e in `finePrimaDellaVoce`. I giorni dopo sono dell'accordo, e la catena riparte da quell'uscita e la legge come ogni passaggio regolato
     * fra le parti: un cambio seguito dalla fine «Scrivi» di chi era entrato (o da un altro cambio regolato, Coda 372) lo segue, decisione 83.3; altrimenti il pezzo
     * porta `regolato_il`, e i giorni restano dov'erano, senza restituzione, e chi entra dopo non li divide (decisioni 43 e 46), fino alla prossima uscita di chi è uscito
     * con un conguaglio suo, che la catena legge come ogni uscita (Coda 379, `uscitaDopoLAccordo`), o fino alla fine della voce. Come nella catena, l'accordo vale per il
     * passaggio, non per l'unità: anche sul box dove la fine non aveva scritto righe (nessuno stava al posto dell'inquilino), come la fine annullata senza il rientro
     * (decisione 46, la nota del 12/10, punto 4). Prima la seconda uscita regolata fra le parti non chiudeva il pezzo, e il prospetto restituiva a chi era uscito, o
     * faceva rimborsare da chi entrava dopo, i giorni che le parti avevano regolato fra loro.
     *
     * Null se non c'è: la seconda uscita con «Non scriverlo», o con «Scrivi» e senza righe su questa unità e gestione (dopo il rientro con un inizio la fine non scrive
     * la coppia della parte che chi esce ha già ceduto; sul box dove nessuno sta al posto dell'inquilino non la scrive): i giorni dopo restano nella strada A′ della
     * prima uscita. Si cerca dal giorno della prima uscita (`$dal`), anche prima della voce.
     *
     * @param list<int> $visti
     */
    private function riuscitaCheChiudeIlPezzo(PianoRate $piano, int $chi, int $immobileId, InsiemePeriodi $dopo, array $visti, string $dal): ?Subentro
    {
        return Subentro::where('immobile_id', $immobileId)->where('tipo_passaggio', 'fine_locazione')->where('anagrafica_uscente_id', $chi)->whereNotIn('id', $visti)
            ->whereDate('decorrenza', '>', $dal)->whereDate('decorrenza', '<=', $dopo->al()->toDateString())
            ->orderBy('decorrenza')->orderBy('id')->get()
            ->first(function (Subentro $s) use ($immobileId, $piano) {
                $capo = $s->capo();
                // Coda 375: regolata fra le parti.
                if ($capo->conguaglioAnnullato() || ($capo->conguaglioRinunciato() && ! $capo->conguaglioNonScritto())) {
                    return true;
                }

                return DB::table('saldi')->where('subentro_id', $capo->id)->where('immobile_id', $immobileId)->where('gestione_id', $piano->gestione_id)->exists();
            });
    }

    /**
     * Coda 379: la prossima uscita di chi ha il pezzo dopo un passaggio che le parti hanno regolato fra loro (quindi dopo un suo rientro), dentro i giorni che restano, con un conguaglio
     * suo: la scelta nel registro («Scrivi», anche se poi annullato, o «Non scriverlo»), o la casella di prima della .50 nel cambio. La catena riparte da lì e la legge come ogni uscita: la
     * strada B dove la coppia ha le righe su questa unità e gestione, la strada A′ dove non le ha (il box dove nessuno sta al posto dell'inquilino) o con «Non scriverlo», il cambio con la
     * coppia scritta che segue, e l'uscita regolata fra le parti anche lei come un altro accordo (con la decisione 83.3 se chi è entrato esce poi con la coppia scritta). La scelta vale per
     * il passaggio, non per l'unità, come l'accordo (Coda 375): sul box dove la fine «Scrivi» non scrive la coppia i giorni dopo restano nelle rate di chi esce, e chi entra dopo glieli
     * rimborsa, come senza l'accordo prima. L'accordo vale per le parti di quel passaggio: non per chi paga al posto dell'inquilino e ha preso i giorni dopo con la coppia di un'uscita
     * successiva, né per chi entra dopo quell'uscita.
     *
     * Null se non c'è, o se c'è solo la fine senza la scelta: dopo il rientro con un inizio, o dopo la fine annullata, la parte dell'unità chi esce l'aveva già ceduta, la fine non ha un
     * conguaglio suo, e i giorni dopo restano nell'accordo (decisioni 43 e 46).
     *
     * @param list<int> $visti
     */
    private function uscitaDopoLAccordo(int $chi, int $immobileId, InsiemePeriodi $restante, Subentro $accordo, array $visti): ?Subentro
    {
        // Dopo l'accordo, anche prima della voce: con l'accordo prima della voce (`finePrimaDellaVoce`, che legge le uscite nell'ordine delle date) l'uscita dopo può cadere prima della voce, o
        // nel suo primo giorno, e il pezzo dell'accordo non ha giorni.
        $dopo = $accordo->decorrenza->toDateString();

        return Subentro::where('immobile_id', $immobileId)->where('tipo_passaggio', 'fine_locazione')->where('anagrafica_uscente_id', $chi)->whereNotIn('id', [...$visti, (int) $accordo->id])
            ->whereDate('decorrenza', '>', $dopo)->whereDate('decorrenza', '<=', $restante->al()->toDateString())
            ->orderBy('decorrenza')->orderBy('id')->get()
            ->first(fn (Subentro $s) => isset($s->capo()->registro['conguaglio']['scelta']) || self::regolatoFraLeParti($s, $s->capo()));
    }

    /** Coda 379: il passaggio che le parti hanno regolato fra loro, come lo legge la catena: il conguaglio annullato, o nel cambio d'inquilino la casella di prima della .50 (la rinuncia senza la scelta). */
    private static function regolatoFraLeParti(Subentro $passaggio, Subentro $capo): bool
    {
        return $capo->conguaglioAnnullato() || ($passaggio->anagrafica_entrante_id !== null && $capo->conguaglioRinunciato() && ! $capo->conguaglioNonScritto());
    }

    /**
     * Decisione 83.3: un cambio d'inquilino «regolato fra le parti» — la casella di prima della .50 (la rinuncia senza la scelta) o il
     * conguaglio annullato — dopo il quale chi era entrato è uscito a sua volta, nei giorni del pezzo, con una fine che ha scritto la coppia
     * su questa unità e gestione (righe in saldi, conguaglio non annullato). Allora i giorni dopo la sua uscita li ha pagati chi ha la
     * coppia, e la catena li segue.
     *
     * Le righe della coppia si scrivono con l'id del padre (`RegistraSubentroAction::scriviCoppie`): sulla pertinenza la fine trovata è il
     * figlio, e le righe si cercano con il suo capo, come in `uscitaDellaLocazione` (giro stretto della .50, G3).
     *
     * Coda 372: di cambio in cambio. Se chi era entrato è uscito con un altro cambio d'inquilino regolato fra le parti, la 83.3 vale per quel cambio: la catena
     * li segue tutti e due se chi è entrato con il secondo è uscito con la coppia scritta (o con un altro cambio regolato, e così via). Prima la ricerca guardava
     * un passo solo: con i due cambi annullati e la fine «Scrivi» dopo, la catena si fermava su chi aveva la riga, mentre il Wallet aveva già dato i giorni dopo
     * la fine a chi paga al posto dell'inquilino, e chi entrava dopo non glieli rimborsava. Le date crescono a ogni passo: la ricerca finisce.
     *
     * Coda 413: solo sul piano che il cambio ha preso (`PianoRate::presoNelConguaglioDa`). Un piano che il cambio non ha preso (le rate non ancora emesse quel giorno) non ha
     * dato a chi è entrato nessuna quota, e la coppia della sua fine non ne ha (decisione 40: il fermo del conguaglio del passaggio dopo conserva la scelta di chi ha emesso senza
     * ricalcolare): la voce resta nelle rate di chi aveva la riga, e il cambio regolato fra le parti si legge come senza la fine dopo (decisioni 43 e 46). Prima la catena seguiva
     * la fine anche lì, e chi entrava dopo risultava rimborsare a chi ha la coppia giorni che la coppia non aveva.
     */
    private function regolatoConUnaFineDopo(Subentro $passaggio, Subentro $capo, PianoRate $piano, int $immobileId, InsiemePeriodi $restante): bool
    {
        if ($passaggio->anagrafica_entrante_id === null || $capo->conguaglioNonScritto() || ! ($capo->conguaglioAnnullato() || $capo->conguaglioRinunciato())
            || ! $piano->presoNelConguaglioDa($passaggio)) {
            return false;
        }

        return Subentro::where('immobile_id', $immobileId)->where('tipo_passaggio', 'fine_locazione')->where('anagrafica_uscente_id', (int) $passaggio->anagrafica_entrante_id)
            ->whereDate('decorrenza', '>', $passaggio->decorrenza->toDateString())->whereDate('decorrenza', '<=', $restante->al()->toDateString())
            ->get()->contains(function (Subentro $fine) use ($immobileId, $piano, $restante) {
                $capoDellaFine = $fine->capo();

                return (! $capoDellaFine->conguaglioAnnullato()
                    && DB::table('saldi')->where('subentro_id', $capoDellaFine->id)->where('immobile_id', $immobileId)->where('gestione_id', $piano->gestione_id)->exists())
                    || $this->regolatoConUnaFineDopo($fine, $capoDellaFine, $piano, $immobileId, $restante);
            });
    }

    /**
     * Decisioni 32, 77 e 79 (1.11.0-beta.50): che cosa è successo ai giorni dopo l'uscita di chi lascia l'unità, piano per piano. Null
     * quando vale la catena del cambio d'inquilino di prima (la coppia scritta con chi entra, o la casella «le parti hanno regolato» di un
     * passaggio registrato prima della .50, decisioni 43 e 46).
     * - `P` (Coda 245, 1.11.0-beta.55): la fine con la regola del piano nel registro (`conguaglio.piano`) per l'unità e il piano, con qualunque scelta:
     *   la parte dell'inquilino si legge con la regola del piano (`ProspettoRegolaDelPiano`), non per giorni dopo l'uscita.
     * - `C`: resta un coinquilino (decisione 79), nelle fini registrate dalle beta .50–.54 con la fermata del coinquilino: nessuna coppia e
     *   nessuna scelta, i giorni restano nelle rate di chi esce e chi li paga lo decidono le parti. Vale per l'unità, anche quando la scelta c'è
     *   per le altre del passaggio.
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
        // Coda 245 (1.11.0-beta.55): la fine (o il cambio) con la regola del piano nel registro per questa unità e questo piano. Prima del coinquilino che
        // resta: il registro ha anche i coinquilini letti, e la strada C è solo per le fini delle beta con la fermata del coinquilino.
        $delPiano = ChiPagaAlPostoDellInquilino::fineDelPiano($capo, $immobileId);
        if ($delPiano !== null && (int) ($delPiano['gestione_id'] ?? 0) === (int) $piano->gestione_id
            && in_array((int) $piano->id, array_map(fn ($v) => (int) ($v['piano_rate_id'] ?? 0), (array) ($delPiano['voci'] ?? [])), true)) {
            return ['strada' => 'P', 'scelta' => $scelta] + $uscita;
        }
        if ($passaggio->anagrafica_entrante_id !== null) {
            if ($scelta !== Subentro::CONGUAGLIO_NON_SCRITTO) {
                return null;
            }
            // Coda 358 (decisione 115.3): i giorni di chi esce li ha presi chi entra (decisione 37), e li rimborsa lui (il registro: `regolato_fuori`); un
            // coinquilino che resta ha i suoi giorni nelle sue rate, e non entra fra chi li divide finché qualcuno sta al posto di chi esce: dai giorni in cui
            // resta solo li divide (`dividiDopoLUscita`). Con le righe di oggi: anche chi è stato associato dopo con
            // una data di prima era coinquilino quel giorno, e il registro del cambio non gli dà quei giorni. Coda 360: neanche chi ha preso dopo il posto di un
            // coinquilino con un altro cambio d'inquilino (i suoi giorni vengono dalle rate del coinquilino), né il coinquilino che cambia lo stesso giorno.
            $restano = $this->coinquiliniEChiNeHaPresoIlPosto($immobileId, $chi, $passaggio);

            return ['strada' => 'A', 'scelta' => $scelta, 'pagato_da' => $nome] + ($restano !== [] ? ['restano' => $restano] : []) + $uscita;
        }
        if ($this->coinquilinoCheResta($capo, $immobileId, $chi, CarbonImmutable::parse($passaggio->decorrenza->toDateString()))) {
            // Decisione 98.2 (Coda 245, 1.11.0-beta.55): i saldi del Wallet legati a quella fine si dicono, con la persona e la cifra.
            $legate = Saldo::where('passaggio_id', (int) $capo->id)->where('immobile_id', $immobileId)->where('gestione_id', (int) $piano->gestione_id)->get()
                ->groupBy('anagrafica_id')->map(fn ($g) => (int) $g->sum('saldo_iniziale'))->filter(fn (int $c) => $c !== 0)->all();

            return ['strada' => 'C', 'legate' => $legate === [] ? null
                : RegolaDelPiano::partiInParole($legate, Anagrafica::whereIn('id', array_keys($legate))->pluck('nome', 'id')->map(fn ($n) => (string) $n)->all())] + $uscita;
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
     * Coda 360 (il cambio d'inquilino con «Non scriverlo», la strada A′): chi non divide i giorni di chi esce, nei giorni in cui un altro conduttore sta al suo posto
     * (`dividiDopoLUscita`; in quelli in cui restano soli li dividono). I coinquilini di chi esce, le persone con una riga
     * d'inquilino il giorno prima del cambio (con le righe di oggi, come la Coda 358), e chi ha preso il posto di uno di loro con un cambio d'inquilino dal giorno del
     * cambio in poi, di cambio in cambio: i suoi giorni sono nelle rate di quel coinquilino, e il registro del suo cambio li dice. Il giorno prima, e non anche quel
     * giorno: un coinquilino che cambia lo stesso giorno (Ines → Sara e Luca → Irma il 1/7) era coinquilino, e chi entra al suo posto non prende i giorni di Ines.
     * Chi entra con un inizio di locazione non ha preso il posto di nessuno, e divide.
     *
     * @return list<int>
     */
    private function coinquiliniEChiNeHaPresoIlPosto(int $immobileId, int $chi, Subentro $passaggio): array
    {
        $ilGiornoPrima = CarbonImmutable::parse($passaggio->decorrenza->toDateString())->subDay();
        $fuori = TitolaritaImmobile::where('immobile_id', $immobileId)->where('tipologia', 'inquilino')->get()
            ->filter(fn (TitolaritaImmobile $t) => (int) $t->anagrafica_id !== $chi && (float) $t->quota > 0 && $t->inCorsoIl($ilGiornoPrima))
            ->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($fuori === []) {
            return [];
        }
        $cambi = Subentro::where('immobile_id', $immobileId)->where('tipo_passaggio', 'fine_locazione')->whereNotNull('anagrafica_entrante_id')->whereKeyNot($passaggio->id)
            ->whereDate('decorrenza', '>=', $passaggio->decorrenza->toDateString())->orderBy('decorrenza')->orderBy('id')->get();
        foreach ($cambi as $c) {
            if (in_array((int) $c->anagrafica_uscente_id, $fuori, true) && ! in_array((int) $c->anagrafica_entrante_id, $fuori, true)) {
                $fuori[] = (int) $c->anagrafica_entrante_id;
            }
        }

        return $fuori;
    }

    /**
     * Coda 245 (1.11.0-beta.55): il piano sull'unità è sull'anello di una fine con la regola del piano (`RegolaDelPiano::sullAnello`)? Allora la sua uscita
     * prende la strada P: la fine e i passaggi sull'anello; altrimenti null, e il piano si legge come prima. L'anello è dell'unità e della gestione, non del
     * piano: anche un piano della stessa gestione generato dopo la fine, e preso da un passaggio dopo, ci sta; e i passaggi si leggono su tutti i piani
     * dell'anello, una volta (`ProspettoRegolaDelPiano::composizione`), con un gruppo solo per la fine e la gestione. Una fine con la regola del piano su
     * più gestioni dell'unità ha un anello per gestione.
     *
     * I cambi d'inquilino della .50 che prima della fine hanno già spostato l'anello con la loro coppia, scritta o lasciata fra le parti
     * (`RegolaDelPiano::cambiCheHannoSpostatoLAnello`): la fine parte da lì, e il loro conguaglio è fra quelli del prospetto.
     *
     * @return ?array{fine: Subentro, passaggi: list<array{s: Subentro, tipo: string, voce: array<string, mixed>}>, cambi: list<Subentro>}
     */
    private function anelloDelPiano(PianoRate $piano, int $immobileId): ?array
    {
        $anello = RegolaDelPiano::sullAnello($immobileId, (int) $piano->gestione_id, [(int) $piano->id]);
        if ($anello === null) {
            return null;
        }
        $fine = $anello['fine'];

        return ['fine' => $fine, 'passaggi' => $this->delPiano->passaggi($fine, $immobileId, (int) $piano->gestione_id, $anello['piani']),
            'cambi' => RegolaDelPiano::cambiCheHannoSpostatoLAnello($immobileId, (int) $piano->gestione_id, (int) $fine->id)];
    }

    /**
     * Tutte le voci del prospetto sono della regola del piano (le voci di un piano letto con la strada P, e il già versato accanto)? Allora la frase di testa
     * dice solo quella regola.
     *
     * @param array<int, list<array<string, mixed>>> $voci
     * @param list<array<string, mixed>> $senza
     */
    private function soloConLaRegolaDelPiano(array $voci, array $senza): bool
    {
        $tutte = [...array_merge([], ...array_values($voci)), ...$senza];

        return $tutte !== [] && collect($tutte)->every(fn ($v) => ($v['strada'] ?? null) === 'P' || str_ends_with((string) ($v['conto'] ?? ''), ' — già versato'));
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
                    // Chi è entrato con la fermata, come lo dice la riga: se è già il nome del pezzo, l'alternativa gira in tondo («pagata da Ugo …; Ugo, se …») e non si scrive.
                    'verso' => collect($f['verso'] ?? [])->filter()->join(', ', ' e '),
                    'testo' => sprintf('%s, se le parti hanno regolato fra loro la parte del conguaglio che %s del %s non ha spostato',
                        collect($f['verso'] ?? [])->filter()->join(', ', ' e ') ?: ($s->entrante?->nome ?? 'chi è entrato'), self::passaggioInParole($s), $data($s->decorrenza)),
                ]))
            ->values();

        // Coda 352: dopo chi ricompra la sua stessa parte (Coda 351) i giorni sono di nuovo suoi, anche se la fermata di prima è stata regolata: dal giorno della
        // ricompera la fermata di prima non è più un'alternativa.
        $ricomprate = [];
        foreach (Subentro::where('condominio_id', $capo->condominio_id)->whereNull('subentro_padre_id')->whereKeyNot($capo->id)->get() as $s) {
            foreach ($s->registro['fermata_ridotta'] ?? [] as $r) {
                if (! empty($r['ricompra']) && (int) ($r['fine_id'] ?? 0) === (int) $capo->id && (int) ($r['immobile_id'] ?? 0) === $immobileId && ! empty($r['dal'])) {
                    foreach ([(int) ($r['passaggio_id'] ?? 0), ...array_map('intval', (array) ($r['passaggi_ids'] ?? []))] as $id) {
                        $ricomprate[$id] = min($ricomprate[$id] ?? '9999-12-31', substr((string) $r['dal'], 0, 10));
                    }
                }
            }
        }
        $fermate = $fermate->map(fn (array $f) => $f + ['fino' => isset($ricomprate[$f['passaggio_id']]) ? CarbonImmutable::parse($ricomprate[$f['passaggio_id']]) : null]);
        // Chi è morto con l'arretrato agli eredi ha lasciato loro la sua riga della coppia: le persone di un pezzo, per i testi del pannello, sono quelle che hanno la riga oggi.
        $eredi = FermataDopoScrivi::eredi($capo, $immobileId, (int) $piano->gestione_id);
        $oggi = fn (array $ids): array => array_values(array_unique(array_merge(...array_map(fn (int $id) => $eredi[$id] ?? [$id], $ids))));
        $pezzi = [];
        $da = $dopo->dal();
        $tagli = $fermate->pluck('dal')->merge($fermate->pluck('fino')->filter());
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
                $persone = $conRighe;
                $inVigore = $fermate->filter(fn (array $f) => $f['dal']->lte($da) && ($f['fino'] === null || $da->lt($f['fino'])));
                if ($chiPaga !== null) {
                    $chi = array_keys($chiPaga->pesi($righeUnita, $pezzo));
                    $persone = $chi !== [] && array_diff($chi, $conRighe) === [] ? array_values(array_intersect($conRighe, $chi)) : ($diPrima ?? $conRighe);
                    $diPrima = $persone;
                    $nome = $nomiDi($persone);
                }
                $suoi = ['nome' => $nome, 'persone' => $oggi($persone), 'fermate' => $inVigore->filter(fn (array $f) => $f['verso'] === '' || $f['verso'] !== $nome)
                    ->map(fn (array $f) => ['passaggio_id' => $f['passaggio_id'], 'testo' => $f['testo']])->values()->all(), 'periodo' => $pezzo, 'in_vigore' => $inVigore, 'chi' => $persone];
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

        // Coda 352 (decisione 112.1): in un pezzo dopo una fermata la quota che la fermata ha passato a chi riceve l'ha pagata chi è rimasto a carico della fermata
        // (`FermataDopoScrivi::chiHaPagatoIlTratto`): il pezzo si divide per chi l'ha pagato (`quote`), la parte di chi riceve per prima, con le alternative delle
        // fermate che non riguardano la quota; per ogni fermata chi ne risponde, con le alternative della sua catena. I pezzi e le loro cifre restano quelli di prima.
        foreach ($pezzi as &$p) {
            $inVigore = $p['in_vigore'];
            $chi = $p['chi'];
            unset($p['in_vigore'], $p['chi']);
            if ($chiPaga === null) {
                continue;
            }
            $diChi = app(FermataDopoScrivi::class)->chiHaPagatoIlTratto($capo, $immobileId, (int) $piano->gestione_id, $righeUnita, $p['periodo']);
            if ($diChi['fermate'] === []) {
                continue;
            }
            $tutti = (float) array_sum($diChi['pesi']);
            $nelleCatene = array_merge(...array_column($diChi['fermate'], 'passaggi'));
            $testi = fn (array $ids) => $inVigore->filter(fn (array $f) => in_array($f['passaggio_id'], $ids, true))
                ->sortBy(fn (array $f) => array_search($f['passaggio_id'], $ids, true))->map(fn (array $f) => ['passaggio_id' => $f['passaggio_id'], 'testo' => $f['testo']])->values()->all();
            $suoiPesi = array_filter($diChi['pesi'], fn (float $peso, int $k) => $k >= 0, ARRAY_FILTER_USE_BOTH);
            // La parte di chi riceve è sua: le persone sono quelle che hanno i giorni senza la quota delle fermate (chi ha la coppia), o quelle del pezzo. Chi ha oggi la riga
            // di un defunto della coppia (l'arretrato agli eredi, anche in catena) sta al suo posto, e il pezzo nomina chi aveva la riga nella coppia, come i pezzi senza fermate.
            $propri = array_values(array_unique(array_map(fn (int $id) => in_array($id, $conRighe, true) ? $id
                : (int) (collect($eredi)->filter(fn (array $ids, int $defunto) => in_array($defunto, $conRighe, true) && in_array($id, $ids, true))->keys()->first() ?? $id), array_keys($suoiPesi))));
            $propri = $propri !== [] && array_diff($propri, $conRighe) === [] ? array_values(array_intersect($conRighe, $propri)) : $chi;
            $p['quote'] = array_values(array_filter([
                ['nome' => $nomiDi($propri), 'persone' => $oggi($propri), 'fermate' => array_values(array_filter($p['fermate'], fn (array $f) => ! in_array($f['passaggio_id'], $nelleCatene, true))),
                    'quota' => (float) array_sum($suoiPesi) / $tutti],
                ...array_map(fn (int $k) => ['nome' => $nomiDi($diChi['fermate'][$k]['persone']), 'persone' => $oggi($diChi['fermate'][$k]['persone']),
                    'fermate' => $testi($diChi['fermate'][$k]['passaggi']), 'quota' => (float) $diChi['pesi'][$k] / $tutti], array_keys($diChi['fermate'])),
            ], fn (array $q) => $q['quota'] > 1e-9));
        }
        unset($p);

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

    /**
     * «la vendita», «la costituzione d'usufrutto», «la fine della locazione di Luca», …: il passaggio che ha dichiarato una fermata, nelle frasi
     * del prospetto. Il sostantivo è quello del pannello e dello storico (una funzione sola, `FermataDopoScrivi::passaggioInParole`); il
     * prospetto vi aggiunge la sua data.
     */
    private static function passaggioInParole(Subentro $s): string
    {
        return FermataDopoScrivi::passaggioInParole($s, false);
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
                . '|' . ($v['strada'] ?? '') . '|' . ($v['scelta'] ?? '') . '|' . ($v['uscita_del'] ?? '') . '|' . ($v['oppure'] ?? '') . '|' . ($v['da_chi'] ?? ''))
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
                ], array_key_exists('nelle_rate', $prima) ? ['nelle_rate' => (int) $g->sum('nelle_rate')] : []), ['tratti' => true]);
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
    private function sezione(int $anagraficaId, array $voci, Collection $righeInquilino, ?PeriodoCompetenza $esercizio, ?array $delPiano = null): array
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
        // Coda 245: delle voci della regola del piano l'importo è la parte del conduttore, e accanto ciò che ne ha nelle sue rate.
        $rate = (int) array_sum(array_map(fn ($v) => $v['modo'] === 'rate' ? $v['importo'] : ($v['modo'] === 'piano' ? (int) ($v['nelle_rate'] ?? 0) : 0), $voci));
        $conguaglio = (int) array_sum(array_map(fn ($v) => $v['modo'] === 'conguaglio' ? $v['importo'] : 0, $voci)) + (int) array_sum(array_column($delPiano['conguagli'] ?? [], 'importo'));
        // Decisione 83.3: la parte regolata fra le parti al cambio d'inquilino non è da rimborsare a nessuno.
        $regolato = (int) array_sum(array_map(fn ($v) => $v['modo'] === 'regolato' ? $v['importo'] : 0, $voci)) + (int) array_sum(array_column($delPiano['regolati'] ?? [], 'importo'));
        // Coda 413 (P-2): il credito nel Wallet della coppia dell'uscita di chi aveva preso il posto di chi è uscito con «Non scriverlo», quando il suo pezzo era regolato fra le parti
        // (`vociDelRipreso`): non è da rimborsare a nessuno, come le altre parti del piede, e ha la sua riga.
        $credito = (int) array_sum(array_map(fn ($v) => $v['modo'] === 'credito' ? $v['importo'] : 0, $voci));

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
            'a_suo_credito_nel_wallet' => $credito,
            'a_suo_credito_nel_wallet_formattato' => MoneyHelper::format($credito),
            'da_rimborsare' => $totale - $rate - $conguaglio - $regolato - $credito,
            'da_rimborsare_formattato' => MoneyHelper::format($totale - $rate - $conguaglio - $regolato - $credito),
            // Coda 252.2 (1.11.0-beta.51): chi è uscito ha nelle sue rate i giorni dopo l'uscita, e la sua sezione li restituisce. Un conduttore
            // che riceve (un'entrata che nei suoi giorni supera la spesa) ha il totale e la parte da rimborsare negativi come lui, ma non è
            // uscito: la stampa non deve dire «da restituire a …: i giorni dopo la sua uscita» di chi è ancora lì.
            'e_uscito' => collect($voci)->contains(fn (array $v) => in_array($v['modo'] ?? null, ['restituzione', 'da_restituire'], true)),
            // Coda 364 (1.11.0-beta.55): di chi è entrato con il cambio d'inquilino, la parte dei conduttori entrati dopo nei giorni che ha pagato con il conguaglio,
            // per persona: negativa la riceve da loro, positiva (un'entrata che in quei giorni supera la spesa) la restituisce. È dentro «da rimborsare».
            'entrati_dopo' => collect($voci)->where('modo', 'entrato_dopo')->groupBy('da_chi')->map(fn (Collection $g) => (int) $g->sum('importo'))->all(),
            // Coda 366: per persona, come chi è entrato con il cambio ha pagato quei giorni (`conguaglio`, `regolato`, o tutti e due), perché il piede lo dica.
            'entrati_dopo_modi' => collect($voci)->where('modo', 'entrato_dopo')->groupBy('da_chi')->map(fn (Collection $g) => self::modiDegliEntratiDopo($g->all()))->all(),
        ] + ($delPiano !== null ? ['regola_del_piano' => $delPiano] : []);
    }

    /**
     * Coda 366: i modi delle voci `entrato_dopo` date — `conguaglio` (il conguaglio scritto del cambio), `regolato` (la parte regolata fra le parti, decisione 83.3) —,
     * una volta ciascuno e in quest'ordine; vuoto se non ce ne sono.
     *
     * @param list<array<string, mixed>> $voci
     * @return list<string>
     */
    private static function modiDegliEntratiDopo(array $voci): array
    {
        $modi = collect($voci)->where('modo', 'entrato_dopo')->map(fn (array $v) => empty($v['regolato']) ? 'conguaglio' : 'regolato')->unique()->all();

        return array_values(array_intersect(['conguaglio', 'regolato'], $modi));
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
        // La strada P (Coda 245) ha le sue frasi (`ProspettoRegolaDelPiano::frasi`).
        $dellUscita = collect([...array_merge([], ...array_values($voci)), ...$senza])->filter(fn ($v) => ! empty($v['strada']) && $v['strada'] !== 'P' && ! empty($v['uscita_del']));
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
                    $v['strada'] === 'C' => 'Alla fine della locazione del %s%s resta un coinquilino: il conguaglio dei giorni dopo l\'uscita non c\'è, quei giorni restano nelle rate di chi è uscito, e chi li paga lo decidono le parti.'
                        . (($v['legate'] ?? null) !== null ? ' Ci sono saldi del Wallet legati a quella fine: ' . str_replace('%', '%%', (string) $v['legate']) . '.' : ''),
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
        // (`manuale`), non quello dell'importatore. Dalla 1.11.0-beta.53 (Coda 244) una riga legata a un altro passaggio non è un saldo per quei
        // giorni: il legame dice di chi è. Una riga legata a quella fine sì.
        $senzaScelta = $dellUscita->filter(fn ($v) => $v['strada'] === 'A' && ($v['scelta'] ?? null) === null
            && $immobileId !== null && $this->finePrimaDellaCinquanta($immobileId, (string) $v['uscita_del']) !== null);
        if ($immobileId !== null && $senzaScelta->isNotEmpty()) {
            $gestioni = PianoRate::whereIn('id', $senzaScelta->pluck('piano_rate_id')->filter()->unique()->all())->pluck('gestione_id')->all();
            $fini = $senzaScelta->map(fn ($v) => (int) $this->finePrimaDellaCinquanta($immobileId, (string) $v['uscita_del'])?->capo()->id)->filter()->unique()->values()->all();
            if ($gestioni !== [] && DB::table('saldi')->whereNull('subentro_id')->where('origine', 'manuale')->where('immobile_id', $immobileId)->whereIn('gestione_id', $gestioni)
                ->where(fn ($q) => $q->whereNull('passaggio_id')->orWhereIn('passaggio_id', $fini ?: [0]))->exists()) {
                $frasi[] = 'Sull\'unità c\'è un saldo manuale scritto dal Wallet sulla stessa gestione: se è stato scritto per i giorni dopo l\'uscita, come il pannello indicava nelle versioni precedenti alla fine di una locazione, quei giorni sono già regolati, e la parte da restituire non si restituisce una seconda volta.';
            }
        }
        if ($dellUscita->contains(fn ($v) => ! empty($v['oppure']))) {
            $frasi[] = 'Dopo la fine con il conguaglio scritto un passaggio ha cambiato chi paga al posto dell\'inquilino senza spostare il conguaglio, e lo ha detto: dal giorno di quel passaggio la voce nomina chi ha il conguaglio, che ha pagato quei giorni, e chi è entrato, se le parti hanno regolato fra loro quella parte.';
        }

        return $frasi;
    }

    /**
     * G19: la fine della locazione dell'unità in quel giorno, se è di prima della .50 (il suo registro non ha la scelta sul conguaglio); null
     * altrimenti. Sulla pertinenza la fine trovata è il figlio, e la scelta sta nel registro del padre.
     */
    private function finePrimaDellaCinquanta(int $immobileId, string $giorno): ?Subentro
    {
        $fine = Subentro::where('immobile_id', $immobileId)->where('tipo_passaggio', 'fine_locazione')->whereDate('decorrenza', substr($giorno, 0, 10))->orderByDesc('id')->first();

        return $fine !== null && ! isset($fine->capo()->registro['conguaglio']) ? $fine : null;
    }

    /**
     * Le frasi in testa: cosa il documento non è, su quali numeri si regge, come divide, come si legge. Con `$conLaFine` (1.11.0-beta.50)
     * il «pagata da …» non è solo il ripiego: è anche chi è uscito o chi paga al posto dell'inquilino alla fine di una locazione; con `$dopoIlCambio`
     * (Coda 364) chi è entrato con il conguaglio di un cambio d'inquilino, per i giorni di un conduttore entrato dopo, o (Coda 366) con un cambio regolato fra le parti:
     * i modi delle voci `entrato_dopo` (`modiDegliEntratiDopo`).
     *
     * @param list<array{nome: string, fonte: string}> $piani
     * @param list<array{nome: string, motivo: string}> $esclusi
     * @param list<string> $dopoIlCambio
     * @return list<string>
     */
    private function intestazione(array $piani, array $esclusi, bool $conLaFine = false, bool $conIlPiano = false, bool $soloIlPiano = false, array $approvatiFuori = [], array $dopoIlCambio = []): array
    {
        // Coda 364 (1.11.0-beta.55): chi ha pagato i giorni di un conduttore entrato dopo un cambio d'inquilino con il conguaglio scritto. Coda 366: o con il cambio
        // d'inquilino regolato fra le parti (decisione 83.3), detto solo se c'è; con tutti e due i modi la frase li dice tutti e due.
        $comeSonoEntrati = array_map(fn (string $m) => $m === 'regolato' ? 'con un cambio d\'inquilino regolato fra le parti' : 'con il conguaglio di un cambio d\'inquilino', $dopoIlCambio);
        $altri = ($dopoIlCambio !== [] ? ', oppure chi è entrato ' . implode(' o ', $comeSonoEntrati) . ', per i giorni di un conduttore entrato dopo' : '')
            . ($conLaFine ? ', oppure, alla fine di una locazione, chi è uscito o chi paga al posto dell\'inquilino, come dicono le frasi qui sotto' : '');
        $fuori = implode('», «', $approvatiFuori);
        $unoFuori = count($approvatiFuori) === 1;
        $nomi = implode('», «', array_column($piani, 'nome'));
        // Coda 245 (1.11.0-beta.55): sull'anello di una fine con la regola del piano le voci si dividono per quota e per giorni sull'intera voce.
        $legendaDelPiano = '«con la regola del piano» è la parte del conduttore, per quota e per giorni sull\'intera voce, con ciò che ne ha già nelle sue rate; sotto il totale, «con il conguaglio della fine locazione del …» ciò che il conguaglio di un passaggio ha scritto in saldi, e «da rimborsare» o «da restituire» ciò che le parti regolano fra loro, con il nome e il passaggio che l\'ha lasciato.';
        $frasi = [
            // R21 (Fase 1-bis): la regola come norma, con le fonti di §3.1 delle pertinenze.
            'Non è un riparto e non è una richiesta di pagamento del condominio. Verso il condominio risponde il proprietario: l\'amministratore può esigere i contributi solo dai condòmini, non dal conduttore, anche quando le rate sono intestate a lui (artt. 1123 c.c. e 63 disp. att. c.c.; Cass. 17039/2007). È il prospetto con cui il proprietario regola con il conduttore le spese che il riparto pone a carico dell\'inquilino.',
            match (true) {
                // Coda 359 (decisione 115.3): un piano approvato dell'esercizio che il prospetto non sa leggere si nomina; «Nessun piano rate approvato» solo quando è vero.
                $piani === [] && $approvatiFuori !== [] => sprintf('Il prospetto è vuoto: %s «%s» di questo esercizio non si %s leggere qui, e il perché è qui sotto.',
                    $unoFuori ? 'il piano rate approvato' : 'i piani rate approvati', $fuori, $unoFuori ? 'può' : 'possono'),
                $piani === [] => 'Nessun piano rate approvato in questo esercizio: il prospetto è vuoto.',
                default => sprintf('Gli importi sono quelli del preventivo, dal piano rate approvato («%s»): il conguaglio definitivo si fa sul rendiconto approvato dell\'esercizio.', $nomi),
            },
            $conIlPiano
                ? 'Le spese si dividono con la stessa regola del riparto: per giorni di conduzione e, dove più conduttori sono stati insieme nell\'anno, per quota e per giorni sull\'intera voce; non per consumi: dove c\'è la contabilizzazione del calore il risultato è un altro.'
                : 'Le spese si dividono per giorni di conduzione, con la stessa regola del riparto, e non per consumi: dove c\'è la contabilizzazione del calore il risultato è un altro.',
            $soloIlPiano
                ? 'Accanto a ogni voce: ' . $legendaDelPiano . ' Ciò che è nelle rate del conduttore è un pagamento diretto, che non lo rende debitore del condominio (Cass. 19650/2006).'
                : 'Accanto a ogni voce: «nelle sue rate» se la voce è già intestata al conduttore nelle rate — un pagamento diretto, che non lo rende debitore del condominio (Cass. 19650/2006); «con il conguaglio del cambio d\'inquilino del …» se un cambio d\'inquilino registrato dopo la generazione del piano ha dato quei giorni al nuovo conduttore con il conguaglio — anche questo è un pagamento diretto; '
                . ($altri !== ''
                    ? '«pagata da …» se l\'ha pagata qualcun altro: un titolare perché nei giorni del piano nessun inquilino era registrato' . $altri . ' — è la parte da rimborsare.'
                    : '«pagata da …» se l\'ha pagata un titolare perché nei giorni del piano nessun inquilino era registrato — è la parte da rimborsare.')
                . ($conIlPiano ? ' Dove la locazione è continuata con un coinquilino: ' . $legendaDelPiano : ''),
        ];
        foreach ($piani as $p) {
            if (! empty($p['dedotto'])) {
                // R13: un piano che non ricorda l'esercizio è attribuito dalla data di creazione, e si dice.
                $frasi[] = sprintf('Il piano «%s» non ricorda l\'esercizio con cui è stato generato (versione precedente): è attribuito a questo esercizio dalla data di creazione.', $p['nome']);
            }
            if ($p['fonte'] === DettaglioRiparto::RICOSTRUITO) {
                // Decisione 115.1: con la regola del piano il piano senza riparto si legge dalle quote pure nelle rate, non dal riparto ricostruito.
                $frasi[] = ! empty($p['del_piano'])
                    ? sprintf('Il piano «%s» è stato generato da una versione precedente e non ha il riparto registrato: la parte dell\'inquilino è quella delle quote nelle rate, tutta insieme, e si divide con la regola del piano.', $p['nome'])
                    : sprintf('Il piano «%s» è stato generato da una versione precedente e non ha il riparto registrato: gli importi sono ricostruiti dai dati di oggi.', $p['nome']);
            }
        }
        foreach ($esclusi as $e) {
            $frasi[] = sprintf('Il piano «%s» non compare: %s.', $e['nome'], $e['motivo']);
        }

        return $frasi;
    }
}
