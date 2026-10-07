<?php

namespace App\Services\Riparto;

use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Services\CalcoloQuoteService;
use App\Support\InsiemePeriodi;
use App\Support\PeriodoCompetenza;
use App\Support\ProRataTemporis;
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
                $this->dividiLeRate($piano, (int) $unita->id, $gruppo->values(), (int) ($nuRate[$k] ?? 0), $trattiConto, $esercizioInsieme, $base, $voci, $spostato);
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
     * @param Collection<int, array<string,mixed>> $gruppo
     * @param array<string, int> $spostato
     */
    private function dividiLeRate(PianoRate $piano, int $immobileId, Collection $gruppo, int $nettingDellUnita, array $trattiConto, ?InsiemePeriodi $esercizio, \Closure $base, array &$voci, array &$spostato): void
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
        foreach ($lordi as $i => $pz) {
            if ($i > 0) {
                $parti[$i] = $gruppo->count() === 1 ? [array_key_first($pesi) => (int) $pz['importo']] : MoneyHelper::ripartisciPerQuote((int) $pz['importo'], $pesi);
            }
        }
        foreach ($gruppo as $j => $r) {
            foreach ($lordi as $i => $pz) {
                $suo = $i > 0 ? (int) $parti[$i][$j] : (int) $r['importo'] - (int) array_sum(array_map(fn ($p) => (int) $p[$j], $parti));
                $voci[$pz['anagrafica_id']][] = $base($r, $competenza) + ['importo' => $suo, 'giorni' => $pz['giorni'], 'tratti' => $pz['tratti'], 'modo' => $pz['modo'], 'pagato_da' => null, 'conguaglio_del' => $pz['conguaglio_del']];
            }
        }
        foreach ($lordi as $i => $pz) {
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
     * sono pagati con il conguaglio.
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
            $passaggio = Subentro::where('immobile_id', $immobileId)->where('tipo_passaggio', 'fine_locazione')
                ->where('anagrafica_uscente_id', $chi)->whereNotNull('anagrafica_entrante_id')->whereNotIn('id', $visti)
                ->whereDate('decorrenza', '>', $restante->dal()->toDateString())->whereDate('decorrenza', '<=', $restante->al()->toDateString())
                ->orderBy('decorrenza')->orderBy('id')->first();
            $capo = $passaggio?->padre ?? $passaggio;
            if ($passaggio === null || $capo->conguaglioAnnullato() || $capo->conguaglioRinunciato() || ! $piano->presoNelConguaglioDa($passaggio)) {
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
            $modo = 'conguaglio';
            $del = $passaggio->decorrenza->toDateString();
        }
        $pezzi[] = ['anagrafica_id' => $chi, 'importo' => $resto, 'giorni' => $restante->giorni(), 'modo' => $modo, 'conguaglio_del' => $del, 'tratti' => $restante];

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
     * Una riga per (piano, voce, tabella, modo, chi ha pagato, cambio d'inquilino): due tratti della stessa voce si sommano, i
     * giorni pure.
     *
     * @param list<array<string,mixed>> $voci
     * @return list<array<string,mixed>>
     */
    private function accorpa(array $voci): array
    {
        return collect($voci)
            // R20: la competenza nella chiave — due righe della stessa voce con competenze diverse restano due.
            ->groupBy(fn ($v) => $v['piano_rate_id'] . '|' . $v['conto_id'] . '|' . $v['tabella_id'] . '|' . $v['modo'] . '|' . $v['pagato_da'] . '|' . ($v['conguaglio_del'] ?? '') . '|' . $v['conto'] . '|' . json_encode($v['competenza']) . '|' . ($v['nota'] ?? ''))
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
            'da_rimborsare' => $totale - $rate - $conguaglio,
            'da_rimborsare_formattato' => MoneyHelper::format($totale - $rate - $conguaglio),
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
            'Accanto a ogni voce: «nelle sue rate» se la voce è già intestata al conduttore nelle rate — un pagamento diretto, che non lo rende debitore del condominio (Cass. 19650/2006); «con il conguaglio del cambio d\'inquilino del …» se un cambio d\'inquilino registrato dopo la generazione del piano ha dato quei giorni al nuovo conduttore con il conguaglio — anche questo è un pagamento diretto; «pagata da …» se l\'ha pagata un titolare perché nei giorni del piano nessun inquilino era registrato — è la parte da rimborsare.',
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
