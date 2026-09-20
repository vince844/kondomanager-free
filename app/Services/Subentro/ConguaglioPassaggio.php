<?php

namespace App\Services\Subentro;

use App\Enums\NaturaGestione;
use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RigaRiparto;
use App\Models\Gestionale\Subentro;
use App\Services\CalcoloQuoteService;
use App\Services\Riparto\CompetenzaDelPiano;
use App\Support\InsiemePeriodi;
use App\Support\PeriodoCompetenza;
use App\Support\ProRataTemporis;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Il blocco 2 del pannello «Cosa cambierà» **e** la coppia in `saldi` che `RegistraSubentroAction` scrive:
 * un solo calcolo (S5, «anteprima = scrittura»), per le quote **già emesse a chi esce** sulle unità del
 * passaggio (D9: le rate emesse non si toccano, si conguagliano).
 *
 * Per ogni quota emessa: la **quota pura del riparto** (`regole_calcolo.importi.quota_pura_gestione`), non
 * l'importo della quota — la parte che viene da un saldo pregresso è dell'uscente per definizione. Il
 * netting del già versato è suo **se la riga di `contributi_versati` porta la sua persona** (decisione 17):
 * non entra nella divisione e si toglie dalla sua parte; senza persona è dell'unità (D8) e abbassa la spesa
 * da dividere, come nel motore (S8-5). La competenza è la stessa del motore, decisa in un posto solo
 * (`CompetenzaDelPiano`, decisioni 11 e 12): ordinario → pro rata per giorni; straordinario → **per riga**
 * congelata (S8-4): la competenza dichiarata sulla fattura, altrimenti il giorno della delibera, tutto da
 * un lato. Straordinario senza competenza dichiarata e senza data della delibera: quel piano **si salta e
 * si dice**, non si inventa un periodo (decisione 12); gestione senza esercizio: idem.
 *
 * Usufrutto (`soloOrdinario`): le spese straordinarie sono del nudo proprietario per legge (art. 1005 c.c.),
 * quindi non si conguagliano mai con l'usufruttuario, qualunque sia la data della delibera.
 *
 * **Capitoli con tratti (S6, decisione 20).** Se il piano ha voci con una competenza propria in
 * `competenze_capitolo` (riscaldamento stagionale), la quota pura non è tutta sulla base: la si scompone
 * per conto con le `righe_riparto` di chi esce (il dettaglio congelato della generazione, beta.29), ogni
 * conto si divide per giorni sulla **sua** competenza — i tratti della pivot del conto, poi della radice,
 * come fa il motore, altrimenti la base — e la parte di chi entra così ottenuta si distribuisce sulle
 * quote emesse in proporzione alla quota pura. Le righe portano solo gli estremi dell'insieme
 * (decisione 15): i tratti veri si rileggono dalla tabella, non dalla riga.
 *
 * La coppia in `saldi` è **una per (gestione, unità)**, solo se l'importo è diverso da zero: credito
 * all'uscente (negativo), debito all'entrante (positivo), stesso `subentro_id` (invariante 19).
 */
final class ConguaglioPassaggio
{
    public function __construct(private readonly CompetenzaDelPiano $competenza = new CompetenzaDelPiano())
    {
    }

