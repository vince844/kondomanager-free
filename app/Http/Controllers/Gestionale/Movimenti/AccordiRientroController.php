<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Helpers\DateHelper;
use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Gestionale\AccordoRientro;
use App\Models\Immobile;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\Debiti\AccordiRientro;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use App\Support\LimiteCaricamento;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * L'accordo di rientro di una persona, dalla riga dell'elenco «Rate scadute»: gli accordi che ha e le rate che un accordo può coprire a una
 * data; la registrazione; l'autorizzazione dell'assemblea arrivata dopo; la chiusura; la scansione firmata. Un accordo alla volta: è un atto con
 * una persona. Non si cancella: si chiude.
 *
 * Le rotte stanno nel gruppo del gestionale, dietro il pannello. La persona, le rate e l'accordo della richiesta devono essere di questo
 * condominio: altrimenti 404 per tutta la richiesta. Gli importi riconosciuti arrivano in euro, come li scrive chi lavora, e diventano
 * centesimi qui, una volta.
 */
class AccordiRientroController extends Controller
{
    public const SENZA_AUTORIZZAZIONE = 'Di\' se l\'accordo è autorizzato dall\'assemblea: sì o no. Se l\'assemblea non ha ancora deliberato è «no», con una nota; '
        . 'diventa «sì» quando registri la delibera.';
    public const RATA_NON_COPRIBILE = 'Una delle rate scelte non era scaduta, o non aveva un residuo, il giorno dell\'accordo: toglila, o cambia la data dell\'accordo.';
    public const GIA_IN_UN_ACCORDO = 'Una di queste rate è già in un altro accordo di rientro in quei giorni: un accordo nuovo sulla stessa rata parte dal giorno '
        . 'in cui l\'altro è chiuso, o dopo. Se l\'altro è ancora in corso, chiudilo prima.';
    public const OLTRE_IL_RESIDUO = 'L\'importo riconosciuto di una rata non supera il suo residuo il giorno dell\'accordo: interessi e spese il programma non li registra ancora.';

    public function __construct(private readonly LetturaDebiti $lettura, private readonly AccordiRientro $accordi) {}

    /** Gli accordi della persona, con le cifre di oggi, e le rate che un accordo può coprire alla data scelta (di serie oggi). */
    public function index(Request $request, Condominio $condominio): JsonResponse
    {
        $persona = $this->persona($condominio, $request->query('anagrafica_id'));
        $oggi = DateHelper::oggiUtente();
        $data = is_string($request->query('data')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query('data')) ? min($request->query('data'), $oggi) : $oggi;

        $possibili = AccordiRientro::coperturePossibili($this->lettura->perPersona((int) $condominio->id, (int) $persona->id, $data));
        $tutti = AccordoRientro::with(['rate', 'documenti:id,documentable_id,documentable_type,name'])->where('condominio_id', $condominio->id)
            ->where('anagrafica_id', $persona->id)->orderByDesc('data_accordo')->orderByDesc('id')->get();
        $segni = collect($this->accordi->segni($tutti, $this->lettura->perPersona((int) $condominio->id, (int) $persona->id)['rate'], $oggi)['accordi'])->keyBy('id');
        $nomi = $this->nomi(array_merge($possibili, $tutti->flatMap(fn (AccordoRientro $a) => $a->rate->map(fn ($r) => ['rata_id' => $r->rata_id, 'immobile_id' => $r->immobile_id]))->all()));

        return response()->json([
            'persona'  => ['id' => (int) $persona->id, 'nome' => (string) $persona->nome],
            'data'     => $data,
            'oggi'     => $oggi,
            'rate'     => array_map(fn ($r) => $r + $nomi($r), $possibili),
            'accordi'  => $tutti->map(fn (AccordoRientro $a) => $segni[$a->id] + [
                'in_corso'  => $a->inCorsoIl($oggi),
                'documento' => $a->documenti->first()?->name,
                'rate'      => $a->rate->map(fn ($r) => ['rata_id' => (int) $r->rata_id, 'immobile_id' => $r->immobile_id, 'riconosciuto' => (int) $r->importo_riconosciuto]
                    + $nomi(['rata_id' => $r->rata_id, 'immobile_id' => $r->immobile_id]))->values()->all(),
            ])->values()->all(),
        ]);
    }

