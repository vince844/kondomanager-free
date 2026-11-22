<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Services\Gestionale\DeliberaDellOpera;
use App\Helpers\DateHelper;
use App\Helpers\MoneyHelper;
use App\Http\Controllers\Assemblee\DeliberaNelleSchede;
use App\Http\Controllers\Controller;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RigaRiparto;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Services\Assemblee\DeliberaInParole;
use App\Services\Gestionale\Addebiti\AddebitiIndividuali;
use App\Services\Gestionale\Addebiti\PersoneDellAddebito;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gli addebiti a una persona sola, dalle tre porte: il riquadro delle quote non assegnate del piano, l'estratto conto della persona, la scheda
 * «Conguaglio» sulle spese non attribuite. La proposta (le persone a cui può andare, la proposta per prima; l'importo, la causale, la gestione), la
 * registrazione, l'annullamento.
 *
 * Le rotte stanno nel gruppo del gestionale, dietro il pannello. Le persone, le unità, i piani, le gestioni e gli addebiti della richiesta devono essere
 * di questo condominio: altrimenti 404. Gli importi arrivano in euro, come li scrive chi lavora, e diventano centesimi qui, una volta.
 *
 * Una spesa del ritardo che si fonda su una delibera dell'assemblea porta la delibera scelta con «Scegli la delibera» (`delibera_id`, del condominio della
 * rotta): la base è «delibera», il riferimento è la frase della delibera, e ogni quota addebitata si lega alla delibera (ruolo `base`, §1.4 del progetto
 * della tappa 2).
 */
class AddebitiController extends Controller
{
    public function __construct(private readonly AddebitiIndividuali $addebiti, private readonly PersoneDellAddebito $persone) {}

    /**
     * Che cosa la finestra propone. Tre forme: le quote non assegnate di un piano (`piano_rate_id` e `chiavi`); la persona dell'estratto conto
     * (`anagrafica_id`); le spese non attribuite di una gestione nell'esercizio del conguaglio (`gestione_id`, `esercizio_id`, e l'unità se scelta).
     */
    public function proposta(Request $request, Condominio $condominio): JsonResponse
    {
        $oggi = DateHelper::oggiUtente();
        // Il nome del condominio, per le frasi di «Scegli la delibera» quando la base è una delibera dell'assemblea.
        $nome = ['condominio' => (string) $condominio->nome];
        if ($request->filled('piano_rate_id')) {
            return response()->json($this->dalPiano($condominio, $request, $oggi) + $nome);
        }
        if ($request->filled('anagrafica_id')) {
            return response()->json($this->dallaPersona($condominio, $request, $oggi) + $nome);
        }
        abort_unless($request->filled('gestione_id') && $request->filled('esercizio_id'), 404);

        return response()->json($this->dalConguaglio($condominio, $request, $oggi) + $nome);
    }