    /**
     * @param list<int> $immobileIds l'unità principale e le pertinenze spuntate
     * @return array{
     *   stato: 'calcolato'|'nessuna_rata',
     *   quote: list<array<string,mixed>>,
     *   per_gestione: list<array<string,mixed>>,
     *   coppie: list<array{gestione_id:int, gestione:string, immobile_id:int, esercizio_id:?int, importo:int}>,
     *   totale_entrante: int, totale_entrante_formattato: string,
     *   pregressi: int, non_risolte: list<array{piano:string, motivo:string}>, frasi: list<string>
     * }
     */
    public function calcola(Anagrafica $uscente, ?Anagrafica $entrante, array $immobileIds, CarbonImmutable $decorrenza, bool $soloOrdinario = false): array
    {
        $righe = $this->quoteConguagliabili((int) $uscente->id, $immobileIds);
        if ($righe->isEmpty()) {
            return $this->vuoto();
        }
        $piani = PianoRate::with('gestione')->findMany($righe->pluck('piano_rate_id')->unique())->keyBy('id');
        $esiti = [];
        $eserciziPerPiano = [];
        $dedotti = [];
        $nonRisolte = [];
        foreach ($piani as $piano) {
            // L'esercizio del piano una volta sola: quello con cui è stato generato (migrazione 9), altrimenti
            // dedotto — e in quel caso lo si dice (verifica S5, R8).
            $esercizio = $this->competenza->esercizioDelPiano($piano);
            $eserciziPerPiano[$piano->id] = $esercizio?->id;
            if ($esercizio !== null && $this->competenza->esercizioDedotto($piano)) {
                $dedotti[] = $piano->nome;
            }
            $esito = $this->competenza->perPiano($piano, $esercizio);
            if ($esito === null) {
                $nonRisolte[] = ['piano' => $piano->nome, 'motivo' => 'la gestione non è legata a nessun esercizio: la competenza non si può determinare'];
            }
            // Straordinario senza delibera: si decide dopo la scomposizione per conto (S8-4) — se ogni riga
            // congelata porta la competenza dichiarata sulla fattura, la delibera non serve.
            $esiti[$piano->id] = $esito;
        }

        // La quota pura per (piano, unità, intestatario) — chi esce e i suoi predecessori (S8-3) — e, dove il piano ha
        // righe congelate per quell'intestatario, la sua scomposizione per conto e per tratto.
        $quotaPuraDi = fn ($r) => (int) (((is_string($r->regole_calcolo) ? json_decode($r->regole_calcolo, true) : (array) $r->regole_calcolo)['importi']['quota_pura_gestione']) ?? $r->importo);
        $chiaveDi = fn ($r) => $r->piano_rate_id . '|' . $r->immobile_id . '|' . $r->anagrafica_id;
        $quotaPuraPer = [];
        foreach ($righe as $r) {
            $quotaPuraPer[$chiaveDi($r)] = ($quotaPuraPer[$chiaveDi($r)] ?? 0) + $quotaPuraDi($r);
        }
        // La scomposizione per conto ogni volta che il piano ha righe congelate per l'intestatario (S8-5): è la
        // strada del motore — il netting del già versato si spacca fra parte «della persona» e parte «dell'unità»
        // (decisione 17, D8) e ogni riga si divide sulla sua competenza ∩ il suo tratto di titolarità (migrazione 11,
        // verifica S8-bis L1-1/L1-2/L1-4: la stessa persona con due tratti, il predecessore già tagliato alla sua
        // uscita, chi esce chiuso prima della generazione). Vale anche per lo straordinario (S8-4): lì la competenza
        // è **per riga** (dichiarata sulla fattura, o la delibera), non una per piano. I piani senza righe
        // (pre-beta.29) restano sulla strada di prima: quota pura netta divisa sulla base.
        $perCapitolo = [];
        $entrantePerQuota = [];
        $uscentePerQuota = [];
        $gruppi = $righe->groupBy($chiaveDi);
        foreach ($gruppi as $chiave => $gruppo) {
            $primo = $gruppo->first();
            $piano = $piani[$primo->piano_rate_id];
            $esito = $esiti[$piano->id];
            $straordinaria = NaturaGestione::daStringa($piano->gestione?->tipo) === NaturaGestione::Straordinaria;
            if ($esito === null || (! $straordinaria && ! $esito->risolto())) {
                continue;
            }
            $tratti = $straordinaria ? [] : $this->trattiPerConto((int) $piano->id);
            $decorrenzaAcquisto = ! empty($primo->ereditata_da) && ! empty($primo->decorrenza_acquisto) ? CarbonImmutable::parse($primo->decorrenza_acquisto) : null;
            $dettaglio = $this->scomponiPerConto((int) $piano->id, (int) $primo->immobile_id, (int) $primo->anagrafica_id, $tratti, $esito->risolto() ? $esito->periodi : null, $decorrenza, $straordinaria, $decorrenzaAcquisto);
            if ($dettaglio === null) {
                continue;
            }
            // La parte di chi entra (e, per un predecessore, quella di chi esce), riportata alla quota pura emessa e
            // distribuita sulle quote (resti maggiori).
            $quotaPuraTot = $quotaPuraPer[$chiave] ?? 0;
            // Conto interamente già versato dalla persona (verifica S8-bis, L1-3): quota pura 0 su totale 0 — la
            // quota esiste (riga documentaria «coperta da versamento») ed è tutta emessa, fattore 1: il versato
            // di chi esce torna a chi esce per la parte di chi entra, non sparisce. Il totale può essere negativo
            // (nota di credito a riparto): il rapporto conserva il segno, un `> 0` azzerava il conguaglio (strada b, B2-2).
            $fattore = fn (int $parte) => $dettaglio['totale'] !== 0
                ? (int) round($quotaPuraTot * $parte / $dettaglio['totale'])
                : ($quotaPuraTot === 0 ? $parte : 0);
            $bersaglio = $fattore((int) $dettaglio['entrante']);
            $pesi = $gruppo->mapWithKeys(fn ($r) => [(int) $r->id => (float) abs($quotaPuraDi($r))])->all();
            foreach (MoneyHelper::ripartisciPerQuote($bersaglio, $pesi) as $rataQuoteId => $cents) {
                $entrantePerQuota[$rataQuoteId] = (int) $cents;
            }
            if ($decorrenzaAcquisto !== null) {
                foreach (MoneyHelper::ripartisciPerQuote($fattore((int) $dettaglio['uscente']), $pesi) as $rataQuoteId => $cents) {
                    $uscentePerQuota[$rataQuoteId] = (int) $cents;
                }
            }
            // Il dettaglio per conto riportato alla stessa grana della testa (verifica S6, R8): con l'emissione
            // parziale le righe dicevano gli importi dell'intero piano sotto un conguaglio calcolato sulle sole quote
            // emesse, e «→ a chi entra» era un numero diverso da quello scritto. Resti maggiori, così le righe
            // sommano esattamente alla testa; con l'emissione completa coincidono con l'intero piano.
            // Chiavi per posizione, non per conto: lo stesso conto può avere due competenze (S8-4) o due tratti (L1-1).
            // Ogni conto scalato col suo segno (strada b, B2-3): con una nota di credito accanto a una spesa i pesi in
            // valore assoluto davano righe tutte positive che non erano quelle emesse. Il resto dell'arrotondamento
            // va sul conto con l'importo assoluto maggiore, così le righe sommano esattamente alla testa.
            $scala = function (int $totale, string $campo) use ($fattore, $dettaglio): array {
                $valori = array_map(fn ($c) => $fattore((int) $c[$campo]), $dettaglio['conti']);
                $resto = $totale - (int) array_sum($valori);
                if ($resto !== 0 && $valori !== []) {
                    $indici = array_keys($valori);
                    usort($indici, fn ($a, $b) => abs((int) $dettaglio['conti'][$b][$campo]) <=> abs((int) $dettaglio['conti'][$a][$campo]));
                    $valori[$indici[0]] += $resto;
                }

                return $valori;
            };
            $importiEmessi = $scala($quotaPuraTot, 'importo');
            $entrantiEmessi = $scala($bersaglio, 'entrante');
            foreach ($dettaglio['conti'] as $i => $c) {
                $dettaglio['conti'][$i]['importo_emesso'] = (int) ($importiEmessi[$i] ?? 0);
                $dettaglio['conti'][$i]['importo_emesso_formattato'] = MoneyHelper::format((int) ($importiEmessi[$i] ?? 0));
                $dettaglio['conti'][$i]['entrante_emesso'] = (int) ($entrantiEmessi[$i] ?? 0);
                $dettaglio['conti'][$i]['entrante_emesso_formattato'] = MoneyHelper::format((int) ($entrantiEmessi[$i] ?? 0));
            }
            $perCapitolo[$chiave] = $dettaglio;
        }
        $unitaDelPiano = fn (PianoRate $piano) => $righe->where('piano_rate_id', $piano->id)->map($chiaveDi)->unique();
        foreach ($piani as $piano) {
            $esito = $esiti[$piano->id];
            if ($esito === null || ! $esito->richiedeDelibera) {
                continue;
            }
            // Risolto dalle righe se ogni (unità, intestatario) del piano ha la scomposizione e nessun conto è rimasto senza competenza.
            $risoltoDalleRighe = $unitaDelPiano($piano)->every(fn ($chiave) => isset($perCapitolo[$chiave]) && $perCapitolo[$chiave]['non_risolte'] === 0);
            if ($risoltoDalleRighe) {
                continue;
            }
            // S8-4: con un intervento urgente (art. 1135 co. 2 c.c.) non c'è una delibera da registrare e le rate
            // emesse non si ricalcolano: la strada è la competenza dichiarata sulle fatture del piano; per le quote
            // già emesse, un saldo manuale dal Wallet o la rinuncia motivata al conguaglio.
            $nonRisolte[] = ['piano' => $piano->nome, 'motivo' => $piano->tipo_autorizzazione === 'urgenza'
                ? 'intervento urgente senza delibera: la competenza si dichiara sulle fatture del piano (Costo maturato / Spesa deliberata il); le quote di questo piano non entrano nel conguaglio proposto — con rate già emesse si regola con un saldo manuale dal Wallet sulla stessa gestione'
                : 'manca la data della delibera dell\'assemblea: registrala sul piano e ricalcola'];
        }

        // Ramo base (nessuna riga congelata: piani pre-beta.29): la parte di chi entra si calcola **una volta** sulla
        // quota pura dell'intero (piano, unità, intestatario) e si distribuisce sulle quote con i resti maggiori — come
        // il ramo per conto e come il motore. Dividere quota per quota arrotondava ogni rata da sé (4 × 6.914 = 27.656
        // invece di 27.655). Per un predecessore (S8-3) la parte di chi esce è ciò che gli era passato all'acquisto
        // meno ciò che passa ora — senza righe non si sa quali giorni la quota coprisse, e si assume l'intero periodo.
        $baseDivisa = [];
        foreach ($gruppi as $chiave => $gruppo) {
            if (isset($perCapitolo[$chiave])) {
                continue;
            }
            $primo = $gruppo->first();
            $piano = $piani[$primo->piano_rate_id];
            $esito = $esiti[$piano->id];
            $esclusa = $soloOrdinario && NaturaGestione::daStringa($piano->gestione?->tipo) === NaturaGestione::Straordinaria;
            if ($esito === null || ! $esito->risolto() || $esclusa) {
                continue;
            }
            $quotaPuraTot = (int) $gruppo->sum($quotaPuraDi);
            $parti = ProRataTemporis::dividi($quotaPuraTot, $esito->periodi, $decorrenza);
            $pesi = $gruppo->mapWithKeys(fn ($r) => [(int) $r->id => (float) abs($quotaPuraDi($r))])->all();
            $voce = ['giorni' => ['giorni_uscente' => $parti['giorni_uscente'], 'giorni_entrante' => $parti['giorni_entrante'], 'giorni_periodo' => $parti['giorni_periodo']], 'entrante' => MoneyHelper::ripartisciPerQuote((int) $parti['entrante'], $pesi), 'uscente' => null];
            if (! empty($primo->ereditata_da) && ! empty($primo->decorrenza_acquisto)) {
                $acquisto = ProRataTemporis::dividi($quotaPuraTot, $esito->periodi, CarbonImmutable::parse($primo->decorrenza_acquisto));
                $voce['giorni'] = ['giorni_uscente' => max(0, $acquisto['giorni_entrante'] - $parti['giorni_entrante']), 'giorni_entrante' => min($parti['giorni_entrante'], $acquisto['giorni_entrante']), 'giorni_periodo' => $acquisto['giorni_entrante']];
                $voce['uscente'] = MoneyHelper::ripartisciPerQuote(max(0, (int) $acquisto['entrante'] - (int) $parti['entrante']), $pesi);
            }
            $baseDivisa[$chiave] = $voce;
        }

        $quote = [];
        foreach ($righe as $r) {
            $piano = $piani[$r->piano_rate_id];
            $esito = $esiti[$piano->id];
            $natura = NaturaGestione::daStringa($piano->gestione?->tipo);
            $quotaPura = $quotaPuraDi($r);
            $chiave = $chiaveDi($r);
            $capitoli = $perCapitolo[$chiave] ?? null;
            $risolta = ($esito !== null && $esito->risolto()) || $capitoli !== null;
            $esclusa = $soloOrdinario && $natura === NaturaGestione::Straordinaria;
            $ereditata = ! empty($r->ereditata_da);

            if ($capitoli !== null && ! $esclusa) {
                $parteEntrante = $entrantePerQuota[(int) $r->id] ?? 0;
                // Per una quota del predecessore chi esce ha solo la parte fra acquisto e decorrenza; il resto era già
                // del predecessore e non riguarda questo passaggio.
                $parteUscente = $ereditata ? ($uscentePerQuota[(int) $r->id] ?? 0) : $quotaPura - $parteEntrante;
                $parti = ['uscente' => $parteUscente, 'entrante' => $parteEntrante] + $capitoli['giorni'];
            } elseif (isset($baseDivisa[$chiave]) && $risolta && ! $esclusa) {
                $base = $baseDivisa[$chiave];
                $parteEntrante = (int) ($base['entrante'][(int) $r->id] ?? 0);
                $parteUscente = $base['uscente'] !== null ? (int) ($base['uscente'][(int) $r->id] ?? 0) : $quotaPura - $parteEntrante;
                $parti = ['uscente' => $parteUscente, 'entrante' => $parteEntrante] + $base['giorni'];
            } else {
                $parti = ['uscente' => $quotaPura, 'entrante' => 0, 'giorni_uscente' => null, 'giorni_entrante' => null, 'giorni_periodo' => null];
            }

            $quote[] = [
                'rata_quote_id'  => (int) $r->id,
                'immobile_id'    => (int) $r->immobile_id,
                'immobile_nome'  => $r->immobile_nome,
                'piano_rate_id'  => (int) $piano->id,
                'piano'          => $piano->nome,
                'esercizio_id'   => $eserciziPerPiano[$piano->id],
                'gestione_id'    => (int) $piano->gestione_id,
                'gestione'       => $piano->gestione?->nome,
                'natura'         => $natura->value,
                'rata'           => (int) $r->numero_rata,
                'scadenza'       => substr((string) $r->data_scadenza, 0, 10),
                'importo'        => (int) $r->importo,
                'importo_formattato' => MoneyHelper::format((int) $r->importo),
                'quota_pura'     => $quotaPura,
                'pregresso'      => (int) $r->importo - $quotaPura,
                'gradino'        => $risolta ? ($capitoli !== null && $capitoli['dettagliato'] ? $capitoli['gradino'] : $esito->gradino?->value) : null,
                'periodo'        => $risolta ? ($capitoli !== null && $natura === NaturaGestione::Straordinaria ? $capitoli['periodo'] : $esito->periodi?->toArray()) : null,
                // La divisione è per conto e il dettaglio sta qui (uguale per tutte le quote del piano sull'unità), quando dice qualcosa in più della testa.
                'per_capitolo'   => $capitoli !== null && $capitoli['dettagliato'] && ! $esclusa ? $capitoli['conti'] : null,
                'giorni_uscente' => $parti['giorni_uscente'],
                'giorni_entrante' => $parti['giorni_entrante'],
                'giorni_periodo' => $parti['giorni_periodo'],
                'uscente'        => $parti['uscente'],
                'entrante'       => $parti['entrante'],
                'entrante_formattato' => MoneyHelper::format((int) $parti['entrante']),
                'non_risolta'    => ! $risolta,
                'esclusa'        => $esclusa,
                // S8-3: la quota è di un predecessore di chi esce; la competenza gli è passata alla sua decorrenza.
                'ereditata_da'   => $r->ereditata_da ?? null,
                'decorrenza_acquisto' => $r->decorrenza_acquisto ?? null,
                // Decisione 21: quota ancora in bozza di un piano che non si può più ricalcolare.
                'in_bozza'       => (bool) ($r->in_bozza ?? false),
            ];
        }

        // Una coppia per (gestione, unità, esercizio del piano), solo se ≠ 0 (verifica S5, R9: due piani della
        // stessa gestione su esercizi diversi non si fondono su un esercizio solo). Dentro il gruppo i periodi
        // possono differire (piani con competenze diverse): i giorni e le frasi sono **per periodo** (R10).
        $perGestione = collect($quote)->groupBy(fn ($q) => $q['gestione_id'] . '|' . $q['immobile_id'] . '|' . ($q['esercizio_id'] ?? ''))->map(function (Collection $g) {
            $prima = $g->first();
            $perPeriodo = $g->filter(fn ($q) => ! $q['non_risolta'] && ! $q['esclusa'])
                ->groupBy(fn ($q) => json_encode([$q['periodo'], $q['per_capitolo'] === null ? null : $q['piano_rate_id'], $q['ereditata_da'], $q['decorrenza_acquisto']]))
                ->map(fn (Collection $p) => [
                    'periodo'        => $p->first()['periodo'],
                    'gradino'        => $p->first()['gradino'],
                    'quote'          => $p->count(),
                    'quota_pura'     => (int) $p->sum('quota_pura'),
                    'giorni_uscente' => $p->first()['giorni_uscente'],
                    'giorni_entrante' => $p->first()['giorni_entrante'],
                    'giorni_periodo' => $p->first()['giorni_periodo'],
                    'uscente'        => (int) $p->sum('uscente'),
                    'entrante'       => (int) $p->sum('entrante'),
                    'entrante_formattato' => MoneyHelper::format((int) $p->sum('entrante')),
                    'per_capitolo'   => $p->first()['per_capitolo'],
                    'ereditata_da'   => $p->first()['ereditata_da'],
                    'decorrenza_acquisto' => $p->first()['decorrenza_acquisto'],
                ])->values();
            $unico = $perPeriodo->count() === 1 ? $perPeriodo->first() : null;

            return [
                'gestione_id'    => $prima['gestione_id'],
                'gestione'       => $prima['gestione'],
                'immobile_id'    => $prima['immobile_id'],
                'immobile_nome'  => $prima['immobile_nome'],
                'natura'         => $prima['natura'],
                'gradino'        => $g->pluck('gradino')->filter()->unique()->values()->all(),
                // Periodo e giorni a livello di gruppo solo quando il periodo è uno; altrimenti `per_periodo`.
                'periodo'        => $unico['periodo'] ?? null,
                'quote'          => $g->count(),
                'quota_pura'     => (int) $g->sum('quota_pura'),
                'pregressi'      => (int) $g->sum('pregresso'),
                'giorni_uscente' => $unico['giorni_uscente'] ?? null,
                'giorni_entrante' => $unico['giorni_entrante'] ?? null,
                'giorni_periodo' => $unico['giorni_periodo'] ?? null,
                'per_periodo'    => $perPeriodo->all(),
                'importo'        => (int) $g->sum('entrante'),
                'importo_formattato' => MoneyHelper::format((int) $g->sum('entrante')),
                'non_risolte'    => $g->where('non_risolta', true)->count(),
                'escluse'        => $g->where('esclusa', true)->count(),
                'esercizio_id'   => $prima['esercizio_id'],
            ];
        })->values();

        $coppie = $perGestione->filter(fn ($g) => $g['importo'] !== 0)->map(fn ($g) => [
            'gestione_id'  => $g['gestione_id'],
            'gestione'     => $g['gestione'],
            'immobile_id'  => $g['immobile_id'],
            'esercizio_id' => $g['esercizio_id'],
            'importo'      => $g['importo'],
        ])->values()->all();

        $totale = (int) array_sum(array_column($coppie, 'importo'));

        // Decisione 21: le quote in bozza comprese, per piano e per intestatario (con la catena dei passaggi le bozze
        // possono essere del predecessore, non di chi esce: la frase deve dire a chi resteranno intestate).
        $inBozza = collect($quote)->where('in_bozza', true)
            ->groupBy(fn ($q) => $q['piano'] . '|' . ($q['ereditata_da'] ?? $uscente->nome))
            ->map(fn (Collection $g) => ['piano' => $g->first()['piano'], 'intestatario' => $g->first()['ereditata_da'] ?? $uscente->nome, 'n' => $g->count()])
            ->values()->all();

        return [
            'stato'          => 'calcolato',
            'anagrafica_uscente_id'  => (int) $uscente->id,
            'anagrafica_entrante_id' => $entrante?->id,
            'quote'          => $quote,
            'quote_in_bozza' => $inBozza,
            'per_gestione'   => $perGestione->all(),
            'coppie'         => $coppie,
            'totale_entrante' => $totale,
            'totale_entrante_formattato' => MoneyHelper::format($totale),
            'pregressi'      => (int) collect($quote)->sum('pregresso'),
            'non_risolte'    => $nonRisolte,
            'esercizi_dedotti' => $dedotti,
            'frasi'          => $this->frasi($perGestione, $nonRisolte, $uscente->nome, $entrante?->nome, $decorrenza, $soloOrdinario, (int) collect($quote)->sum('pregresso'), $dedotti, $inBozza),
        ];
    }