    public function store(Request $request, Condominio $condominio): JsonResponse
    {
        $oggi = DateHelper::oggiUtente();
        $dati = $request->validate([
            'anagrafica_id' => ['required', 'integer'],
            'data_accordo' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $oggi],
            'fino_al' => ['required', 'date_format:Y-m-d', 'after_or_equal:data_accordo'],
            'rate' => ['required', 'array', 'min:1'],
            'rate.*.rata_id' => ['required', 'integer'],
            'rate.*.immobile_id' => ['nullable', 'integer'],
            'rate.*.importo' => ['required'],
            // Sì o no, senza una risposta già scelta: senza la scelta il server rifiuta.
            'autorizzato_assemblea' => ['required', Rule::in(['0', '1', 0, 1, true, false])],
            'data_autorizzazione' => [Rule::requiredIf(fn () => filter_var($request->input('autorizzato_assemblea'), FILTER_VALIDATE_BOOLEAN)), 'nullable', 'date_format:Y-m-d', 'before_or_equal:' . $oggi],
            'verbale_autorizzazione' => ['nullable', 'string', 'max:50'],
            'nota' => ['nullable', 'string', 'max:2000'],
            'documento' => ['nullable', 'file', 'extensions:pdf,jpg,jpeg,png', 'max:' . LimiteCaricamento::regolaMax(10.0), \App\Support\FileComeIlNome::regola()],
        ], [
            'data_accordo.required' => 'Scrivi la data dell\'accordo.',
            'data_accordo.before_or_equal' => 'La data dell\'accordo non può essere dopo oggi.',
            'fino_al.required' => 'Scrivi «fino al»: la data dell\'ultima rata dell\'accordo.',
            'fino_al.after_or_equal' => '«Fino al» non può essere prima della data dell\'accordo.',
            'rate.required' => 'Scegli le rate che l\'accordo copre.',
            'autorizzato_assemblea.required' => self::SENZA_AUTORIZZAZIONE,
            'autorizzato_assemblea.in' => self::SENZA_AUTORIZZAZIONE,
            'data_autorizzazione.required' => 'Scrivi la data della delibera che autorizza l\'accordo.',
            'data_autorizzazione.before_or_equal' => 'La data della delibera non può essere dopo oggi.',
        ]);

        $persona = $this->persona($condominio, $dati['anagrafica_id']);
        $rataIds = array_values(array_unique(array_map(fn ($r) => (int) $r['rata_id'], $dati['rate'])));
        $delCondominio = DB::table('rate')->join('piani_rate', 'piani_rate.id', '=', 'rate.piano_rate_id')->whereIn('rate.id', $rataIds)
            ->where('piani_rate.condominio_id', $condominio->id)->count();
        abort_unless($delCondominio === count($rataIds), 404);

        // Le rate che l'accordo può coprire: le scadute della persona con un residuo il giorno dell'accordo, lette a quel giorno.
        $possibili = collect(AccordiRientro::coperturePossibili($this->lettura->perPersona((int) $condominio->id, (int) $persona->id, $dati['data_accordo'])))
            ->keyBy(fn ($r) => AccordiRientro::chiave($r['rata_id'], $r['immobile_id']));
        $coperte = [];
        foreach ($dati['rate'] as $r) {
            $k = AccordiRientro::chiave($r['rata_id'], $r['immobile_id'] ?? null);
            $possibile = $possibili->get($k);
            if ($possibile === null || isset($coperte[$k])) {
                throw ValidationException::withMessages(['rate' => self::RATA_NON_COPRIBILE]);
            }
            $importo = is_scalar($r['importo']) ? MoneyHelper::toCents((string) $r['importo']) : 0;
            if ($importo < 1) {
                throw ValidationException::withMessages(['rate' => 'Scrivi l\'importo riconosciuto di ogni rata, più di zero.']);
            }
            if ($importo > $possibile['residuo']) {
                throw ValidationException::withMessages(['rate' => self::OLTRE_IL_RESIDUO]);
            }
            $coperte[$k] = ['rata_id' => $possibile['rata_id'], 'immobile_id' => $possibile['immobile_id'], 'importo_riconosciuto' => $importo,
                'residuo_iniziale' => (int) $possibile['residuo']];
        }

