<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Helpers\DateHelper;
use App\Helpers\MoneyHelper;
use App\Http\Controllers\Assemblee\DeliberaNelleSchede;
use App\Http\Controllers\Controller;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Gestionale\AccordoRientro;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\PDF\PdfService;
use App\Services\Gestionale\Debiti\AccordiRientro;
use App\Services\Gestionale\Debiti\CalendarioAccordo;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use App\Support\LimiteCaricamento;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * L'accordo di rientro di una persona, dalla riga dell'elenco «Rate scadute» e dal suo estratto conto (dalla 1.12.0-beta.13 l'autorizzazione dell'assemblea
 * viene dalla delibera scelta con «Scegli la delibera», `delibera_id` del condominio della rotta, ruolo `autorizzazione`: la data e il verbale della riunione
 * nelle colonne di oggi, che le letture continuano a usare): gli accordi che ha e le rate che un accordo
 * può coprire a una data; la registrazione con il calendario e la regola di decadenza; l'autorizzazione dell'assemblea arrivata dopo; la scelta sulla
 * parte della rata fuori dall'accordo, cambiata dopo; la chiusura;
 * la decadenza registrata dall'amministratore; la scansione firmata. Un accordo alla volta: è un atto con una persona. Non si cancella: si chiude.
 *
 * Il calendario: le rate dell'accordo, con il giorno (in ordine, non prima dell'accordo) e l'importo; la somma è il debito riconosciuto, e un
 * calendario che non torna non si registra. «Fino al» è il giorno dell'ultima rata. Un accordo senza calendario si registra ancora con «fino al»
 * (la forma di prima, che la finestra non offre più): non ha segnali.
 *
 * Le rotte stanno nel gruppo del gestionale, dietro il pannello. La persona, le rate e l'accordo della richiesta devono essere di questo
 * condominio: altrimenti 404 per tutta la richiesta. Gli importi riconosciuti e quelli del calendario arrivano in euro, come li scrive chi lavora,
 * e diventano centesimi qui, una volta.
 */
class AccordiRientroController extends Controller
{
    public const SENZA_AUTORIZZAZIONE = 'Di\' se l\'accordo è autorizzato dall\'assemblea: sì o no. Se l\'assemblea non ha ancora deliberato è «no», con una nota; '
        . 'diventa «sì» quando registri la delibera.';
    public const RATA_NON_COPRIBILE = 'Una delle rate scelte non era scaduta, o non aveva un residuo, il giorno dell\'accordo: toglila, o cambia la data dell\'accordo.';
    public const GIA_IN_UN_ACCORDO = 'Una di queste rate è già in un altro accordo di rientro in quei giorni: un accordo nuovo sulla stessa rata parte dal giorno '
        . 'in cui l\'altro è chiuso, o dopo. Se l\'altro è ancora in corso, chiudilo prima.';
    public const OLTRE_IL_RESIDUO = 'L\'importo riconosciuto di una rata non supera il suo residuo il giorno dell\'accordo. Gli interessi e le spese entrano nell\'accordo '
        . 'come rate loro, dopo che li hai registrati: «Proponi gli interessi» e «Spese del ritardo» in «Rate scadute».';
    public const REGOLA_SENZA_CALENDARIO = 'La regola di decadenza conta le rate del calendario: scrivi il calendario.';
    public const CALENDARIO_IN_ORDINE = 'Le rate del calendario vanno in ordine di giorno, una per giorno, dal giorno dell\'accordo in poi; ogni rata più di zero.';

    public function __construct(private readonly LetturaDebiti $lettura, private readonly AccordiRientro $accordi) {}