    public function store(Request $request, Condominio $condominio): JsonResponse
    {
        $dati = $request->validate([
            'gestione_id' => ['required', 'integer'],
            'esercizio_id' => ['required', 'integer'],
            'data' => ['nullable', 'date_format:Y-m-d'],
            'voci' => ['required', 'array', 'min:1', 'max:500'],
            'voci.*.anagrafica_id' => ['required', 'integer'],
            'voci.*.immobile_id' => ['required', 'integer'],
            'voci.*.importo' => ['required'],
            'voci.*.causale' => ['required', 'string', 'max:255'],
            'voci.*.scadenza' => ['required', 'date_format:Y-m-d'],
            'voci.*.natura' => ['nullable', 'string', 'in:contributo,accessorio'],
            'voci.*.chiave' => ['nullable', 'string', 'max:255'],
            'voci.*.piano_origine_id' => ['nullable', 'integer'],
            'voci.*.riga_fattura_id' => ['nullable', 'integer'],
            'voci.*.conto_id' => ['nullable', 'integer'],
            'voci.*.gestione_origine_id' => ['nullable', 'integer'],
            'voci.*.esercizio_origine_id' => ['nullable', 'integer'],
            // Una spesa per il ritardo: il tipo (gli interessi di mora si registrano dalla loro proposta, con il calcolo), la base e il riferimento.
            'voci.*.tipo_accessorio' => ['nullable', 'string', 'in:' . implode(',', array_keys(array_diff_key(\App\Services\Gestionale\Accessori\BaseDegliAccessori::TIPI, ['interessi' => true])))],
            'voci.*.base' => ['nullable', 'string', 'in:' . implode(',', array_keys(\App\Services\Gestionale\Accessori\BaseDegliAccessori::BASI))],
            'voci.*.riferimento' => ['nullable', 'string', 'max:255'],
        ], [
            'voci.*.tipo_accessorio.in' => 'Gli interessi di mora si registrano dalla proposta degli interessi, nell\'elenco delle rate scadute: con il calcolo riga per riga.',
            'voci.required' => 'Scegli almeno una persona da addebitare.',
            'voci.*.causale.required' => 'Scrivi la causale: è la riga che la persona legge nell\'estratto conto e nella ricevuta.',
            'voci.*.scadenza.required' => 'Scrivi la scadenza dell\'addebito.',
            'data.date_format' => 'Scrivi la data dell\'addebito.',
        ]);
        // La delibera della base: vale per le voci che si fondano su «una delibera dell'assemblea», con la sua frase come riferimento.
        $delibera = DeliberaNelleSchede::dallaRichiesta($request, $condominio);
        if ($delibera !== null) {
            $frase = DeliberaInParole::frase($delibera, scritta: true);
            foreach ($dati['voci'] as $i => $v) {
                if (($v['base'] ?? null) === 'delibera') {
                    $dati['voci'][$i]['riferimento'] = $frase;
                }
            }
        }
        $gestione = Gestione::whereKey((int) $dati['gestione_id'])->where('condominio_id', $condominio->id)->first();
        $esercizio = Esercizio::whereKey((int) $dati['esercizio_id'])->where('condominio_id', $condominio->id)->first();
        abort_if($gestione === null || $esercizio === null, 404);

        $voci = array_map(function (array $v) {
            $v['importo'] = is_scalar($v['importo']) ? MoneyHelper::toCents((string) $v['importo']) : 0;
            // Il calcolo degli interessi lo scrive solo la loro proposta, sul server.
            unset($v['interessi']);

            return $v;
        }, $dati['voci']);

        $rate = DB::transaction(function () use ($condominio, $gestione, $esercizio, $voci, $request, $dati, $delibera) {
            $rate = $this->addebiti->registra($condominio, $gestione, $esercizio, $voci, $request->user()?->id, $dati['data'] ?? null);
            if ($delibera !== null) {
                foreach ($rate as $rata) {
                    foreach ($rata->rateQuote as $quota) {
                        if ((($quota->regole_calcolo['addebito'] ?? [])['base'] ?? null) === 'delibera') {
                            DeliberaNelleSchede::collega($quota, 'base', $delibera, $request->user());
                        }
                    }
                }
            }

            return $rate;
        });
        $totale = array_sum(array_map(fn (Rata $r) => (int) $r->importo_totale, $rate));

        return response()->json([
            'rate' => array_map(fn (Rata $r) => (int) $r->id, $rate),
            'messaggio' => (count($rate) === 1 ? 'Addebito registrato' : count($rate) . ' addebiti registrati') . ': ' . MoneyHelper::format($totale)
                . '. È una scadenza della persona, come una rata: è nel suo estratto conto e si incassa dalla pagina degli incassi.',
        ], 201);
    }

    public function annulla(Condominio $condominio, string|int $addebito): JsonResponse
    {
        $rata = $this->assicuraAddebito($condominio, $addebito);
        $this->addebiti->annulla($rata);

        return response()->json(['messaggio' => 'Addebito annullato: la sua scrittura è tolta dal giornale e la scadenza non c\'è più.']);
    }