    /**
     * Chi, oltre a chi esce, ha quote emesse che il conguaglio riguarda: i suoi **predecessori** sulle stesse
     * unità, risalendo la catena dei passaggi registrati (Fase 1-bis, S8-3). Chi ha comprato a maggio con la
     * coppia di conguaglio e rivende a settembre non ha quote emesse a suo nome: quelle del venditore di maggio
     * portano la competenza che gli è passata, e passano ancora. Si include anche chi ha rinunciato o annullato
     * la coppia: il conguaglio si propone, non si impone, e l'amministratore può rinunciare di nuovo.
     *
     * @return array<int, array{nome: ?string, decorrenza_acquisto: string}> predecessore → quando chi esce ha acquistato
     */
    public function predecessori(int $anagraficaId, array $immobileIds): array
    {
        $trovati = [];
        $frontiera = [$anagraficaId];
        $visti = [$anagraficaId => true];
        while ($frontiera !== []) {
            $prossimi = [];
            foreach (Subentro::with('uscente:id,nome')->whereIn('immobile_id', $immobileIds)->whereIn('anagrafica_entrante_id', $frontiera)->whereNotNull('anagrafica_uscente_id')->orderBy('decorrenza')->get() as $s) {
                $pid = (int) $s->anagrafica_uscente_id;
                if (isset($visti[$pid])) {
                    continue;
                }
                $visti[$pid] = true;
                // La data in cui la competenza di questo predecessore è passata a chi esce (verifica S8-bis, L1-6):
                // al primo anello è la decorrenza del passaggio verso chi esce; agli anelli successivi è quella
                // ereditata dal proprio antenato di primo anello — chi ha comprato da A il 1/3 e da B il 1/6 ha
                // due date, una per quota.
                $decorrenzaAcquisto = (int) $s->anagrafica_entrante_id === $anagraficaId
                    ? $s->decorrenza->toDateString()
                    : $trovati[(int) $s->anagrafica_entrante_id]['decorrenza_acquisto'];
                $trovati[$pid] = ['nome' => $s->uscente?->nome, 'decorrenza_acquisto' => $decorrenzaAcquisto, 'riga_uscente_id' => $s->riga_uscente_id !== null ? (int) $s->riga_uscente_id : null];
                $prossimi[] = $pid;
            }
            $frontiera = $prossimi;
        }

        return $trovati;
    }