    /** Gli accordi della persona, con le cifre di oggi, e le rate che un accordo può coprire alla data scelta (di serie oggi). */
    public function index(Request $request, Condominio $condominio): JsonResponse
    {
        $persona = $this->persona($condominio, $request->query('anagrafica_id'));
        $oggi = DateHelper::oggiUtente();
        $data = is_string($request->query('data')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query('data')) ? min($request->query('data'), $oggi) : $oggi;

        $possibili = AccordiRientro::coperturePossibili($this->lettura->perPersona((int) $condominio->id, (int) $persona->id, $data),
            AccordiRientro::pianiDegliAddebiti((int) $condominio->id));
        $tutti = AccordoRientro::with(['rate', 'scadenze', 'documenti:id,documentable_id,documentable_type,name'])->where('condominio_id', $condominio->id)
            ->where('anagrafica_id', $persona->id)->orderByDesc('data_accordo')->orderByDesc('id')->get();
        $segni = collect($this->accordi->segni($tutti, $this->lettura->perPersona((int) $condominio->id, (int) $persona->id)['rate'], $oggi)['accordi'])->keyBy('id');
        $nomi = $this->nomi(array_merge($possibili, $tutti->flatMap(fn (AccordoRientro $a) => $a->rate->map(fn ($r) => ['rata_id' => $r->rata_id, 'immobile_id' => $r->immobile_id]))->all()));

        return response()->json([
            'persona'  => ['id' => (int) $persona->id, 'nome' => (string) $persona->nome],
            // Il nome del condominio, per le frasi di «Scegli la delibera» nella finestra.
            'condominio' => (string) $condominio->nome,
            'data'     => $data,
            'oggi'     => $oggi,
            // La regola di decadenza dell'ultimo accordo del condominio: la finestra la propone, l'amministratore la cambia.
            'regola_di_serie' => AccordiRientro::regolaDiSerie((int) $condominio->id),
            'rate'     => array_map(fn ($r) => $r + $nomi($r), $possibili),
            'accordi'  => $tutti->map(fn (AccordoRientro $a) => $segni[$a->id] + [
                'in_corso'  => $a->inCorsoIl($oggi),
                'documento' => $a->documenti->first()?->name,
                'delibera' => DeliberaNelleSchede::perLaPagina($a, 'autorizzazione'),
                'rate'      => $a->rate->map(fn ($r) => ['rata_id' => (int) $r->rata_id, 'immobile_id' => $r->immobile_id, 'riconosciuto' => (int) $r->importo_riconosciuto]
                    + $nomi(['rata_id' => $r->rata_id, 'immobile_id' => $r->immobile_id]))->values()->all(),
            ])->values()->all(),
        ]);
    }

