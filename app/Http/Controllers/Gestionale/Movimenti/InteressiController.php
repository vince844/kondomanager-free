<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Helpers\DateHelper;
use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\Rata;
use App\Services\Gestionale\Accessori\BaseDegliAccessori;
use App\Services\Gestionale\Accessori\PropostaInteressi;
use App\Services\Gestionale\Addebiti\PersoneDellAddebito;
use App\Services\Gestionale\Debiti\ElencoRateScadute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Gli interessi di mora e le spese per il ritardo, dall'elenco «Rate scadute».
 *
 * - **La proposta degli interessi** a una data (di serie oggi, mai dopo) per le persone scelte: una lista, o «tutte le N dei filtri», che il server
 *   rilegge dall'elenco. Riga per riga, con i tratti; niente si scrive.
 * - **La registrazione** delle righe tenute: il server rifà la proposta e scrive solo se le cifre sono quelle viste.
 * - **La proposta delle spese in blocco**: per ogni persona l'unità su cui si scrive (quella con più scaduto, fra le sue di proprietario), le gestioni
 *   degli esercizi aperti di oggi con le loro voci, i tipi, le basi e gli avvisi di ogni coppia tipo e base. La registrazione è quella degli addebiti.
 *
 * Le rotte stanno nel gruppo del gestionale, dietro il pannello. Una persona che non è di questo condominio fa 404 per tutta la richiesta.
 */
class InteressiController extends Controller
{
    public function __construct(private readonly PropostaInteressi $interessi, private readonly ElencoRateScadute $elenco, private readonly PersoneDellAddebito $persone) {}

    public function proposta(Request $request, Condominio $condominio): JsonResponse
    {
        $alla = $this->alla($request);

        return response()->json($this->interessi->proponi($condominio, $alla, $this->personeDellaRichiesta($request, $condominio)));
    }

    public function store(Request $request, Condominio $condominio): JsonResponse
    {
        $dati = $request->validate([
            'alla' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . DateHelper::oggiUtente()],
            'righe' => ['required', 'array', 'min:1', 'max:20000'],
            'righe.*' => ['string', 'regex:/^\d+\|\d+\|\d+$/'],
            'atteso' => ['required', 'integer', 'min:1'],
            'voci' => ['required', 'array'],
            'voci.*' => ['integer'],
        ], [
            'righe.required' => 'Tieni almeno una riga della proposta.',
            'alla.before_or_equal' => 'Gli interessi si propongono fino a oggi, non dopo.',
            'voci.required' => 'Scegli la voce di entrata che riceve gli interessi quando sono versati.',
        ]);
        $persone = array_values(array_unique(array_map(fn (string $k) => (int) explode('|', $k)[0], $dati['righe'])));
        $this->assicuraPersone($condominio, $persone);

        $rate = $this->interessi->registra($condominio, $dati['alla'], array_values($dati['righe']), (int) $dati['atteso'],
            array_map('intval', $dati['voci']), $request->user()?->id);
        $totale = array_sum(array_map(fn (Rata $r) => (int) $r->importo_totale, $rate));