        // Una rata in un accordo solo: due accordi in corso sulla stessa rata direbbero due cifre per lo stesso debito. Conta ogni accordo della
        // persona che è in corso in un giorno da quello del nuovo in poi: uno non chiuso, di qualunque data (anche dopo, o prima del nuovo), e
        // uno chiuso dopo il giorno del nuovo.
        $sovrapposti = AccordoRientro::with('rate')->where('condominio_id', $condominio->id)->where('anagrafica_id', $persona->id)
            ->where(fn ($q) => $q->whereNull('chiuso_il')->orWhereDate('chiuso_il', '>', $dati['data_accordo']))->get();
        $giaCoperte = $sovrapposti->flatMap(fn (AccordoRientro $a) => $a->rate->map(fn ($r) => AccordiRientro::chiave($r->rata_id, $r->immobile_id)))->all();
        if (array_intersect(array_keys($coperte), $giaCoperte) !== []) {
            throw ValidationException::withMessages(['rate' => self::GIA_IN_UN_ACCORDO]);
        }

        $autorizzato = filter_var($dati['autorizzato_assemblea'], FILTER_VALIDATE_BOOLEAN);
        $percorso = null;
        try {
            $accordo = DB::transaction(function () use ($condominio, $persona, $dati, $autorizzato, $coperte, $request, &$percorso) {
                $accordo = AccordoRientro::create([
                    'condominio_id' => $condominio->id,
                    'anagrafica_id' => $persona->id,
                    'data_accordo' => $dati['data_accordo'],
                    'fino_al' => $dati['fino_al'],
                    'autorizzato_assemblea' => $autorizzato,
                    'data_autorizzazione' => $autorizzato ? $dati['data_autorizzazione'] : null,
                    'verbale_autorizzazione' => $autorizzato ? ($dati['verbale_autorizzazione'] ?? null) : null,
                    'nota' => $dati['nota'] ?? null,
                    'registrato_da' => $request->user()?->id,
                ]);
                $accordo->rate()->createMany(array_values($coperte));
                if ($request->hasFile('documento')) {
                    $file = $request->file('documento');
                    $percorso = app(ArchivioDocumenti::class)->salvaConLEstensioneDelNome($file);
                    // Un documento dell'accordo: con il suo proprietario non entra nell'archivio né nel portale.
                    $accordo->documenti()->create([
                        'name' => $file->getClientOriginalName(),
                        'description' => 'Accordo di rientro di ' . $persona->nome . ' del ' . Carbon::parse($dati['data_accordo'])->format('d/m/Y'),
                        'path' => $percorso,
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                        'created_by' => $request->user()?->id ?? 1,
                        'is_published' => false,
                        'is_approved' => true,
                    ]);
                }

                return $accordo;
            });
        } catch (\Throwable $e) {
            app(ArchivioDocumenti::class)->elimina($percorso);

            throw $e;
        }

