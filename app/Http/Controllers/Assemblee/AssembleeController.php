<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Enums\Assemblee\OrigineAssemblea;
use App\Enums\Assemblee\StatoAssemblea;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assemblee\PreparaAssembleaRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\MemoDellAssemblea;
use App\Models\Condominio;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\Convocazione\ElencoDeiConvocati;
use App\Services\Assemblee\Convocazione\FascicoloDellAssemblea;
use App\Services\Assemblee\Convocazione\StatoDellaConvocazione;
use App\Services\Assemblee\Convocazione\TerminiDellaConvocazione;
use App\Services\Assemblee\DeliberaAMano;
use App\Services\Assemblee\DiarioDellAssemblea;
use App\Services\Assemblee\MemoriaDelCondominio;
use App\Services\Assemblee\PassiDellAssemblea;
use App\Services\Assemblee\PreparazioneDellAssemblea;
use App\Services\Assemblee\ProposteDellOrdineDelGiorno;
use App\Services\Assemblee\DelibereDelCondominio;
use App\Services\Assemblee\SchedaDelCondominio;
use App\Services\Assemblee\TabellaDiProprieta;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * L'archivio delle assemblee del condominio (§3 P1), «Prepara l'assemblea» (P2), la pagina dell'assemblea con la strada dei cinque passi e la riunione
 * registrata a mano (P2, P3, P6). Chi vede: «Vede le assemblee»; chi scrive: «Gestisce le assemblee» (`AccessoAlleAssemblee`, mai i ruoli del portale).
 *
 * I filtri dell'archivio e i gesti sull'ordine del giorno tornano con visite parziali; le proposte sono differite (`Inertia::defer`); la convocazione,
 * il fascicolo e il «Diario» si chiedono all'apertura del loro passo (`Inertia::optional`).
 */