    /** Gli intestatari le cui quote emesse il conguaglio riguarda: chi esce e i suoi predecessori (per l'anteprima). */
    public function intestatariConguagliabili(int $anagraficaId, array $immobileIds): array
    {
        return array_values(array_unique([$anagraficaId, ...array_keys($this->predecessori($anagraficaId, $immobileIds))]));
    }

    /**
     * Le quote emesse a chi esce — e ai suoi predecessori (S8-3) — sulle unità del passaggio, con ciò che serve a
     * decidere la competenza. Le righe ereditate portano `ereditata_da` e `decorrenza_acquisto`.
     */
    private function quoteConguagliabili(int $anagraficaId, array $immobileIds): Collection
    {
        $predecessori = $this->predecessori($anagraficaId, $immobileIds);

        return DB::table('rate_quote')
            ->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
            ->join('immobili', 'immobili.id', '=', 'rate_quote.immobile_id')
            ->whereIn('rate_quote.immobile_id', $immobileIds)
            ->whereIn('rate_quote.anagrafica_id', [$anagraficaId, ...array_keys($predecessori)])
            // Decisione 21 (S8-1): le quote **emesse**, più quelle ancora in bozza dei piani che hanno già emesso a
            // giornale (`PianoRate::haRateEmesse()`, lo stesso criterio che blocca il ricalcolo): quel piano non si
            // rigenera più, le sue bozze resteranno intestate a chi esce e si conguagliano qui, non con il cancello (2).
            ->where(fn ($q) => $q->where('rate.stato', 'emessa')
                ->orWhereIn('rate.piano_rate_id', DB::table('rate as r2')->join('rate_quote as q2', 'q2.rata_id', '=', 'r2.id')->whereNotNull('q2.scrittura_contabile_id')->select('r2.piano_rate_id')))
            ->where('rate_quote.stato', '!=', 'annullata')
            ->orderBy('rate.piano_rate_id')->orderBy('rate.numero_rata')
            ->get(['rate_quote.id', 'rate_quote.immobile_id', 'rate_quote.anagrafica_id', 'immobili.nome as immobile_nome', 'rate_quote.importo', 'rate_quote.regole_calcolo', 'rate.numero_rata', 'rate.data_scadenza', 'rate.piano_rate_id', 'rate.stato as stato_rata'])
            ->map(function ($r) use ($anagraficaId, $predecessori) {
                $pred = (int) $r->anagrafica_id !== $anagraficaId ? ($predecessori[(int) $r->anagrafica_id] ?? null) : null;
                $r->ereditata_da = $pred['nome'] ?? null;
                $r->decorrenza_acquisto = $pred['decorrenza_acquisto'] ?? null;
                $r->in_bozza = $r->stato_rata !== 'emessa';

                return $r;
            });
    }

    /**
     * I tratti di competenza delle voci del piano (S6, decisione 20), per `conto_id` della pivot.
     *
     * @return array<int, InsiemePeriodi>
     */
    private function trattiPerConto(int $pianoRateId): array
    {
        $righe = DB::table('competenze_capitolo as cc')
            ->join('piano_rate_capitoli as prc', 'prc.id', '=', 'cc.piano_rate_capitolo_id')
            ->where('prc.piano_rate_id', $pianoRateId)
            ->orderBy('cc.dal')
            ->get(['prc.conto_id', 'cc.dal', 'cc.al']);

        $perConto = [];
        foreach ($righe->groupBy('conto_id') as $contoId => $tratti) {
            $perConto[(int) $contoId] = new InsiemePeriodi(...$tratti->map(fn ($t) => new PeriodoCompetenza(substr((string) $t->dal, 0, 10), substr((string) $t->al, 0, 10)))->all());
        }

        return $perConto;
    }