        return response()->json(['id' => $accordo->id, 'messaggio' => 'Accordo registrato: le rate coperte restano fra le scadute, con il segno dell\'accordo, '
            . 'e le lettere proposte lasciano fuori la parte riconosciuta fino al ' . Carbon::parse($dati['fino_al'])->format('d/m/Y') . '.'], 201);
    }

    /** L'autorizzazione dell'assemblea arrivata dopo: un «no» diventa «sì», con la data e il verbale della delibera. */
    public function autorizza(Request $request, Condominio $condominio, string|int $accordo): JsonResponse
    {
        $a = $this->assicuraAccordo($condominio, $accordo);
        $dati = $request->validate([
            'data_autorizzazione' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . DateHelper::oggiUtente()],
            'verbale_autorizzazione' => ['nullable', 'string', 'max:50'],
        ], [
            'data_autorizzazione.required' => 'Scrivi la data della delibera che autorizza l\'accordo.',
            'data_autorizzazione.before_or_equal' => 'La data della delibera non può essere dopo oggi.',
        ]);
        if ($a->autorizzato_assemblea) {
            throw ValidationException::withMessages(['data_autorizzazione' => 'Questo accordo è già autorizzato dall\'assemblea.']);
        }
        $a->update(['autorizzato_assemblea' => true, 'data_autorizzazione' => $dati['data_autorizzazione'], 'verbale_autorizzazione' => $dati['verbale_autorizzazione'] ?? null]);

        return response()->json(['messaggio' => 'Autorizzazione dell\'assemblea registrata.']);
    }

    /** La chiusura: una data (non prima dell'accordo, non dopo oggi) e una nota. L'accordo resta, chiuso. */
    public function chiudi(Request $request, Condominio $condominio, string|int $accordo): JsonResponse
    {
        $a = $this->assicuraAccordo($condominio, $accordo);
        $dati = $request->validate([
            'chiuso_il' => ['required', 'date_format:Y-m-d', 'after_or_equal:' . $a->data_accordo->format('Y-m-d'), 'before_or_equal:' . DateHelper::oggiUtente()],
            'nota_chiusura' => ['nullable', 'string', 'max:2000'],
        ], [
            'chiuso_il.required' => 'Scrivi la data della chiusura.',
            'chiuso_il.after_or_equal' => 'La chiusura non può essere prima della data dell\'accordo.',
            'chiuso_il.before_or_equal' => 'La data della chiusura non può essere dopo oggi.',
        ]);
        if ($a->chiuso_il !== null) {
            throw ValidationException::withMessages(['chiuso_il' => 'Questo accordo è già chiuso.']);
        }
        $a->update(['chiuso_il' => $dati['chiuso_il'], 'nota_chiusura' => $dati['nota_chiusura'] ?? null, 'chiuso_da' => $request->user()?->id]);

        // Chiuso il giorno D, l'accordo non è più in corso già quel giorno: le rate tornano nelle lettere da D.
        $da = $dati['chiuso_il'] === DateHelper::oggiUtente() ? 'da oggi' : 'dal ' . Carbon::parse($dati['chiuso_il'])->format('d/m/Y');

        return response()->json(['messaggio' => 'Accordo chiuso: ' . $da . ' le sue rate tornano nelle lettere proposte.']);
    }

    /** La scansione dell'accordo firmato. */
    public function documento(Condominio $condominio, string|int $accordo)
    {
        $a = $this->assicuraAccordo($condominio, $accordo);
        $documento = $a->documenti()->first();
        abort_if($documento === null || ! app(ArchivioDocumenti::class)->esiste($documento->path), 404);

        return app(ArchivioDocumenti::class)->scarica($documento->path, $documento->nomeDiScaricamento());
    }

    /** L'accordo dell'indirizzo, se è di questo condominio; altrimenti 404. */
    private function assicuraAccordo(Condominio $condominio, string|int $id): AccordoRientro
    {
        abort_unless(is_numeric($id), 404);
        $a = AccordoRientro::with('rate')->find((int) $id);
        abort_unless($a !== null && (int) $a->condominio_id === (int) $condominio->id, 404);

        return $a;
    }

    /** La persona della richiesta, se è di questo condominio; altrimenti 404. */
    private function persona(Condominio $condominio, mixed $id): Anagrafica
    {
        abort_unless(is_numeric($id), 404);
        $persona = Anagrafica::whereKey((int) $id)->whereHas('condomini', fn ($q) => $q->where('condomini.id', $condominio->id))->first();
        abort_if($persona === null, 404);

        return $persona;
    }

    /**
     * I nomi che la finestra mostra accanto a una rata: l'unità, il piano, il numero e la scadenza.
     *
     * @param list<array{rata_id: mixed, immobile_id: mixed}> $rate
     * @return callable(array): array{unita: ?string, piano: string, numero_rata: int, scadenza: ?string}
     */
    private function nomi(array $rate): callable
    {
        $rataIds = array_values(array_unique(array_map(fn ($r) => (int) $r['rata_id'], $rate)));
        $unitaIds = array_values(array_unique(array_filter(array_map(fn ($r) => $r['immobile_id'] === null ? null : (int) $r['immobile_id'], $rate))));
        $info = DB::table('rate')->join('piani_rate', 'piani_rate.id', '=', 'rate.piano_rate_id')->whereIn('rate.id', $rataIds ?: [0])
            ->get(['rate.id', 'rate.numero_rata', 'rate.data_scadenza', 'piani_rate.nome'])->keyBy('id');
        $unita = Immobile::whereIn('id', $unitaIds ?: [0])->get()->keyBy('id');

        return fn (array $r) => [
            'unita' => $r['immobile_id'] !== null && isset($unita[(int) $r['immobile_id']]) ? $unita[(int) $r['immobile_id']]->etichetta : null,
            'piano' => (string) ($info[(int) $r['rata_id']]->nome ?? ''),
            'numero_rata' => (int) ($info[(int) $r['rata_id']]->numero_rata ?? 0),
            'scadenza' => isset($info[(int) $r['rata_id']]) ? substr((string) $info[(int) $r['rata_id']]->data_scadenza, 0, 10) : null,
        ];
    }
}