    /** L'addebito dell'indirizzo, se è una rata di un piano degli addebiti di questo condominio; altrimenti 404. */
    private function assicuraAddebito(Condominio $condominio, string|int $id): Rata
    {
        abort_unless(is_numeric($id), 404);
        $rata = Rata::with('pianoRate')->find((int) $id);
        abort_unless($rata !== null && (int) $rata->pianoRate?->condominio_id === (int) $condominio->id && $rata->pianoRate->eDegliAddebiti(), 404);

        return $rata;
    }

    /** Le quote non assegnate scelte del piano, di un'unità sola: l'importo che resta, la causale, la voce; le persone; dove si scrive. */
    private function dalPiano(Condominio $condominio, Request $request, string $oggi): array
    {
        $piano = PianoRate::whereKey((int) $request->query('piano_rate_id'))->where('condominio_id', $condominio->id)->delRiparto()->with('gestione')->first();
        abort_if($piano === null, 404);
        $chiavi = array_values(array_filter((array) $request->query('chiavi', []), 'is_string'));
        $voci = collect($this->addebiti->quoteDaAddebitare($piano))->filter(fn (array $v) => in_array($v['chiave'] ?? null, $chiavi, true))->values();
        abort_if($voci->isEmpty(), 404);
        if ($voci->contains(fn (array $v) => ! $v['addebitabile'])) {
            throw ValidationException::withMessages(['chiavi' => AddebitiIndividuali::FRASE_SENZA_UNITA]);
        }
        $unita = $voci->pluck('immobile_id')->unique()->values();
        if ($unita->count() !== 1) {
            throw ValidationException::withMessages(['chiavi' => 'Le quote scelte sono di unità diverse: addebitale un\'unità alla volta.']);
        }
        $immobile = Immobile::findOrFail((int) $unita->first());
        $esercizio = $piano->esercizio_id ? Esercizio::find($piano->esercizio_id) : null;
        $dove = $this->dove($condominio, $piano->gestione, $esercizio);
        // Decisione 11: «straordinaria» è la natura della gestione, non il tipo del piano; decisione 322: la delibera è quella che ha approvato l'opera,
        // con la regola in un posto solo (`DeliberaDellOpera`), e con più delibere diverse non si propone nessuno.
        $straordinaria = \App\Enums\NaturaGestione::daStringa($piano->gestione?->tipo) === \App\Enums\NaturaGestione::Straordinaria;
        $opera = $straordinaria ? app(DeliberaDellOpera::class)->perEsercizio($piano->gestione, $esercizio?->id) : ['delibera' => null, 'motivo' => null];

        return [
            'unita' => ['id' => (int) $immobile->id, 'nome' => (string) $immobile->etichetta],
            // Con più delibere diverse (`nessuna_proposta`) i titolari ci sono lo stesso, senza la regola della delibera: la finestra non ne sceglie
            // nessuno e lo dice, e la scelta è dell'amministratore.
            'persone' => $this->persone->proposte((int) $immobile->id, $esercizio?->data_inizio?->format('Y-m-d'),
                $esercizio?->data_fine?->format('Y-m-d'), $opera['delibera'], $oggi),
            'nessuna_proposta' => $opera['motivo'],
            'voci' => $voci->map(fn (array $v) => [
                'chiave' => $v['chiave'], 'piano_origine_id' => (int) $piano->id, 'conto_id' => $v['conto_id'] ?? null,
                'importo_cents' => (int) $v['importo_cents'], 'addebitato_cents' => (int) $v['addebitato_cents'], 'resta_cents' => (int) $v['resta_cents'],
                'causale' => self::causaleDellaQuota($v, (string) $immobile->etichetta),
                'spesa_fatturata' => ($v['motivo'] ?? null) === 'ad_personam_senza_titolare',
            ])->all(),
            'dove' => $dove,
            'scadenza' => $oggi,
            'oggi' => $oggi,
        ];
    }