class AssembleeController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio;

    /** Le assemblee per pagina dell'archivio. */
    public const PER_PAGINA = 20;

    public function index(Request $request, Condominio $condominio): Response
    {
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));
        $filtri = [
            'anno' => preg_match('/^\d{4}$/', (string) $request->query('anno')) === 1 ? (string) $request->query('anno') : null,
            'stato' => in_array($request->query('stato'), ['in_preparazione', 'convocata', 'in_corso', 'chiusa', 'deserta', 'tenuta', 'a_mano', 'annullata'], true)
                ? (string) $request->query('stato') : null,
        ];

        return Inertia::render('gestionale/assemblee/Archivio', [
            'condominio' => new CondominioResource($condominio),
            'condomini' => $this->getCondomini(),
            'esercizio' => $this->getEsercizioCorrente($condominio),
            'filtri' => $filtri,
            'assemblee' => Inertia::scroll(fn () => $this->archivio($condominio, $filtri)),
            'anni' => fn () => $this->anni($condominio),
            'card' => fn () => $this->card($condominio),
            'raccolta' => fn () => DelibereDelCondominio::raccolta($condominio),
            'puo' => ['gestire' => AccessoAlleAssemblee::gestisce($request->user()), 'registrare_il_verbale' => AccessoAlleAssemblee::registraIlVerbale($request->user())],
        ]);
    }

    public function create(Request $request, Condominio $condominio): Response
    {
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $memo = $request->filled('memo') ? SchedaDelCondominio::cerca($condominio, 'memo_assemblea', (int) $request->query('memo')) : null;

        return Inertia::render('gestionale/assemblee/Prepara', [
            'condominio' => new CondominioResource($condominio),
            'condomini' => $this->getCondomini(),
            'esercizio' => $this->getEsercizioCorrente($condominio),
            'proposte' => ProposteDellOrdineDelGiorno::per($condominio, null),
            'scelte' => PerLePagine::scelte($condominio),
            'tabella_di_proprieta' => $this->tabellaDiProprieta($condominio),
            'passi' => PerLePagine::passiDellaPreparazione(),
            // I giorni da cui la prima convocazione ha i cinque giorni (art. 66 c. 3): la nota sotto le date, mentre si scrivono.
            'termini_della_prima' => app(TerminiDellaConvocazione::class)->primiGiorniDaOggi(),
            'memo' => $memo instanceof MemoDellAssemblea && $memo->eInAttesa() ? ['id' => (int) $memo->id, 'testo' => $memo->testo] : null,
        ]);
    }

    public function store(PreparaAssembleaRequest $request, Condominio $condominio): RedirectResponse
    {
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $dati = array_intersect_key($request->validated(), array_flip(['tipo', 'ambito', 'palazzina_id', 'scala_id', 'luogo', 'modalita', 'piattaforma',
            'consenso_videoconferenza_il', 'consenso_videoconferenza_modo', 'tabella_id']));
        if (! $request->has('tabella_id')) {
            unset($dati['tabella_id']);
        }
        $dati['sedute'] = $request->sedute();
        $punti = $this->propostePrese($condominio, null, (array) $request->validated('proposte', []), (array) $request->validated('maggioranze', []));
        // I punti scritti in questa pagina: per la strada di «Aggiungi un punto», con la scala, la palazzina e la tabella del condominio (R18).
        $scritti = array_map(fn (array $p) => PuntiController::nelCondominio($condominio, $p), $request->puntiScritti());
        $segnata = null;
        $assemblea = DB::transaction(function () use ($request, $condominio, $dati, $punti, $scritti, &$segnata) {
            $assemblea = PreparazioneDellAssemblea::crea($condominio, $dati, $punti, $request->user(), $scritti, $request->ordine());
            // «Segna come tabella di proprietà generale» (R14): la scelta dell'amministratore, fatta qui. Solo per il condominio intero, e mai sopra una
            // tabella già segnata: quella si cambia nella pagina della tabella o nella pagina dell'assemblea.
            if ($request->boolean('segna_di_proprieta') && $assemblea->tabella_id !== null && ($dati['ambito'] ?? null) === AmbitoAssemblea::CONDOMINIO->value
                && TabellaDiProprieta::di($condominio) === null) {
                /** @var \App\Models\Tabella $tabella */
                $tabella = SchedaDelCondominio::risolvi($condominio, 'tabella', (int) $assemblea->tabella_id);
                TabellaDiProprieta::scegli($condominio, $tabella);
                $segnata = $tabella->nome;
            }

            return $assemblea;
        });

        // Appena preparata, con il passo 1 fatto (le date valgono e c'è almeno un punto) la pagina apre «Documenti» (decisione 661); altrimenti resta su «Date e
        // ordine del giorno», che dice ciò che manca (decisione 591). «Documenti» resta chiuso finché il passo 1 non è fatto.
        $fatto = PassiDellAssemblea::primoPassoFatto($assemblea);
        $tabella = $segnata === null ? '' : ' «' . $segnata . '» è la tabella di proprietà generale di ' . $condominio->nome . '.';

        return to_route('admin.gestionale.assemblee.show', ['condominio' => $condominio->id, 'assemblea' => $assemblea->id, 'scheda' => $fatto ? 'documenti' : 'date'])
            ->with($this->flashSuccess('Assemblea preparata: ' . $assemblea->nome() . '. '
                . ($fatto ? 'Adesso i documenti.' : 'Adesso è in preparazione; la convocazione parte quando la spedisci.') . $tabella));
    }

    /**
     * «Avanti: Convocazione» dal passo «Documenti»: il giorno in cui i documenti sono stati guardati, scritto una volta sola; il passo diventa fatto e la
     * pagina apre la convocazione.
     */
    public function documentiVisti(Request $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        if ($assemblea->documenti_visti_il === null) {
            $assemblea->forceFill(['documenti_visti_il' => now()])->save();
        }

        return to_route('admin.gestionale.assemblee.show', ['condominio' => $condominio->id, 'assemblea' => $assemblea->id, 'scheda' => 'convocazione']);
    }

    public function show(Request $request, Condominio $condominio, Assemblea $assemblea): Response
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));
        $utente = $request->user();
        // Il passo aperto: `scheda` con i valori di sempre («fascicolo» è il passo «Documenti»); un punto o un memo aprono le date; senza, la pagina apre il
        // passo di adesso.
        $scheda = match ($request->query('scheda')) {
            'date', 'documenti', 'convocazione', 'sala', 'verbale', 'diario', 'delibere' => (string) $request->query('scheda'),
            'fascicolo' => 'documenti',
            default => $request->filled('punto') || $request->filled('memo') ? 'date' : null,
        };
        $memo = $request->filled('memo') ? SchedaDelCondominio::cerca($condominio, 'memo_assemblea', (int) $request->query('memo')) : null;
        // Il seguito delle delibere si legge una volta per richiesta: lo usano la testa del passo 6 nella strada (`passi`) e il passo aperto (`seguito`).
        $letto = null;
        $seguito = function () use (&$letto, $assemblea, $utente): array {
            return $letto ??= \App\Services\Assemblee\Seguito\SeguitoDelleDelibere::perLaPagina($assemblea, $utente);
        };

        return Inertia::render('gestionale/assemblee/Assemblea', [
            'condominio' => new CondominioResource($condominio),
            'condomini' => $this->getCondomini(),
            'esercizio' => $this->getEsercizioCorrente($condominio),
            'assemblea' => fn () => PerLePagine::assemblea($assemblea->refresh()),
            'ordine' => fn () => PerLePagine::ordine($assemblea),
            'passi' => fn () => PerLePagine::passi($assemblea, $seguito),
            'scheda' => $scheda,
            'termini_della_prima' => fn () => $assemblea->eInPreparazione() ? app(TerminiDellaConvocazione::class)->primiGiorniDaOggi() : null,
            'punto' => $request->filled('punto') ? (int) $request->query('punto') : null,
            // Il passo 6 aperto su una delibera o su una cosa da fare (l'azione del compito, della card, del riepilogo): la pagina la mostra in evidenza.
            'delibera' => ctype_digit((string) $request->query('delibera', '')) ? (int) $request->query('delibera') : null,
            'cosa' => ctype_digit((string) $request->query('cosa', '')) ? (int) $request->query('cosa') : null,
            'memo' => $memo instanceof MemoDellAssemblea && $memo->eInAttesa() ? ['id' => (int) $memo->id, 'testo' => $memo->testo] : null,
            'scelte' => fn () => PerLePagine::scelte($condominio),
            'riunioni_a_mano' => fn () => $assemblea->stato === StatoAssemblea::REGISTRATA_A_MANO ? $this->riunioniAMano($condominio, $assemblea) : [],
            'proposte' => Inertia::defer(fn () => $assemblea->eInPreparazione() ? ProposteDellOrdineDelGiorno::per($condominio, $assemblea) : [], 'proposte'),
            // I passi lenti si chiedono quando si aprono (`router.reload({ only: [...] })`): non partono con la pagina.
            'convocazione' => Inertia::optional(fn () => app(StatoDellaConvocazione::class)->perLaPagina($assemblea)),
            'fascicolo' => Inertia::optional(fn () => app(FascicoloDellAssemblea::class)->perLaPagina($assemblea)),
            // Il passo «In sala»: le deleghe ricevute con il quinto, la fotografia provvisoria, le sedute; la stessa lettura della pagina della sala.
            'sala' => Inertia::optional(fn () => app(\App\Services\Assemblee\Sala\LetturaDellaSala::class)->perLaPagina($assemblea, $utente)),
            // Il passo «Verbale e trasmissione»: le riunioni con il loro verbale e le rotte, quando il passo si apre.
            'verbale' => Inertia::optional(fn () => app(\App\Services\Assemblee\Verbale\LetturaDelVerbale::class)->perIlPasso($assemblea)),
            // Il passo 6 «Delibere eseguite» (il seguito delle delibere): il riepilogo «Che cosa cambia nel gestionale» finché non è confermato, poi la
            // tabella delle delibere con «Da eseguire» e «Trenta giorni»; per la riunione su carta, nella sua pagina.
            'seguito' => Inertia::optional($seguito),
            // L'esito per riga dell'ultima conferma, o di «Applica».
            'seguito_esiti' => fn () => $request->session()->get('seguito_esiti'),
            'diario' => Inertia::optional(fn () => DiarioDellAssemblea::righe($assemblea,
                (string) $request->query('diario_filtro', 'tutto'), $request->filled('diario_dopo') ? (string) $request->query('diario_dopo') : null)),
            'puo' => [
                'gestire' => AccessoAlleAssemblee::gestisce($utente),
                'registrare_il_verbale' => AccessoAlleAssemblee::registraIlVerbale($utente),
            ],
        ]);
    }

    public function update(PreparaAssembleaRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $dati = array_intersect_key($request->validated(), array_flip(['tipo', 'ambito', 'palazzina_id', 'scala_id', 'luogo', 'modalita', 'piattaforma',
            'consenso_videoconferenza_il', 'consenso_videoconferenza_modo', 'tabella_id', 'affissione_dal', 'affissione_al']));
        if ($request->has('sedute')) {
            $dati['sedute'] = $request->sedute();
        }
        PreparazioneDellAssemblea::aggiorna($assemblea, $dati, $request->user());

        // Con le lettere già stampate e l'avviso cambiato, il messaggio lo dice: «Hai stampato le lettere il …: ristampale».
        return back()->with($this->flashSuccess(app(ElencoDeiConvocati::class)->fraseDopoIlSalvataggio($assemblea->refresh(), 'Salvato.')));
    }

    /** Le date dell'avviso, una per una (la riga di ogni seduta cambiata nel registro). */
    public function sedute(PreparaAssembleaRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        PreparazioneDellAssemblea::aggiorna($assemblea, ['sedute' => $request->sedute()], $request->user());

        return back()->with($this->flashSuccess(app(ElencoDeiConvocati::class)->fraseDopoIlSalvataggio($assemblea->refresh(), 'Date salvate.')));
    }

    /**
     * Elimina un'assemblea in preparazione (i memo tornano nella memoria) o una riunione registrata a mano senza delibere e senza il verbale sottoscritto.
     * Da convocata si annulla con il motivo.
     */
    public function destroy(Request $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $nome = $assemblea->nome();
        if ($assemblea->eAMano()) {
            DeliberaAMano::eliminaRiunione($assemblea, $request->user());
        } else {
            PreparazioneDellAssemblea::elimina($assemblea, $request->user());
        }

        return to_route('admin.gestionale.assemblee.index', ['condominio' => $condominio->id])
            ->with($this->flashSuccess($nome . ' eliminata. La riga resta nel registro delle modifiche del condominio.'));
    }

    /**
     * Le proposte spuntate, rilette dal server: si prendono solo quelle che il programma propone oggi, nell'ordine delle proposte, con la maggioranza scelta
     * dall'amministratore (il menu della scena 4).
     *
     * @param list<string> $chiavi
     * @param array<string, ?string> $maggioranze
     * @return list<array<string, mixed>>
     */
    public static function propostePrese(Condominio $condominio, ?Assemblea $assemblea, array $chiavi, array $maggioranze): array
    {
        $scelte = array_flip(array_map('strval', $chiavi));
        $punti = [];
        foreach (ProposteDellOrdineDelGiorno::per($condominio, $assemblea) as $gruppo) {
            foreach ($gruppo['punti'] as $p) {
                if (! isset($scelte[$p['chiave_fonte']])) {
                    continue;
                }
                if (array_key_exists($p['chiave_fonte'], $maggioranze)) {
                    $p['maggioranza'] = $maggioranze[$p['chiave_fonte']];
                }
                $punti[] = $p;
            }
        }
        // «Varie ed eventuali» in fondo.
        usort($punti, fn ($a, $b) => ($a['chiave_fonte'] === ProposteDellOrdineDelGiorno::CHIAVE_VARIE) <=> ($b['chiave_fonte'] === ProposteDellOrdineDelGiorno::CHIAVE_VARIE));

        return $punti;
    }

    /** @param array{anno: ?string, stato: ?string} $filtri */
    private function archivio(Condominio $condominio, array $filtri)
    {
        $query = Assemblea::query()->where('condominio_id', $condominio->id)->withCount(['punti', 'delibere'])->with(['sedute', 'palazzina', 'scala'])
            ->when($filtri['anno'] !== null, fn ($q) => $q->whereYear('data', (int) $filtri['anno']))
            ->when($filtri['stato'] === 'in_preparazione', fn ($q) => $q->where('stato', StatoAssemblea::IN_PREPARAZIONE->value))
            ->when($filtri['stato'] === 'convocata', fn ($q) => $q->where('stato', StatoAssemblea::CONVOCATA->value))
            ->when($filtri['stato'] === 'annullata', fn ($q) => $q->where('stato', StatoAssemblea::ANNULLATA->value))
            // Gli stati della sala: in corso, tenuta in sala e chiusa, deserta in prima e in seconda.
            ->when(in_array($filtri['stato'], ['in_corso', 'chiusa', 'deserta'], true), fn ($q) => $q->where('stato', (string) $filtri['stato']))
            ->when($filtri['stato'] === 'tenuta', fn ($q) => $q->where('stato', StatoAssemblea::REGISTRATA_A_MANO->value)->where('origine', OrigineAssemblea::PROGRAMMA->value))
            ->when($filtri['stato'] === 'a_mano', fn ($q) => $q->where('origine', OrigineAssemblea::A_MANO->value))
            ->orderByRaw('data IS NULL DESC')->orderByDesc('data')->orderByDesc('id');
        $pagina = $query->paginate(self::PER_PAGINA)->withQueryString();

        return $pagina->setCollection($pagina->getCollection()->map(fn (Assemblea $a) => [
            'id' => (int) $a->id,
            'nome' => $a->nome(),
            'anno' => $a->data?->format('Y'),
            'data' => $a->data?->format('Y-m-d'),
            'stato' => $a->stato?->value,
            'stato_parola' => PerLePagine::statoParola($a),
            'a_mano' => $a->eAMano(),
            'perimetro' => PerLePagine::perimetroDi($a->ambito?->value, $a->palazzina, $a->scala),
            'sottotitolo' => PerLePagine::sottotitolo($a),
            'punti' => (int) $a->punti_count,
            'delibere' => (int) $a->delibere_count,
            'numero_verbale' => $a->numero_verbale,
            'verbale_da_caricare' => $a->stato === StatoAssemblea::REGISTRATA_A_MANO && $a->verbale_documento_id === null,
        ]));
    }

    /** @return list<string> */
    private function anni(Condominio $condominio): array
    {
        return Assemblea::query()->where('condominio_id', $condominio->id)->whereNotNull('data')->pluck('data')
            ->map(fn ($d) => $d->format('Y'))->unique()->sortDesc()->values()->all();
    }

    /** Le tre card: la prossima, i punti che aspettano, le delibere dell'anno. @return array<string, array<string, ?string>> */
    private function card(Condominio $condominio): array
    {
        $prossima = Assemblea::query()->where('condominio_id', $condominio->id)
            ->whereIn('stato', [StatoAssemblea::IN_PREPARAZIONE->value, StatoAssemblea::CONVOCATA->value])->orderByRaw('data IS NULL')->orderBy('data')->first();
        $inAttesa = MemoriaDelCondominio::perLaDashboard($condominio)['totale'];
        $anno = now()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('Y');
        $delibere = DB::table('delibere')->where('condominio_id', $condominio->id)->whereYear('deliberata_il', (int) $anno)->count();

        return [
            'prossima' => $prossima === null
                ? ['valore' => 'Nessuna', 'sotto' => 'nessuna assemblea in preparazione', 'id' => null]
                : ['valore' => $prossima->nome(), 'sotto' => mb_strtolower(PerLePagine::statoParola($prossima)), 'id' => (int) $prossima->id],
            'in_attesa' => ['valore' => $inAttesa === 1 ? '1 punto' : $inAttesa . ' punti', 'sotto' => 'per la prossima assemblea'],
            'delibere' => ['valore' => $delibere === 1 ? '1 delibera' : $delibere . ' delibere', 'sotto' => 'nel ' . $anno],
        ];
    }

    /** La tabella di proprietà generale del condominio, per la proposta della tabella del valore. @return array{id: int, nome: string}|null */
    private function tabellaDiProprieta(Condominio $condominio): ?array
    {
        $t = \App\Services\Assemblee\TabellaDiProprieta::di($condominio);

        return $t === null ? null : ['id' => (int) $t->id, 'nome' => $t->nome];
    }

    /** Le altre riunioni a mano del condominio, per «Sposta in un'altra riunione». @return list<array{id: int, nome: string}> */
    private function riunioniAMano(Condominio $condominio, Assemblea $tranne): array
    {
        return Assemblea::query()->where('condominio_id', $condominio->id)->where('origine', OrigineAssemblea::A_MANO->value)->whereKeyNot($tranne->id)
            ->orderByDesc('data')->get()->map(fn (Assemblea $a) => ['id' => (int) $a->id, 'nome' => $a->nome()
                . (trim((string) $a->numero_verbale) !== '' ? ' · verbale n. ' . trim((string) $a->numero_verbale) : '')])->values()->all();
    }
}