    /**
     * La quota di un intestatario (chi esce, o un suo predecessore) su un'unità, **riga per riga** dalle
     * `righe_riparto` del piano, ognuna divisa sulla sua competenza ∩ il suo tratto di titolarità. Null se il piano
     * non ha righe per quel soggetto (piano generato prima della beta.29: si torna alla base, come prima di S6).
     *
     * - **Ordinario**: la competenza della riga è il tratto del pivot (del conto → della radice), altrimenti la base;
     *   gruppo per (conto, tratto di titolarità): la stessa persona con due righe sullo stesso conto — ha cambiato
     *   quota nell'anno, o ha preso un ripiego — ha due gruppi, ciascuno sui suoi giorni (S8-bis L1-1).
     * - **Straordinario (S8-4)**: la competenza è quella congelata **sulla riga** (`competenza_dal/al`, gradino
     *   `dichiarata` o `delibera`), gruppo per (conto, dal, al, tratto); riga senza competenza → la delibera del
     *   piano se c'è (`$base`), altrimenti il conto resta non risolto e per intero a chi esce.
     * - **Il tratto (migrazione 11)**: `titolarita_dal/al` è ciò che quei giorni coprono; la divisione è
     *   `competenza ∩ tratto` alla decorrenza — nessuna ipotesi su giorni «in coda». Righe senza tratto (scritte prima
     *   della migrazione 11, o atemporali) si dividono sull'intera competenza, come prima (S8-bis L1-2/L1-4).
     * - **Predecessore (S8-3)**: con `$decorrenzaAcquisto`, a chi entra va la parte dalla decorrenza in poi; a chi
     *   esce quella fra il suo acquisto e la decorrenza; ciò che precede l'acquisto resta del predecessore.
     * - **Netting del già versato (S8-5, decisione 17 + D8)**: il motore congela due righe `netting` per chiave —
     *   «già versato della persona» e «già versato dell'unità» (`CalcoloQuoteService::NETTING_*`) — e qui si
     *   rileggono così com'erano alla generazione, attribuite ai gruppi del conto in proporzione ai lordi. La parte
     *   dell'unità abbassa la spesa da dividere; la parte della persona **non entra nella divisione**: è un pagamento
     *   di chi esce e resta suo (`versato_uscente`), tolto dalla sua parte dopo la divisione al lordo. Così `entrante`
     *   può superare `totale`: è l'eccedenza di chi esce, come nel motore. Piani pre-B2 (una riga sola, senza
     *   descrizione): parte della persona zero.
     *
     * @param array<int, InsiemePeriodi> $tratti
     * @param ?InsiemePeriodi $base la competenza del piano quando è risolta (ordinario: base; straordinario: la delibera, usata come ripiego)
     * @return ?array{totale:int, uscente:int, entrante:int, versato_uscente:int, non_risolte:int, gradino:?string, periodo:?array, giorni:array, dettagliato:bool, conti:list<array<string,mixed>>}
     */
    private function scomponiPerConto(int $pianoRateId, int $immobileId, int $anagraficaId, array $tratti, ?InsiemePeriodi $base, CarbonImmutable $decorrenza, bool $straordinaria = false, ?CarbonImmutable $decorrenzaAcquisto = null): ?array
    {
        $righe = DB::table('righe_riparto')
            ->where('piano_rate_id', $pianoRateId)->where('immobile_id', $immobileId)->where('anagrafica_id', $anagraficaId)
            // Tutte le righe del soggetto, non solo i lordi (verifica S6, R7): il netting del già versato è una riga
            // negativa dello stesso conto e la quota pura emessa è già al netto — sommandola qui, `totale` torna a
            // essere la quota pura del piano e un conto interamente già versato pesa zero.
            ->whereIn('tipo', [RigaRiparto::TIPO_RIPARTO, RigaRiparto::TIPO_NETTING, RigaRiparto::TIPO_AD_PERSONAM])
            ->get(['conto_id', 'conto_nome', 'conto_radice_id', 'importo', 'tipo', 'giorni_titolarita', 'competenza_dal', 'competenza_al', 'gradino_competenza', 'riga_descrizione', 'titolarita_dal', 'titolarita_al']);
        if ($righe->isEmpty()) {
            return null;
        }

        $giorno = fn ($d) => $d === null ? null : substr((string) $d, 0, 10);
        $lordi = $righe->whereIn('tipo', [RigaRiparto::TIPO_RIPARTO, RigaRiparto::TIPO_AD_PERSONAM]);
        $nettingRighe = $righe->where('tipo', RigaRiparto::TIPO_NETTING);
        $chiave = fn ($r) => (int) ($r->conto_id ?? 0) . '|' . ($straordinaria ? $giorno($r->competenza_dal) . '|' . $giorno($r->competenza_al) : '') . '|' . $giorno($r->titolarita_dal) . '|' . $giorno($r->titolarita_al);
        $gruppi = $lordi->groupBy($chiave);
        // Netting per conto (persona / unità), attribuito ai gruppi del conto in proporzione ai lordi.
        $nettingPerConto = [];
        foreach ($nettingRighe->groupBy(fn ($r) => (int) ($r->conto_id ?? 0)) as $contoId => $g) {
            $tot = (int) abs((int) $g->sum('importo'));
            $persona = min($tot, (int) abs((int) $g->where('riga_descrizione', CalcoloQuoteService::NETTING_DELLA_PERSONA)->sum('importo')));
            $gruppiDelConto = $gruppi->filter(fn ($gr, $k) => (int) explode('|', (string) $k)[0] === (int) $contoId)->keys()->all();
            if ($gruppiDelConto === []) {
                // Netting senza lordo (conto interamente versato con lordo su un'altra chiamata): un gruppo a sé, a zero.
                $gruppi[(int) $contoId . '|netting'] = $g->take(0);
                $gruppiDelConto = [(int) $contoId . '|netting'];
            }
            $pesiLordi = array_map(fn ($k) => (float) abs((int) $gruppi[$k]->sum('importo')), $gruppiDelConto);
            $distribuito = MoneyHelper::ripartisciPerQuote($tot, array_combine($gruppiDelConto, $pesiLordi));
            $distribuitoPersona = MoneyHelper::ripartisciPerQuote($persona, array_combine($gruppiDelConto, $pesiLordi));
            foreach ($gruppiDelConto as $k) {
                $nettingPerConto[$k] = ['netting' => (int) ($distribuito[$k] ?? 0), 'persona' => (int) ($distribuitoPersona[$k] ?? 0), 'conto' => $g->first()];
            }
        }

        $conti = [];
        foreach ($gruppi as $k => $gruppo) {
            $primo = $gruppo->first() ?? $nettingPerConto[$k]['conto'];
            $contoId = (int) ($primo->conto_id ?? 0);
            $lordo = (int) $gruppo->sum('importo');
            $netting = (int) ($nettingPerConto[$k]['netting'] ?? 0);
            $np = (int) ($nettingPerConto[$k]['persona'] ?? 0);
            $nu = $netting - $np;
            $importo = $lordo - $netting;

            if ($straordinaria) {
                $rigaConCompetenza = $primo->competenza_dal !== null && $primo->competenza_al !== null;
                $competenza = $rigaConCompetenza ? InsiemePeriodi::uno(new PeriodoCompetenza($giorno($primo->competenza_dal), $giorno($primo->competenza_al))) : $base;
                $gradino = $rigaConCompetenza ? ($primo->gradino_competenza ?: 'dichiarata') : ($base !== null ? 'delibera' : null);
            } else {
                $tratto = $tratti[$contoId] ?? $tratti[(int) ($primo->conto_radice_id ?? 0)] ?? null;
                $competenza = $tratto ?? $base;
                $gradino = $tratto !== null ? 'capitolo' : 'base';
            }
            // Migrazione 11: la riga copre solo il suo tratto di titolarità — si divide su competenza ∩ tratto. Se il
            // tratto congelato non tocca la competenza di oggi (competenza del piano cambiata dopo l'emissione) la voce
            // non è risolta e lo dice: ripiegare in silenzio sull'intera competenza divideva giorni che la riga non
            // copre (strada b, B2-4). Le righe senza tratto (pre-migrazione 11) restano sulla competenza intera.
            $trattoRiga = $primo->titolarita_dal !== null && $primo->titolarita_al !== null ? new PeriodoCompetenza($giorno($primo->titolarita_dal), $giorno($primo->titolarita_al)) : null;
            $competenzaRiga = $competenza !== null && $trattoRiga !== null ? $competenza->intersezione($trattoRiga) : $competenza;
            $trattoFuoriCompetenza = $competenza !== null && $trattoRiga !== null && $competenzaRiga === null;

            if ($competenzaRiga === null) {
                $parti = ['uscente' => $lordo - $nu, 'entrante' => 0, 'giorni_uscente' => null, 'giorni_entrante' => null, 'giorni_periodo' => null, 'giorni_predecessore' => null];
            } elseif ($decorrenzaAcquisto !== null) {
                // Predecessore (S8-3): a chi entra dalla decorrenza in poi; a chi esce fra il suo acquisto e la decorrenza.
                $adesso = ProRataTemporis::dividi($lordo - $nu, $competenzaRiga, $decorrenza);
                $acquisto = ProRataTemporis::dividi($lordo - $nu, $competenzaRiga, $decorrenzaAcquisto);
                $parti = [
                    'uscente'         => max(0, $acquisto['entrante'] - $adesso['entrante']),
                    'entrante'        => min($adesso['entrante'], $acquisto['entrante']),
                    'giorni_uscente'  => max(0, $acquisto['giorni_entrante'] - $adesso['giorni_entrante']),
                    'giorni_entrante' => min($adesso['giorni_entrante'], $acquisto['giorni_entrante']),
                    'giorni_periodo'  => $acquisto['giorni_entrante'],
                    // I giorni prima dell'acquisto: restano al predecessore, e le frasi voce per voce li dicono (B2-1).
                    'giorni_predecessore' => $acquisto['giorni_uscente'],
                ];
            } else {
                $parti = ProRataTemporis::dividi($lordo - $nu, $competenzaRiga, $decorrenza) + ['giorni_predecessore' => null];
            }
            $conti[] = [
                'conto_id'        => $contoId,
                'conto'           => $primo->conto_nome,
                'importo'         => $importo,
                'importo_formattato' => MoneyHelper::format($importo),
                'versato_uscente' => $np,
                'gradino'         => $gradino,
                'periodo'         => $competenzaRiga?->toArray(),
                'tratto'          => $trattoRiga?->toArray(),
                'non_risolta'     => $competenzaRiga === null,
                'motivo'          => $trattoFuoriCompetenza ? 'tratto_fuori_competenza' : null,
                'competenza_oggi' => $trattoFuoriCompetenza ? $competenza->toArray() : null,
                'giorni_uscente'  => $parti['giorni_uscente'],
                'giorni_entrante' => $parti['giorni_entrante'],
                'giorni_periodo'  => $parti['giorni_periodo'],
                'giorni_predecessore' => $parti['giorni_predecessore'],
                'uscente'         => $parti['uscente'] - ($decorrenzaAcquisto !== null ? 0 : $np),
                'entrante'        => $parti['entrante'],
                'entrante_formattato' => MoneyHelper::format($parti['entrante']),
            ];
        }

        $gradini = array_values(array_unique(array_filter(array_column($conti, 'gradino'))));
        // I giorni a livello di quota, quando sono gli stessi per ogni riga (ordinario tutto sulla base, un tratto).
        $giorniDistinti = array_unique(array_map(fn ($c) => json_encode([$c['giorni_uscente'], $c['giorni_entrante'], $c['giorni_periodo']]), $conti));
        $giorni = count($giorniDistinti) === 1 ? ['giorni_uscente' => $conti[0]['giorni_uscente'], 'giorni_entrante' => $conti[0]['giorni_entrante'], 'giorni_periodo' => $conti[0]['giorni_periodo']] : ['giorni_uscente' => null, 'giorni_entrante' => null, 'giorni_periodo' => null];
        $versato = (int) array_sum(array_column($conti, 'versato_uscente'));
        $nonRisolte = count(array_filter($conti, fn ($c) => $c['non_risolta']));
        $contiDistinti = count(array_unique(array_column($conti, 'conto_id')));
        $periodi = [];
        foreach ($conti as $c) {
            foreach ($c['periodo'] ?? [] as $t) {
                $periodi[$t['dal'] . '|' . $t['al']] = $t;
            }
        }
        ksort($periodi);

        return [
            'totale'   => (int) array_sum(array_column($conti, 'importo')),
            'uscente'  => (int) array_sum(array_column($conti, 'uscente')),
            'entrante' => (int) array_sum(array_column($conti, 'entrante')),
            'versato_uscente' => $versato,
            'non_risolte' => $nonRisolte,
            // Un gradino solo quando è uno; con più gradini (dichiarata + delibera) si dice il primo e le righe dicono il resto.
            'gradino'  => $straordinaria ? ($gradini[0] ?? null) : 'capitolo',
            'periodo'  => $periodi === [] ? null : array_values($periodi),
            'giorni'   => $giorni,
            // Il dettaglio voce per voce si mostra solo quando dice qualcosa che la testa non dice: una voce con
            // competenza propria, lo straordinario per riga, un versato di chi esce, una voce non risolta, la stessa
            // voce su due tratti (L1-1). Un ordinario tutto sulla base si legge come prima («divisa in proporzione ai
            // giorni: N a X, M a Y»).
            'dettagliato' => $straordinaria || in_array('capitolo', $gradini, true) || $versato > 0 || $nonRisolte > 0 || count($conti) > $contiDistinti,
            'conti'    => $conti,
        ];
    }