    /** La persona dell'estratto conto: le sue unità (dove è o è stata titolare), le spese di una sola unità già fatturate e non chieste, dove si scrive. */
    private function dallaPersona(Condominio $condominio, Request $request, string $oggi): array
    {
        $persona = Anagrafica::whereKey((int) $request->query('anagrafica_id'))->whereHas('condomini', fn ($q) => $q->where('condomini.id', $condominio->id))->first();
        abort_if($persona === null, 404);
        $unitaIds = DB::table('anagrafica_immobile as ai')->join('immobili as i', 'i.id', '=', 'ai.immobile_id')->where('i.condominio_id', $condominio->id)
            ->where('ai.anagrafica_id', $persona->id)->whereIn('ai.tipologia', ['proprietario', 'nuda_proprietario', 'usufruttuario'])->where('ai.attivo', true)
            ->distinct()->pluck('ai.immobile_id')->map(fn ($id) => (int) $id)->all();
        $unita = Immobile::whereIn('id', $unitaIds ?: [0])->orderBy('id')->get()->map(fn (Immobile $i) => ['id' => (int) $i->id, 'nome' => (string) $i->etichetta])->values()->all();

        return [
            'persona' => ['id' => (int) $persona->id, 'nome' => (string) $persona->nome],
            'unita' => $unita,
            'spese' => $this->speseDaChiedere($condominio, $unitaIds),
            // Per una spesa per il ritardo fatturata: che cos'è e su che cosa si fonda, con gli avvisi di ogni coppia.
            'tipi' => collect(\App\Services\Gestionale\Accessori\BaseDegliAccessori::TIPI)->except('interessi')->map(fn ($nome, $k) => ['valore' => $k, 'nome' => $nome])->values()->all(),
            'basi' => collect(\App\Services\Gestionale\Accessori\BaseDegliAccessori::BASI)->map(fn ($nome, $k) => ['valore' => $k, 'nome' => $nome])->values()->all(),
            'avvisi' => InteressiController::avvisiPerCoppia(),
            'dove' => $this->dove($condominio, null, null),
            'scadenza' => $oggi,
            'oggi' => $oggi,
        ];
    }

    /** Le spese non attribuite di una gestione nell'esercizio del conguaglio: quanto coprono gli addebiti, quanto resta; le persone dell'unità scelta. */
    private function dalConguaglio(Condominio $condominio, Request $request, string $oggi): array
    {
        $gestione = Gestione::whereKey((int) $request->query('gestione_id'))->where('condominio_id', $condominio->id)->first();
        $esercizio = Esercizio::whereKey((int) $request->query('esercizio_id'))->where('condominio_id', $condominio->id)->first();
        abort_if($gestione === null || $esercizio === null, 404);
        $coperti = $this->addebiti->copertiNellEsercizio((int) $condominio->id, (int) $gestione->id, (int) $esercizio->id);
        $unitaCoperte = array_values(array_unique(array_filter(array_column($coperti, 'immobile_id'))));
        $immobileId = $request->filled('immobile_id') ? (int) $request->query('immobile_id') : (count($unitaCoperte) === 1 ? $unitaCoperte[0] : null);
        if ($immobileId !== null) {
            abort_unless(Immobile::whereKey($immobileId)->where('condominio_id', $condominio->id)->exists(), 404);
        }
        $straordinaria = \App\Enums\NaturaGestione::daStringa($gestione->tipo) === \App\Enums\NaturaGestione::Straordinaria;
        // Decisione 322: la delibera che ha approvato l'opera (il primo piano attivo: la delibera più vecchia, a parità il numero più basso), non quella del
        // piano con l'id più basso; con più delibere diverse nell'esercizio del primo piano non si propone nessuno, e la finestra lo dice.
        $opera = $straordinaria ? app(DeliberaDellOpera::class)->perEsercizio($gestione, (int) $esercizio->id) : ['delibera' => null, 'motivo' => null];

        return [
            'unita' => Immobile::where('condominio_id', $condominio->id)->orderBy('id')->get()->map(fn (Immobile $i) => ['id' => (int) $i->id, 'nome' => (string) $i->etichetta])->values()->all(),
            'immobile_id' => $immobileId,
            // Con più delibere diverse (`nessuna_proposta`) i titolari ci sono lo stesso, senza la regola della delibera: la finestra non ne sceglie
            // nessuno e lo dice, e la scelta è dell'amministratore.
            'persone' => $immobileId === null ? [] : $this->persone->proposte($immobileId, $esercizio->data_inizio?->format('Y-m-d'),
                $esercizio->data_fine?->format('Y-m-d'), $opera['delibera'], $oggi),
            'nessuna_proposta' => $opera['motivo'],
            'causale' => 'Spese dei giorni senza titolare, ' . $gestione->nome,
            'origine' => ['gestione_id' => (int) $gestione->id, 'esercizio_id' => (int) $esercizio->id],
            'dove' => $this->dove($condominio, $gestione, $esercizio),
            'scadenza' => $oggi,
            'oggi' => $oggi,
        ];
    }