        return response()->json([
            'rate' => array_map(fn (Rata $r) => (int) $r->id, $rate),
            'messaggio' => (count($rate) === 1 ? 'Interessi registrati: 1 addebito' : 'Interessi registrati: ' . count($rate) . ' addebiti') . ', ' . MoneyHelper::format($totale)
                . '. Ognuno è una scadenza della persona, nel suo estratto conto; diventano del condominio quando sono versati.',
        ], 201);
    }

    /** Le spese per il ritardo in blocco: per ogni persona scelta l'unità proposta, e dove si scrivono, con le voci. */
    public function spese(Request $request, Condominio $condominio): JsonResponse
    {
        $ids = $this->personeDellaRichiesta($request, $condominio);
        $righe = $this->elenco->righe($condominio, $this->elenco->filtriNormalizzati([]), $ids);
        $nomi = Anagrafica::whereIn('id', $ids ?: [0])->pluck('nome', 'id');
        $persone = [];
        foreach ($ids as $id) {
            $riga = $righe->firstWhere('anagrafica_id', $id);
            $perUnita = [];
            foreach ($riga['rate'] ?? [] as $r) {
                if ($r['immobile_id'] !== null && $this->persone->puoRicevere($id, (int) $r['immobile_id'])) {
                    $perUnita[(int) $r['immobile_id']] = ($perUnita[(int) $r['immobile_id']] ?? ['nome' => $r['unita'], 'importo' => 0]);
                    $perUnita[(int) $r['immobile_id']]['importo'] += (int) $r['residuo'];
                }
            }
            uksort($perUnita, fn ($a, $b) => [-$perUnita[$a]['importo'], $a] <=> [-$perUnita[$b]['importo'], $b]);
            $unita = array_key_first($perUnita);
            // Senza rate scadute (dall'estratto conto): la prima unità di cui è titolare di un diritto reale oggi.
            if ($unita === null) {
                $titolare = DB::table('anagrafica_immobile as ai')->join('immobili as i', 'i.id', '=', 'ai.immobile_id')->where('i.condominio_id', $condominio->id)
                    ->where('ai.anagrafica_id', $id)->whereIn('ai.tipologia', ['proprietario', 'nuda_proprietario', 'usufruttuario'])->where('ai.attivo', true)
                    ->orderBy('i.id')->value('i.id');
                if ($titolare !== null) {
                    $unita = (int) $titolare;
                    $perUnita[$unita] = ['nome' => (string) \App\Models\Immobile::find($unita)?->etichetta, 'importo' => 0];
                }
            }
            $persone[] = ['anagrafica_id' => $id, 'nome' => (string) ($nomi[$id] ?? ''), 'immobile_id' => $unita, 'unita' => $unita === null ? null : $perUnita[$unita]['nome'],
                'perche' => $unita === null ? 'Non è proprietario, nudo proprietario o usufruttuario di un\'unità di questo condominio: la spesa non va a lui.' : null];
        }

        return response()->json([
            'persone' => $persone,
            'dove' => $this->doveDiOggi($condominio),
            'tipi' => collect(BaseDegliAccessori::TIPI)->except('interessi')->map(fn ($nome, $k) => ['valore' => $k, 'nome' => $nome])->values()->all(),
            'basi' => collect(BaseDegliAccessori::BASI)->map(fn ($nome, $k) => ['valore' => $k, 'nome' => $nome])->values()->all(),
            'avvisi' => self::avvisiPerCoppia(),
            'oggi' => DateHelper::oggiUtente(),
        ]);
    }

    /** Gli avvisi di ogni coppia tipo e base: una fonte sola, il server; la finestra li legge mentre l'amministratore sceglie. */
    public static function avvisiPerCoppia(): array
    {
        $esito = [];
        foreach (array_keys(BaseDegliAccessori::TIPI) as $tipo) {
            foreach (array_keys(BaseDegliAccessori::BASI) as $base) {
                $esito[$tipo][$base] = BaseDegliAccessori::avvisi($tipo, $base);
            }
        }

        return $esito;
    }

    /**
     * Le gestioni degli esercizi aperti di oggi, con le voci che possono ricevere il denaro di una spesa (le voci senza sottovoci, con il mastro), e
     * la proposta: l'ordinaria.
     *
     * @return array{proposta: ?int, scelte: list<array{gestione_id: int, esercizio_id: int, nome: string, voci: list<array{id: int, nome: string, entrata: bool}>}>}
     */
    private function doveDiOggi(Condominio $condominio): array
    {
        $oggi = DateHelper::oggiUtente();
        $scelte = DB::table('esercizio_gestione as eg')->join('esercizi as e', 'e.id', '=', 'eg.esercizio_id')->join('gestioni as g', 'g.id', '=', 'eg.gestione_id')
            ->where('e.condominio_id', $condominio->id)->where('e.stato', 'aperto')->where('e.data_inizio', '<=', $oggi . ' 23:59:59')->where('e.data_fine', '>=', $oggi)
            ->orderBy('g.id')->get(['g.id as gestione_id', 'e.id as esercizio_id', 'g.nome as gestione', 'g.tipo', 'e.nome as esercizio'])
            ->map(fn ($r) => ['gestione_id' => (int) $r->gestione_id, 'esercizio_id' => (int) $r->esercizio_id, 'nome' => $r->gestione . ' (' . $r->esercizio . ')', 'tipo' => (string) $r->tipo,
                'voci' => Conto::query()->join('piani_conti as pc', 'pc.id', '=', 'conti.piano_conto_id')->where('pc.gestione_id', $r->gestione_id)->where('pc.condominio_id', $condominio->id)
                    ->whereNotNull('conti.conto_contabile_id')->whereDoesntHave('sottoconti')->whereHas('tabelleMillesimali')->orderBy('conti.nome')->get(['conti.id', 'conti.nome', 'conti.tipo'])
                    ->map(fn ($c) => ['id' => (int) $c->id, 'nome' => (string) $c->nome, 'entrata' => Conto::segnoDelTipo((string) $c->tipo) !== 1])->values()->all()])
            ->values();
        $proposta = $scelte->firstWhere('tipo', 'ordinaria') ?? $scelte->first();

        return ['proposta' => $proposta['gestione_id'] ?? null, 'scelte' => $scelte->map(fn ($s) => array_diff_key($s, ['tipo' => true]))->all()];
    }

    /** La data della proposta: quella chiesta, fino a oggi; di serie oggi. */
    private function alla(Request $request): string
    {
        $oggi = DateHelper::oggiUtente();
        $alla = $request->query('alla');

        return is_string($alla) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $alla) && checkdate((int) substr($alla, 5, 2), (int) substr($alla, 8, 2), (int) substr($alla, 0, 4))
            ? min($alla, $oggi) : $oggi;
    }

    /**
     * Le persone della richiesta: la lista `persone`, o «tutte le N dei filtri» (`tutte` con i filtri dell'elenco), che il server rilegge.
     *
     * @return list<int>
     */
    private function personeDellaRichiesta(Request $request, Condominio $condominio): array
    {
        if ($request->boolean('tutte')) {
            $filtri = $this->elenco->filtriNormalizzati((array) $request->input('filtri', []));

            return $this->elenco->righe($condominio, $filtri, null, false)->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->values()->all();
        }
        $persone = array_values(array_unique(array_map('intval', array_filter((array) $request->input('persone', []), 'is_numeric'))));
        abort_if($persone === [], 404);
        $this->assicuraPersone($condominio, $persone);

        return $persone;
    }

    /** Le persone sono di questo condominio, tutte; altrimenti 404 per tutta la richiesta. */
    private function assicuraPersone(Condominio $condominio, array $persone): void
    {
        $delCondominio = Anagrafica::whereIn('id', $persone ?: [0])->whereHas('condomini', fn ($q) => $q->where('condomini.id', $condominio->id))->count();
        abort_unless($persone !== [] && $delCondominio === count($persone), 404);
    }
}