    /**
     * Le frasi del blocco 2 dell'anteprima: una riga d'apertura, poi una per gestione (e per periodo, quando i piani
     * della stessa gestione hanno competenze diverse — verifica S5, R10), poi le non risolte e i piani con l'esercizio
     * dedotto. I testi sono quelli del §6 del progetto.
     *
     * @param list<array{piano: string, motivo: string}> $nonRisolte
     * @param list<string> $dedotti
     * @param list<array{piano: string, intestatario: string, n: int}> $inBozza
     */
    private function frasi(Collection $perGestione, array $nonRisolte, string $uscente, ?string $entrante, CarbonImmutable $decorrenza, bool $soloOrdinario, int $pregressi, array $dedotti = [], array $inBozza = []): array
    {
        $entrante ??= 'chi entra';
        $frasi = ['Le rate già emesse non si toccano. Il conguaglio è proposto come due righe di saldo che sommano a zero, per gestione:'];
        // Decisione 21: le bozze dei piani già a giornale sono comprese, e si dice quali — una riga a sé, perché
        // l'anteprima sostituisce la riga d'apertura con la propria e tiene le altre.
        foreach ($inBozza as $b) {
            $n = $b['n'];
            $frasi[] = $n === 1
                ? sprintf('Compresa la quota del piano «%s» non ancora emessa: il piano ha già emesso a giornale e non si può più ricalcolare, quindi resterà intestata a %s e si conguaglia qui.', $b['piano'], $b['intestatario'])
                : sprintf('Comprese le %d quote del piano «%s» non ancora emesse: il piano ha già emesso a giornale e non si può più ricalcolare, quindi resteranno intestate a %s e si conguagliano qui.', $n, $b['piano'], $b['intestatario']);
        }

        foreach ($perGestione as $g) {
            $nome = $g['gestione'] ?? 'gestione';
            if ($g['non_risolte'] > 0 && $g['importo'] === 0) {
                continue; // la dice la riga delle non risolte
            }
            if ($g['escluse'] > 0 && $g['importo'] === 0) {
                $frasi[] = sprintf('Sulla gestione %s (straordinaria): %s restano interamente a %s — le spese straordinarie sono del nudo proprietario (art. 1005 c.c.), il passaggio non le tocca.', $nome, MoneyHelper::format($g['quota_pura']), $uscente);
                continue;
            }
            if ($g['natura'] === NaturaGestione::Straordinaria->value) {
                // Con più delibere sulla stessa gestione, ciascuna con la sua sorte (R10).
                foreach ($g['per_periodo'] as $p) {
                    if (($p['per_capitolo'] ?? null) !== null) {
                        // S8-4: la competenza è per riga (dichiarata sulla fattura, o la delibera): si leggono le voci.
                        // Con una quota del predecessore (S8-3) la testa lo nomina, e le voci dicono di chi era l'unità (B2-1).
                        $ereditata = ! empty($p['ereditata_da']) ? sprintf(' (emesse a %s: la competenza è passata a %s dal %s con un passaggio precedente)', $p['ereditata_da'], $uscente, $this->data($p['decorrenza_acquisto'])) : '';
                        $frasi[] = $p['entrante'] === 0
                            ? ($ereditata !== ''
                                ? sprintf('Sulla gestione %s (straordinaria): %s%s non passano a chi entra — la competenza di ogni voce, dichiarata sulla fattura o alla data della delibera, cade prima del passaggio:', $nome, MoneyHelper::format($p['quota_pura']), $ereditata)
                                : sprintf('Sulla gestione %s (straordinaria): %s restano a %s — la competenza di ogni voce, dichiarata sulla fattura o alla data della delibera, cade prima del passaggio:', $nome, MoneyHelper::format($p['quota_pura']), $uscente))
                            : sprintf('Sulla gestione %s (straordinaria): credito %s a %s, debito %s a %s%s — voce per voce, ognuna sulla sua competenza (dichiarata sulla fattura, o la data della delibera; art. 63 disp. att. c.c.):', $nome, $p['entrante_formattato'], $uscente, $p['entrante_formattato'], $entrante, $ereditata);
                        array_push($frasi, ...$this->frasiPerCapitolo($p['per_capitolo'], $uscente, $entrante, $decorrenza, true, $p['ereditata_da'] ?? null));
                        continue;
                    }
                    $delibera = $p['periodo'][0]['dal'] ?? null;
                    $frasi[] = $p['entrante'] === 0
                        ? sprintf('Sulla gestione %s (straordinaria): %s restano interamente a %s, perché l\'assemblea ha deliberato il %s, quando l\'unità era sua (art. 63 disp. att. c.c.; Cass. civ. 30 agosto 2025 n. 24236).', $nome, MoneyHelper::format($p['quota_pura']), $uscente, $this->data($delibera))
                        : sprintf('Sulla gestione %s (straordinaria): credito %s a %s, debito %s a %s — la delibera del %s è del giorno del passaggio o successiva, la spesa è di chi entra (art. 63 disp. att. c.c.; Cass. 24654/2010).', $nome, $p['entrante_formattato'], $uscente, $p['entrante_formattato'], $entrante, $this->data($delibera));
                }
                continue;
            }
            if ($g['importo'] === 0) {
                // Con giorni di chi entra > 0 la ragione non è la competenza: la quota pura è zero (versato dell'unità
                // al 100 %, D8, o solo saldi pregressi) e non c'è nulla da dividere (L1-3). Gli intestatari sono quelli
                // veri del gruppo — chi esce o un suo predecessore (L1-8).
                $intestatari = collect($g['per_periodo'])->map(fn ($p) => $p['ereditata_da'] ?? $uscente)->unique()->values()->all();
                $chi = $intestatari === [] ? $uscente : implode(' e ', $intestatari);
                $frasi[] = ($g['giorni_entrante'] ?? 0) > 0 || (int) $g['quota_pura'] === 0
                    ? sprintf('Sulla gestione %s: nessun conguaglio — la quota emessa a %s è %s (interamente coperta dal già versato dell\'unità, o fatta solo di saldi pregressi): non c\'è nulla da dividere.', $nome, $chi, MoneyHelper::format($g['quota_pura']))
                    : sprintf('Sulla gestione %s: nessun conguaglio — la competenza delle quote emesse a %s (%s) finisce prima del %s.', $nome, $chi, MoneyHelper::format($g['quota_pura']), $this->data($decorrenza->toDateString()));
                continue;
            }
            if (count($g['per_periodo']) === 1 && ($g['per_periodo'][0]['per_capitolo'] ?? null) === null) {
                $p0 = $g['per_periodo'][0];
                if (! empty($p0['ereditata_da'])) {
                    // S8-3: la quota è emessa a un predecessore; passa la competenza che era passata a chi esce (L1-7: vale
                    // per vendita, locazione, usufrutto e per catene di qualunque lunghezza).
                    $frasi[] = sprintf('Sulla gestione %s: credito %s a %s, debito %s a %s — la quota ordinaria (%s su %d %s) è emessa a %s: la sua competenza è passata a %s dal %s con un passaggio precedente, e quella parte (%d giorni) è divisa in proporzione ai giorni: %d a %s, %d a %s.',
                        $nome, $g['importo_formattato'], $uscente, $g['importo_formattato'], $entrante,
                        MoneyHelper::format($g['quota_pura']), $g['quote'], $g['quote'] === 1 ? 'quota' : 'quote',
                        $p0['ereditata_da'], $uscente, $this->data($p0['decorrenza_acquisto']), (int) $p0['giorni_periodo'],
                        (int) $g['giorni_uscente'], $uscente, (int) $g['giorni_entrante'], $entrante);
                    continue;
                }
                $frasi[] = sprintf('Sulla gestione %s: credito %s a %s, debito %s a %s — la quota ordinaria (%s su %d %s) è divisa in proporzione ai giorni di competenza: %d a %s, %d a %s.',
                    $nome, $g['importo_formattato'], $uscente, $g['importo_formattato'], $entrante,
                    MoneyHelper::format($g['quota_pura']), $g['quote'], $g['quote'] === 1 ? 'quota' : 'quote',
                    (int) $g['giorni_uscente'], $uscente, (int) $g['giorni_entrante'], $entrante);
                continue;
            }
            if (count($g['per_periodo']) === 1) {
                // Voci con una competenza propria: la divisione è per conto, e si leggono i conti. Con una quota del
                // predecessore (S8-3) la testa dice a chi è emessa e da quando la competenza è di chi esce (B2-1).
                $p0 = $g['per_periodo'][0];
                $frasi[] = ! empty($p0['ereditata_da'])
                    ? sprintf('Sulla gestione %s: credito %s a %s, debito %s a %s — la quota ordinaria (%s su %d %s) è emessa a %s: la sua competenza è passata a %s dal %s con un passaggio precedente, e quella parte è divisa voce per voce, ognuna sui giorni della sua competenza:',
                        $nome, $g['importo_formattato'], $uscente, $g['importo_formattato'], $entrante, MoneyHelper::format($g['quota_pura']), $g['quote'], $g['quote'] === 1 ? 'quota' : 'quote', $p0['ereditata_da'], $uscente, $this->data($p0['decorrenza_acquisto']))
                    : sprintf('Sulla gestione %s: credito %s a %s, debito %s a %s — la quota ordinaria (%s su %d %s) è divisa voce per voce, ognuna sui giorni della sua competenza:',
                        $nome, $g['importo_formattato'], $uscente, $g['importo_formattato'], $entrante, MoneyHelper::format($g['quota_pura']), $g['quote'], $g['quote'] === 1 ? 'quota' : 'quote');
                array_push($frasi, ...$this->frasiPerCapitolo($p0['per_capitolo'], $uscente, $entrante, $decorrenza, false, $p0['ereditata_da'] ?? null));
                continue;
            }
            // Più piani con competenze diverse sulla stessa gestione: una riga per periodo, così i giorni
            // che si leggono sono quelli che hanno prodotto l'importo (verifica S5, R10). Stesso periodo ma quote di
            // predecessori diversi o acquistate in date diverse (L1-6): non sono «piani con competenze diverse».
            $periodiDistinti = count(array_unique(array_map(fn ($p) => json_encode($p['periodo']), $g['per_periodo'])));
            $frasi[] = sprintf('Sulla gestione %s: credito %s a %s, debito %s a %s, %s:', $nome, $g['importo_formattato'], $uscente, $g['importo_formattato'], $entrante, $periodiDistinti > 1 ? 'da piani con competenze diverse' : 'da quote emesse a intestatari diversi o acquistate in date diverse');
            foreach ($g['per_periodo'] as $p) {
                if (($p['per_capitolo'] ?? null) !== null) {
                    $emesseA = ! empty($p['ereditata_da']) ? sprintf(', emesse a %s; a %s dal %s', $p['ereditata_da'], $uscente, $this->data($p['decorrenza_acquisto'])) : '';
                    $frasi[] = sprintf('— %s (%d %s%s) divisi voce per voce, ognuna sui giorni della sua competenza → %s a chi entra:', MoneyHelper::format($p['quota_pura']), $p['quote'], $p['quote'] === 1 ? 'quota' : 'quote', $emesseA, $p['entrante_formattato']);
                    array_push($frasi, ...$this->frasiPerCapitolo($p['per_capitolo'], $uscente, $entrante, $decorrenza, false, $p['ereditata_da'] ?? null));
                    continue;
                }
                $tratti = implode(' + ', array_map(fn ($t) => $this->data($t['dal']) . '–' . $this->data($t['al']), $p['periodo'] ?? []));
                if (! empty($p['ereditata_da'])) {
                    $tratti .= sprintf(', emesse a %s; a %s dal %s', $p['ereditata_da'], $uscente, $this->data($p['decorrenza_acquisto']));
                }
                $frasi[] = $p['entrante'] === 0
                    ? sprintf('— %s (%d %s, competenza %s) restano a %s: la competenza finisce prima del %s.', MoneyHelper::format($p['quota_pura']), $p['quote'], $p['quote'] === 1 ? 'quota' : 'quote', $tratti, $uscente, $this->data($decorrenza->toDateString()))
                    : sprintf('— %s (%d %s, competenza %s) divisi per giorni: %d a %s, %d a %s → %s a chi entra.', MoneyHelper::format($p['quota_pura']), $p['quote'], $p['quote'] === 1 ? 'quota' : 'quote', $tratti, (int) $p['giorni_uscente'], $uscente, (int) $p['giorni_entrante'], $entrante, $p['entrante_formattato']);
            }
        }

        foreach ($nonRisolte as $n) {
            $frasi[] = sprintf('Piano «%s»: %s. Nessun conguaglio proposto su quelle quote.', $n['piano'], $n['motivo']);
        }
        if ($dedotti !== []) {
            $frasi[] = sprintf('%s «%s» non %s in quale esercizio %s generat%s (piano di una versione precedente): l\'esercizio è stato dedotto dalla data di creazione.', count($dedotti) === 1 ? 'Il piano' : 'I piani', implode('», «', $dedotti), count($dedotti) === 1 ? 'ricorda' : 'ricordano', count($dedotti) === 1 ? 'è stato' : 'sono stati', count($dedotti) === 1 ? 'o' : 'i');
        }
        if ($pregressi !== 0) {
            $frasi[] = sprintf('%s delle quote emesse a %s sono saldi pregressi (conguagli di esercizi passati o di un passaggio precedente): non si dividono, restano suoi.', MoneyHelper::format($pregressi), $uscente);
        }

        return $frasi;
    }