    /**
     * Dove si scrive l'addebito: la gestione e l'esercizio di origine, se l'esercizio è aperto; con l'esercizio chiuso, l'esercizio aperto di oggi,
     * con la stessa gestione se vi è legata, o con la gestione dello stesso tipo. Accanto, le altre gestioni degli esercizi aperti, per chi vuole
     * scegliere (dall'estratto conto non c'è un'origine: la prima è l'ordinaria dell'esercizio aperto di oggi). Ogni scelta porta l'inizio e la fine
     * dell'esercizio: la data dell'addebito sta dentro, di serie oggi o la fine se oggi è dopo. Una spesa già fatturata ha il suo posto, che la
     * finestra non lascia cambiare (`spese[].dove`).
     *
     * @return array{proposta: ?array{gestione_id: int, esercizio_id: int, nome: string, inizio: string, fine: string}, scelte: list<array{gestione_id: int, esercizio_id: int, nome: string, inizio: string, fine: string}>}
     */
    private function dove(Condominio $condominio, ?Gestione $origine, ?Esercizio $esercizio): array
    {
        $oggi = DateHelper::oggiUtente();
        $scelte = DB::table('esercizio_gestione as eg')->join('esercizi as e', 'e.id', '=', 'eg.esercizio_id')->join('gestioni as g', 'g.id', '=', 'eg.gestione_id')
            ->where('e.condominio_id', $condominio->id)->where('e.stato', 'aperto')
            ->orderByDesc('e.data_inizio')->orderBy('g.id')
            ->get(['g.id as gestione_id', 'e.id as esercizio_id', 'g.nome as gestione', 'g.tipo', 'e.nome as esercizio', 'e.data_inizio', 'e.data_fine'])
            ->map(fn ($r) => ['gestione_id' => (int) $r->gestione_id, 'esercizio_id' => (int) $r->esercizio_id, 'nome' => $r->gestione . ' (' . $r->esercizio . ')',
                'tipo' => (string) $r->tipo, 'oggi' => substr((string) $r->data_inizio, 0, 10) <= $oggi && substr((string) $r->data_fine, 0, 10) >= $oggi,
                'inizio' => substr((string) $r->data_inizio, 0, 10), 'fine' => substr((string) $r->data_fine, 0, 10)])
            ->values();
        $proposta = null;
        if ($origine !== null && $esercizio !== null && ($esercizio->stato ?? 'aperto') === 'aperto') {
            $proposta = $scelte->first(fn ($s) => $s['gestione_id'] === (int) $origine->id && $s['esercizio_id'] === (int) $esercizio->id);
        }
        $diOggi = $scelte->filter(fn ($s) => $s['oggi'])->values();
        $proposta ??= $origine === null ? null : ($diOggi->first(fn ($s) => $s['gestione_id'] === (int) $origine->id) ?? $diOggi->first(fn ($s) => $s['tipo'] === $origine->tipo));
        $proposta ??= $diOggi->first(fn ($s) => $s['tipo'] === 'ordinaria') ?? $scelte->first();
        $togli = fn ($s) => $s === null ? null : ['gestione_id' => $s['gestione_id'], 'esercizio_id' => $s['esercizio_id'], 'nome' => $s['nome'],
            'inizio' => $s['inizio'], 'fine' => $s['fine']];

        return ['proposta' => $togli($proposta), 'scelte' => $scelte->map($togli)->values()->all()];
    }