    public function store(Request $request, Condominio $condominio): JsonResponse
    {
        $oggi = DateHelper::oggiUtente();
        // L'autorizzazione dalla delibera scelta: il giorno della riunione e il suo verbale entrano nelle colonne di oggi (R1).
        $delibera = DeliberaNelleSchede::dallaRichiesta($request, $condominio);
        if ($delibera !== null) {
            $request->merge(['autorizzato_assemblea' => '1', 'data_autorizzazione' => DeliberaNelleSchede::giorno($delibera),
                'verbale_autorizzazione' => DeliberaNelleSchede::numeroDelVerbale($delibera)]);
        }
        $dati = $request->validate([
            'anagrafica_id' => ['required', 'integer'],
            'data_accordo' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $oggi],
            // Con il calendario «fino al» è il giorno della sua ultima rata; senza, si scrive.
            'fino_al' => ['required_without:scadenze', 'nullable', 'date_format:Y-m-d', 'after_or_equal:data_accordo'],
            'scadenze' => ['nullable', 'array', 'min:1', 'max:120'],
            'scadenze.*.data' => ['required', 'date_format:Y-m-d'],
            'scadenze.*.importo' => ['required'],
            'decadenza_rate' => ['nullable', 'integer', 'min:1'],
            'decadenza_giorni' => ['nullable', 'integer', 'min:0', 'max:365'],
            // Decisione 450: sulla parte della rata fuori dall'accordo gli interessi continuano (di serie) o restano sospesi finché l'accordo regge.
            'interessi_parte_fuori' => ['nullable', Rule::in([AccordoRientro::INTERESSI_CONTINUANO, AccordoRientro::INTERESSI_SOSPESI])],
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
            'fino_al.required_without' => 'Scrivi il calendario dell\'accordo: le rate, con il giorno e l\'importo.',
            'scadenze.min' => 'Scrivi il calendario dell\'accordo: almeno una rata.',
            'scadenze.*.data.required' => self::CALENDARIO_IN_ORDINE,
            'scadenze.*.data.date_format' => self::CALENDARIO_IN_ORDINE,
            'decadenza_rate.min' => 'La regola di decadenza conta almeno una rata non versata.',
            'decadenza_giorni.max' => 'I giorni della regola di decadenza sono al più 365.',
            'interessi_parte_fuori.in' => 'Sulla parte della rata fuori dall\'accordo scegli se gli interessi continuano o restano sospesi finché l\'accordo regge.',
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
        $possibili = collect(AccordiRientro::coperturePossibili($this->lettura->perPersona((int) $condominio->id, (int) $persona->id, $dati['data_accordo']),
            AccordiRientro::pianiDegliAddebiti((int) $condominio->id)))
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

        // Il calendario: in ordine di giorno, dal giorno dell'accordo; la somma è il debito riconosciuto (le rate coperte).
        $calendario = $this->calendarioDellaRichiesta($dati, (int) array_sum(array_column($coperte, 'importo_riconosciuto')));
        // La regola di decadenza conta le rate del calendario: senza calendario non si registra (e non si butta in silenzio).
        if ($calendario === null && (($dati['decadenza_rate'] ?? null) !== null || ($dati['decadenza_giorni'] ?? null) !== null)) {
            throw ValidationException::withMessages(['decadenza_rate' => self::REGOLA_SENZA_CALENDARIO]);
        }
        if ($calendario !== null && ($dati['decadenza_rate'] ?? null) !== null && (int) $dati['decadenza_rate'] > count($calendario)) {
            throw ValidationException::withMessages(['decadenza_rate' => 'La regola di decadenza conta al più le ' . count($calendario) . ' rate del calendario.']);
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
            $accordo = DB::transaction(function () use ($condominio, $persona, $dati, $autorizzato, $coperte, $calendario, $request, $delibera, &$percorso) {
                $accordo = AccordoRientro::create([
                    'condominio_id' => $condominio->id,
                    'anagrafica_id' => $persona->id,
                    'data_accordo' => $dati['data_accordo'],
                    'fino_al' => $calendario !== null ? $calendario[count($calendario) - 1]['data'] : $dati['fino_al'],
                    'decadenza_rate' => $calendario !== null && ($dati['decadenza_rate'] ?? null) !== null ? (int) $dati['decadenza_rate'] : null,
                    'decadenza_giorni' => $calendario !== null && ($dati['decadenza_giorni'] ?? null) !== null ? (int) $dati['decadenza_giorni'] : null,
                    'autorizzato_assemblea' => $autorizzato,
                    'data_autorizzazione' => $autorizzato ? $dati['data_autorizzazione'] : null,
                    'verbale_autorizzazione' => $autorizzato ? ($dati['verbale_autorizzazione'] ?? null) : null,
                    'nota' => $dati['nota'] ?? null,
                    'interessi_parte_fuori' => $dati['interessi_parte_fuori'] ?? AccordoRientro::INTERESSI_CONTINUANO,
                    'registrato_da' => $request->user()?->id,
                ]);
                $accordo->rate()->createMany(array_values($coperte));
                DeliberaNelleSchede::collega($accordo, 'autorizzazione', $delibera, $request->user());
                if ($calendario !== null) {
                    $accordo->scadenze()->createMany($calendario);
                }
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
                        'created_by' => $request->user()?->id,
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
            . 'e le lettere proposte lasciano fuori la parte riconosciuta fino al ' . $accordo->fino_al->format('d/m/Y') . '.'], 201);
    }

    /** L'autorizzazione dell'assemblea arrivata dopo: un «no» diventa «sì», con la data e il verbale della delibera. */
    public function autorizza(Request $request, Condominio $condominio, string|int $accordo): JsonResponse
    {
        $a = $this->assicuraAccordo($condominio, $accordo);
        $delibera = DeliberaNelleSchede::dallaRichiesta($request, $condominio);
        if ($delibera !== null) {
            $request->merge(['data_autorizzazione' => DeliberaNelleSchede::giorno($delibera), 'verbale_autorizzazione' => DeliberaNelleSchede::numeroDelVerbale($delibera)]);
        }
        $dati = $request->validate([
            'data_autorizzazione' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . DateHelper::oggiUtente()],
            'verbale_autorizzazione' => ['nullable', 'string', 'max:50'],
        ], [
            'data_autorizzazione.required' => 'Scrivi la data della delibera che autorizza l\'accordo.',
            'data_autorizzazione.before_or_equal' => 'La data della delibera non può essere dopo oggi.',
        ]);
        // Il servizio che chiama anche il seguito delle delibere: l'accordo già autorizzato non si tocca, il collegamento nella stessa transazione.
        \App\Services\Gestionale\AutorizzazioneDellAccordo::autorizza($a, $dati['data_autorizzazione'], $dati['verbale_autorizzazione'] ?? null, $delibera,
            $request->user());

        return response()->json(['messaggio' => 'Autorizzazione dell\'assemblea registrata.']);
    }

    /**
     * La scelta sulla parte della rata fuori dall'accordo (decisione 450), cambiata dalla scheda dopo la registrazione: gli accordi registrati prima della
     * scelta hanno il di serie senza che l'amministratore l'abbia vista, e una scelta sbagliata nella finestra si corregge senza chiudere e rifare l'accordo.
     * Tocca solo la proposta degli interessi: niente si scrive a giornale. Il registro dell'accordo tiene chi l'ha cambiata, quando, da che cosa a che cosa.
     * Un accordo chiuso non si cambia.
     */
    public function parteFuori(Request $request, Condominio $condominio, string|int $accordo): JsonResponse
    {
        $a = $this->assicuraAccordo($condominio, $accordo);
        $dati = $request->validate([
            'interessi_parte_fuori' => ['required', Rule::in([AccordoRientro::INTERESSI_CONTINUANO, AccordoRientro::INTERESSI_SOSPESI])],
        ], [
            'interessi_parte_fuori.required' => 'Sulla parte della rata fuori dall\'accordo scegli se gli interessi continuano o restano sospesi finché l\'accordo regge.',
            'interessi_parte_fuori.in' => 'Sulla parte della rata fuori dall\'accordo scegli se gli interessi continuano o restano sospesi finché l\'accordo regge.',
        ]);
        if ($a->chiuso_il !== null) {
            throw ValidationException::withMessages(['interessi_parte_fuori' => 'Questo accordo è chiuso: la scelta resta quella che aveva.']);
        }
        $prima = (string) ($a->interessi_parte_fuori ?? AccordoRientro::INTERESSI_CONTINUANO);
        if ($prima === $dati['interessi_parte_fuori']) {
            throw ValidationException::withMessages(['interessi_parte_fuori' => 'La scelta è già questa.']);
        }
        $registro = is_array($a->registro) ? $a->registro : [];
        $registro[] = ['cosa' => 'interessi_parte_fuori', 'da' => $prima, 'a' => $dati['interessi_parte_fuori'], 'il' => now()->toIso8601String(),
            'utente_id' => $request->user()?->id, 'utente' => (string) ($request->user()?->name ?? '')];
        $a->update(['interessi_parte_fuori' => $dati['interessi_parte_fuori'], 'registro' => $registro]);

        return response()->json(['messaggio' => 'Scelta cambiata: sulla parte della rata fuori dall\'accordo '
            . ($dati['interessi_parte_fuori'] === AccordoRientro::INTERESSI_SOSPESI ? 'gli interessi restano sospesi finché l\'accordo regge' : 'gli interessi continuano')
            . '. La proposta degli interessi la segue da adesso; gli interessi già registrati restano come sono.']);
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

    /**
     * La decadenza, registrata dall'amministratore: la data (non prima dell'accordo, non dopo oggi) e una nota. Chiude l'accordo quel giorno: da
     * quel giorno le rate tornano nelle lettere proposte. Il programma la segnala quando la regola si verifica e non la registra mai da sé.
     */
    public function decadenza(Request $request, Condominio $condominio, string|int $accordo): JsonResponse
    {
        $a = $this->assicuraAccordo($condominio, $accordo);
        $dati = $request->validate([
            'data' => ['required', 'date_format:Y-m-d', 'after_or_equal:' . $a->data_accordo->format('Y-m-d'), 'before_or_equal:' . DateHelper::oggiUtente()],
            'nota' => ['nullable', 'string', 'max:2000'],
        ], [
            'data.required' => 'Scrivi la data della decadenza.',
            'data.after_or_equal' => 'La decadenza non può essere prima della data dell\'accordo.',
            'data.before_or_equal' => 'La data della decadenza non può essere dopo oggi.',
        ]);
        if ($a->chiuso_il !== null) {
            throw ValidationException::withMessages(['data' => 'Questo accordo è già chiuso.']);
        }
        $a->update(['chiuso_il' => $dati['data'], 'decadenza_registrata_il' => $dati['data'], 'nota_chiusura' => $dati['nota'] ?? null, 'chiuso_da' => $request->user()?->id]);
        $da = $dati['data'] === DateHelper::oggiUtente() ? 'da oggi' : 'dal ' . Carbon::parse($dati['data'])->format('d/m/Y');

        return response()->json(['messaggio' => 'Decadenza registrata: ' . $da . ' le rate dell\'accordo tornano nelle lettere proposte.']);
    }

    /**
     * L'accordo con il calendario, stampato per la persona: il debito riconosciuto rata per rata, il calendario, la regola di decadenza,
     * l'autorizzazione, la nota e lo spazio delle firme. Solo per un accordo con il calendario. Un GET: non scrive niente.
     */
    public function stampa(Condominio $condominio, string|int $accordo)
    {
        $a = $this->assicuraAccordo($condominio, $accordo);
        abort_if($a->scadenze->isEmpty(), 404);
        $persona = Anagrafica::findOrFail($a->anagrafica_id);
        $nomeFile = PdfService::nomeFile('accordo-di-rientro', $condominio->nome, $a->data_accordo->format('Y'), \Illuminate\Support\Str::slug((string) $persona->nome));

        return response($this->accordi->stampa($condominio, $a))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="' . $nomeFile . '"');
    }

    /** La scansione dell'accordo firmato. */
    public function documento(Condominio $condominio, string|int $accordo)
    {
        $a = $this->assicuraAccordo($condominio, $accordo);
        $documento = $a->documenti()->first();
        abort_if($documento === null || ! app(ArchivioDocumenti::class)->esiste($documento->path), 404);

        return app(ArchivioDocumenti::class)->scarica($documento->path, $documento->nomeDiScaricamento());
    }

    /**
     * Il calendario della richiesta, in centesimi, o null senza calendario. Le rate in ordine di giorno (una per giorno), non prima dell'accordo,
     * ognuna più di zero; la somma deve essere il debito riconosciuto, altrimenti la frase con le due cifre.
     *
     * @return list<array{numero:int, data:string, importo:int}>|null
     */
    private function calendarioDellaRichiesta(array $dati, int $riconosciuto): ?array
    {
        if (($dati['scadenze'] ?? null) === null) {
            return null;
        }
        $calendario = [];
        $prima = null;
        foreach (array_values($dati['scadenze']) as $i => $r) {
            $importo = is_scalar($r['importo']) ? MoneyHelper::toCents((string) $r['importo']) : 0;
            if ($importo < 1 || $r['data'] < $dati['data_accordo'] || ($prima !== null && $r['data'] <= $prima)) {
                throw ValidationException::withMessages(['scadenze' => self::CALENDARIO_IN_ORDINE]);
            }
            $prima = $r['data'];
            $calendario[] = ['numero' => $i + 1, 'data' => $r['data'], 'importo' => $importo];
        }
        $frase = CalendarioAccordo::sommaCheNonTorna(array_column($calendario, 'importo'), $riconosciuto);
        if ($frase !== null) {
            throw ValidationException::withMessages(['scadenze' => $frase]);
        }

        return $calendario;
    }

    /** L'accordo dell'indirizzo, se è di questo condominio; altrimenti 404. */
    private function assicuraAccordo(Condominio $condominio, string|int $id): AccordoRientro
    {
        abort_unless(is_numeric($id), 404);
        $a = AccordoRientro::with(['rate', 'scadenze'])->find((int) $id);
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
     * I nomi che la finestra mostra accanto a una rata: l'unità, il piano, il numero e la scadenza; per un addebito la causale.
     *
     * @param list<array{rata_id: mixed, immobile_id: mixed}> $rate
     * @return callable(array): array{unita: ?string, piano: string, numero_rata: int, scadenza: ?string, causale: ?string}
     */
    private function nomi(array $rate): callable
    {
        return AccordiRientro::nomiDelleRate($rate);
    }
}