    /**
     * Le righe «voce per voce». `$predecessore` è l'intestatario delle quote quando non è chi esce (S8-3): le righe
     * dicono i giorni che restano a chi le ha emesse, quelli di chi esce e quelli di chi entra (B2-1).
     *
     * @param list<array<string,mixed>> $conti
     */
    private function frasiPerCapitolo(array $conti, string $uscente, ?string $entrante, CarbonImmutable $decorrenza, bool $straordinaria = false, ?string $predecessore = null): array
    {
        $frasi = [];
        $versato = 0;
        $intestatario = $predecessore ?? $uscente;
        foreach ($conti as $c) {
            $versato += (int) ($c['versato_uscente'] ?? 0);
            // Gli importi sono quelli riportati alle quote emesse (R8): le righe sommano alla testa.
            $importo = $c['importo_emesso_formattato'] ?? $c['importo_formattato'];
            $parteEntrante = $c['entrante_emesso'] ?? $c['entrante'];
            if (! empty($c['non_risolta'])) {
                if (($c['motivo'] ?? null) === 'tratto_fuori_competenza') {
                    // B2-4: la riga è congelata su un tratto che la competenza di oggi non tocca (competenza del piano
                    // cambiata dopo l'emissione): non si divide per giorni che la riga non copre.
                    // `tratto` è un periodo solo ({dal, al}), `competenza_oggi` un insieme (lista di periodi).
                    $tratto = $this->data($c['tratto']['dal'] ?? null) . '–' . $this->data($c['tratto']['al'] ?? null);
                    $oggi = implode(' + ', array_map(fn ($t) => $this->data($t['dal']) . '–' . $this->data($t['al']), $c['competenza_oggi'] ?? []));
                    $frasi[] = sprintf('  · %s: %s, titolarità %s — la riga emessa copre un tratto che la competenza di oggi del piano (%s) non tocca: resta a %s, nessun conguaglio su questa voce finché il piano non è ricalcolato.', $c['conto'], $importo, $tratto, $oggi, $intestatario);
                    continue;
                }
                $frasi[] = sprintf('  · %s: %s — senza competenza dichiarata sulla fattura e senza delibera: resta a %s, nessun conguaglio su questa voce.', $c['conto'], $importo, $intestatario);
                continue;
            }
            $tratti = implode(' + ', array_map(fn ($t) => $t['dal'] === $t['al'] ? $this->data($t['dal']) : $this->data($t['dal']) . '–' . $this->data($t['al']), $c['periodo']));
            $etichetta = match ($c['gradino'] ?? null) {
                'dichiarata' => 'competenza dichiarata ' . $tratti,
                'delibera'   => 'delibera del ' . $tratti,
                default      => 'competenza ' . $tratti,
            };
            if ($predecessore !== null && ! $straordinaria) {
                // Quota del predecessore: i giorni prima dell'acquisto di chi esce restano a chi ha emesso la quota.
                $parti = array_filter([
                    ($c['giorni_predecessore'] ?? 0) > 0 ? sprintf('%d giorni a %s', (int) $c['giorni_predecessore'], $predecessore) : null,
                    ($c['giorni_uscente'] ?? 0) > 0 ? sprintf('%d a %s', (int) $c['giorni_uscente'], $uscente) : null,
                    ($c['giorni_entrante'] ?? 0) > 0 ? sprintf('%d a %s', (int) $c['giorni_entrante'], $entrante) : null,
                ]);
                $frasi[] = $parteEntrante === 0
                    ? sprintf('  · %s: %s, %s — emessa a %s, la competenza finisce prima del %s: nessun conguaglio su questa voce.', $c['conto'], $importo, $etichetta, $predecessore, $this->data($decorrenza->toDateString()))
                    : sprintf('  · %s: %s, %s — emessa a %s: %s → %s a chi entra.', $c['conto'], $importo, $etichetta, $predecessore, implode(', ', $parti), $c['entrante_emesso_formattato'] ?? $c['entrante_formattato']);
            } elseif ($parteEntrante === 0) {
                // Straordinario a gradino: era di chi esce se il gradino cade fra il suo acquisto e il passaggio, altrimenti del predecessore.
                $eraDi = $predecessore !== null && ($c['giorni_uscente'] ?? 0) === 0 ? $predecessore : $uscente;
                $frasi[] = $straordinaria
                    ? sprintf('  · %s: %s, %s — %s, quando l\'unità era di %s: la spesa resta sua.', $c['conto'], $importo, $etichetta, ($c['gradino'] ?? null) === 'delibera' ? 'deliberata' : 'maturata', $eraDi)
                    : sprintf('  · %s: %s, %s — resta a %s, la competenza finisce prima del %s.', $c['conto'], $importo, $etichetta, $uscente, $this->data($decorrenza->toDateString()));
            } elseif (($c['giorni_uscente'] ?? 0) === 0) {
                $frasi[] = sprintf('  · %s: %s, %s — %s dal giorno del passaggio in poi: la spesa è di chi entra per intero → %s a chi entra.', $c['conto'], $importo, $etichetta, ($c['gradino'] ?? null) === 'delibera' ? 'deliberata' : 'maturata', $c['entrante_emesso_formattato'] ?? $c['entrante_formattato']);
            } else {
                $frasi[] = sprintf('  · %s: %s, %s — %d giorni a %s, %d a %s → %s a chi entra.', $c['conto'], $importo, $etichetta, (int) $c['giorni_uscente'], $uscente, (int) $c['giorni_entrante'], $entrante, $c['entrante_emesso_formattato'] ?? $c['entrante_formattato']);
            }
        }
        if ($versato > 0) {
            // S8-5: la parte «della persona» del già versato non entra nella divisione (decisione 17).
            $frasi[] = $straordinaria
                ? sprintf('  %s già versati da %s restano suoi: la spesa si attribuisce per competenza al lordo, e il versato torna col conguaglio.', MoneyHelper::format($versato), $intestatario)
                : sprintf('  %s già versati da %s restano suoi: la spesa è divisa per competenza al lordo, il versato si toglie dalla sua parte.', MoneyHelper::format($versato), $intestatario);
        }

        return $frasi;
    }

    private function vuoto(): array
    {
        return [
            'stato' => 'nessuna_rata', 'anagrafica_uscente_id' => null, 'anagrafica_entrante_id' => null, 'quote' => [], 'per_gestione' => [], 'coppie' => [],
            'totale_entrante' => 0, 'totale_entrante_formattato' => MoneyHelper::format(0),
            'pregressi' => 0, 'non_risolte' => [], 'esercizi_dedotti' => [], 'frasi' => [],
        ];
    }

    private function data(?string $iso): string
    {
        return $iso ? CarbonImmutable::parse($iso)->locale('it')->translatedFormat('j F Y') : '—';
    }
}