    /**
     * Le spese di una sola unità già fatturate (la riga di fattura con l'unità) e non ancora chieste del tutto, sulle unità date: la riga, la fattura,
     * quanto resta da chiedere, dove si scrive (la gestione e l'esercizio della fattura). Quelle di un esercizio chiuso non ci sono: il suo conguaglio
     * le chiede già.
     *
     * @param list<int> $unitaIds
     * @return list<array<string, mixed>>
     */
    private function speseDaChiedere(Condominio $condominio, array $unitaIds): array
    {
        if ($unitaIds === []) {
            return [];
        }
        $righe = DB::table('righe_fattura as rf')->join('fatture_passive as fp', 'fp.id', '=', 'rf.fattura_passiva_id')
            ->where('fp.condominio_id', $condominio->id)->whereIn('rf.immobile_id', $unitaIds)
            ->whereExists(fn ($q) => $q->from('fattura_scrittura as fs')->join('scritture_contabili as sc', 'sc.id', '=', 'fs.scrittura_contabile_id')
                ->whereColumn('fs.fattura_passiva_id', 'fp.id')->where('fs.tipo', 'competenza')->whereNull('sc.deleted_at'))
            ->orderBy('fp.data_documento')->orderBy('rf.id')
            ->get(['rf.id', 'rf.immobile_id', 'rf.descrizione', 'rf.importo_imponibile', 'rf.importo_iva', 'fp.numero_documento', 'fp.data_documento']);
        $chiesto = RigaRiparto::where('tipo', RigaRiparto::TIPO_AD_PERSONAM)->whereIn('riga_fattura_id', $righe->pluck('id')->all() ?: [0])
            ->groupBy('riga_fattura_id')->selectRaw('riga_fattura_id, SUM(importo) as importo')->pluck('importo', 'riga_fattura_id');
        $nomi = Immobile::whereIn('id', $unitaIds)->get()->mapWithKeys(fn (Immobile $i) => [(int) $i->id => (string) $i->etichetta]);

        return $righe->map(function ($r) use ($chiesto, $nomi, $condominio) {
            $resta = (int) $r->importo_imponibile + (int) $r->importo_iva - (int) ($chiesto[$r->id] ?? 0);
            $f = AddebitiIndividuali::esercizioDellaRiga((int) $condominio->id, (int) $r->id);

            return ['riga_fattura_id' => (int) $r->id, 'immobile_id' => (int) $r->immobile_id, 'unita' => $nomi[(int) $r->immobile_id] ?? null,
                'descrizione' => (string) ($r->descrizione ?? ''), 'fattura' => 'n. ' . $r->numero_documento . ' del ' . CarbonImmutable::parse($r->data_documento)->format('d/m/Y'),
                'resta_cents' => $resta, 'aperto' => $f === null || $f['aperto'],
                'dove' => $f === null || $f['gestione_id'] === null ? null : ['gestione_id' => $f['gestione_id'], 'esercizio_id' => $f['esercizio_id'], 'nome' => $f['gestione'] . ' (' . $f['esercizio'] . ')']];
        })->filter(fn (array $r) => $r['resta_cents'] > 0 && $r['aperto'])->map(fn (array $r) => array_diff_key($r, ['aperto' => true]))->values()->all();
    }

    /** La causale proposta per una quota non assegnata: «Quota non assegnata: Pulizie, U3, 31 giorni senza titolare». */
    private static function causaleDellaQuota(array $v, string $unita): string
    {
        if (($v['motivo'] ?? null) === 'ad_personam_senza_titolare') {
            return 'Spesa di una sola unità: ' . trim((string) ($v['riga_descrizione'] ?? 'senza descrizione')) . ', ' . $unita;
        }
        $giorni = (int) ($v['giorni'] ?? 0);

        return 'Quota non assegnata: ' . ($v['conto_nome'] ?? 'voce') . ', ' . $unita . ($giorni > 0 ? ', ' . ($giorni === 1 ? '1 giorno' : $giorni . ' giorni') . ' senza titolare' : '');
    }
}
