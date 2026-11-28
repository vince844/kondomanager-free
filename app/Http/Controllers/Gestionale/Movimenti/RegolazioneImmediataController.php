<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Actions\Gestionale\Movimenti\RegistraRegolazioneImmediataAction;
use App\Exceptions\Gestionale\RegolazioneImmediataNonAmmessaException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Movimenti\StoreRegolazioneImmediataRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Fornitore;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ScritturaContabile;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Registrazione a regolazione immediata (prima nota diretta costo → banca/cassa).
 *
 * Percorso PARALLELO alla registrazione fattura, non sostitutivo: serve ai fatti
 * amministrativi che nascono e si estinguono nello stesso momento — imposte di
 * bollo, commissioni bancarie, addebiti automatici, piccole spese — per i quali
 * aprire una partita fornitore significa inventare un fornitore fittizio "BANCA"
 * e sporcare il libro giornale con documenti che non esistono.
 *
 * Cfr. docs/registrazione_e_regolazione_immediata.md
 */
class RegolazioneImmediataController extends Controller
{
    use HandleFlashMessages;
    use HasCondomini;
    use HasEsercizio;

    /**
     * Form di registrazione. Espone solo ciò che la scrittura richiede davvero:
     * capitolo di spesa (DARE), cassa (AVERE), importo, data, causale — più il
     * fornitore come tag facoltativo.
     *
     * I capitoli senza `conto_contabile_id` non vengono esposti: sono quelli su
     * cui l'Action rifiuterebbe la registrazione per mancanza di ancoraggio in
     * partita doppia, e mostrarli produrrebbe solo un errore dopo il click.
     */
    public function create(\Illuminate\Http\Request $request, Condominio $condominio): Response
    {
        // Una regolazione registra un'uscita di cassa: il capitolo è una voce di spesa, le entrate non si offrono.
        $capitoli = Conto::whereIn('piano_conto_id', $condominio->pianiDeiConti()->pluck('id'))
            ->whereIn('tipo', Conto::TIPI_DI_SPESA)
            ->whereDoesntHave('sottoconti')
            ->whereNotNull('conto_contabile_id')
            ->visibili()
            ->with(['parent:id,nome', 'pianoConto:id,gestione_id', 'pianoConto.gestione:id,nome'])
            ->get()
            // La gestione di ogni voce: la pagina offre solo le voci della gestione scelta, e l'etichetta la nomina («Riscaldamento · Manutenzione caldaia»).
            ->map(fn ($c) => [
                'id' => $c->id,
                'nome' => $c->nome,
                'parent_nome' => $c->parent?->nome,
                'gestione_id' => $c->pianoConto?->gestione_id,
                'label' => ($c->pianoConto?->gestione ? $c->pianoConto->gestione->nome.' · ' : '')
                    .($c->parent ? $c->parent->nome.' — '.$c->nome : $c->nome),
            ])
            ->sortBy('label')
            ->values();

        return Inertia::render('gestionale/movimenti/regolazioni/RegolazioneImmediataNew', [
            'condominio' => $condominio,
            'condomini' => CondominioResource::collection($this->getCondomini())->resolve(),
            // Obbligatorio: GestionaleHeader legge `props.esercizio` (singolare) per
            // costruire i link a Gestioni, Piani conti e Piani rate. Senza, il setup
            // del componente va in errore e l'intera barra di navigazione sparisce.
            'esercizio' => $this->getEsercizioCorrente($condominio),
            'esercizi' => $condominio->esercizi()->where('stato', 'aperto')->get(['esercizi.id', 'esercizi.nome']),
            'gestioni' => $condominio->gestioni()
                ->where('gestioni.attiva', true)
                ->with('esercizi:id')
                ->get()
                ->map(fn ($g) => [
                    'id' => $g->id,
                    'nome' => $g->nome,
                    'tipo' => $g->tipo,
                    'esercizio_ids' => $g->esercizi->pluck('id')->toArray(),
                ]),
            // Non vincolante: serve solo perché l'amministratore possa annotarlo
            // subito sul documento cartaceo mentre compila. Il numero definitivo
            // è quello assegnato al salvataggio (può scostarsi di poco se nel
            // frattempo viene registrato un altro movimento con lo stesso prefisso).
            'prossimo_protocollo' => ScritturaContabile::previewNextProtocolNumber($condominio->id, 'RIM'),
            'capitoli' => $capitoli,
            // I fondi sono partizioni virtuali dell'unico conto corrente reale, non
            // casse da cui far uscire denaro: una regolazione immediata è un'uscita
            // di cassa vera. Stessa esclusione del form di pagamento fattura.
            'casse' => Cassa::where('condominio_id', $condominio->id)
                ->where('attiva', true)
                ->where('tipo', '!=', 'fondo')
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'nome' => $c->nome,
                    'tipo' => $c->tipo,
                ]),
            // soggetto_ritenuta serve al form per avvisare PRIMA del submit che
            // quel fornitore richiede una fattura (guard rail §6).
            'fornitori' => Fornitore::orderBy('ragione_sociale')
                ->get(['id', 'ragione_sociale', 'soggetto_ritenuta']),
            'precompilata' => $this->precompilata($request, $condominio),
        ]);
    }

    /**
     * Il modulo aperto già compilato da un'altra pagina (la correzione consigliata del controllo della banca: le spese della banca non registrate): la
     * cassa, l'esercizio, la voce, la data, l'importo e la causale arrivano nell'indirizzo, e passano solo se sono di questo condominio e si possono
     * scegliere nel modulo. L'amministratore li rilegge e salva lui; niente si registra da solo.
     *
     * @return array{cassa_id:?int, esercizio_id:?int, conto_id:?int, data_operazione:?string, importo:?string, causale:?string}
     */
    private function precompilata(\Illuminate\Http\Request $request, Condominio $condominio): array
    {
        $intero = fn (string $k) => ctype_digit((string) $request->query($k, '')) ? (int) $request->query($k) : null;
        $cassa = $intero('cassa_id');
        $esercizio = $intero('esercizio_id');
        $conto = $intero('conto_id');
        $data = (string) $request->query('data', '');
        $importo = (string) $request->query('importo', '');

        return [
            'cassa_id' => $cassa !== null && Cassa::where('condominio_id', $condominio->id)->where('attiva', true)->where('tipo', '!=', 'fondo')->whereKey($cassa)->exists() ? $cassa : null,
            'esercizio_id' => $esercizio !== null && $condominio->esercizi()->where('stato', 'aperto')->whereKey($esercizio)->exists() ? $esercizio : null,
            'conto_id' => $conto !== null && Conto::whereIn('piano_conto_id', $condominio->pianiDeiConti()->pluck('id'))->whereIn('tipo', Conto::TIPI_DI_SPESA)
                ->whereNotNull('conto_contabile_id')->whereKey($conto)->exists() ? $conto : null,
            'data_operazione' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) ? $data : null,
            'importo' => preg_match('/^\d{1,9}(\.\d{1,2})?$/', $importo) ? $importo : null,
            'causale' => is_string($request->query('causale')) ? mb_substr(trim((string) $request->query('causale')), 0, 255) : null,
        ];
    }

    /**
     * Storno di una regolazione immediata: scrittura inversa, mai cancellazione.
     * Il giornale è append-only e lo storno è l'operazione sempre ammessa.
     */
    public function storno(
        \Illuminate\Http\Request $request,
        Condominio $condominio,
        \App\Models\Gestionale\ScritturaContabile $scrittura,
        \App\Actions\Gestionale\Movimenti\StornaRegolazioneImmediataAction $action
    ): RedirectResponse {
        $validated = $request->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'motivo.required' => 'È obbligatorio indicare il motivo dello storno.',
            'motivo.min' => 'Il motivo deve contenere almeno 10 caratteri.',
        ]);

        try {
            $storno = $action->execute($scrittura, $condominio, $validated['motivo']);

            // Nel registro la lunghezza del motivo, non il testo: lo scrive l'amministratore e può portare nomi e fatti delle persone.
            Log::info(
                "Storno regolazione immediata {$scrittura->numero_protocollo} → {$storno->numero_protocollo} "
                ."da utente #".Auth::id().' — motivo di '.mb_strlen($validated['motivo']).' caratteri'
            );

            return redirect()
                ->route('admin.gestionale.scritture.show', [
                    'condominio' => $condominio->id,
                    'scrittura' => $storno->id,
                ])
                ->with($this->flashSuccess(
                    "Storno eseguito: scrittura {$storno->numero_protocollo}."
                ));
        } catch (RegolazioneImmediataNonAmmessaException $e) {
            return back()->with($this->flashError($e->getMessage()));
        }
    }

    public function store(
        StoreRegolazioneImmediataRequest $request,
        Condominio $condominio,
        RegistraRegolazioneImmediataAction $action
    ): RedirectResponse {
        $validated = $request->validated();

        $esercizio = Esercizio::where('condominio_id', $condominio->id)
            ->findOrFail($validated['esercizio_id']);

        try {
            $scrittura = $action->execute($validated, $condominio, $esercizio);

            // La causale resta nella scrittura; nel registro solo la sua lunghezza.
            Log::info(
                "Regolazione immediata {$scrittura->numero_protocollo} registrata da utente #".Auth::id()
                ." — condominio #{$condominio->id}, importo {$validated['importo']}, causale di ".mb_strlen((string) $validated['causale']).' caratteri'
            );

            // "Registra e nuova": chi ha una pila di scontrini torna al modulo
            // vuoto, non al dettaglio scrittura.
            if ($request->boolean('crea_altro')) {
                return redirect()
                    ->route('admin.gestionale.regolazioni-immediate.create', ['condominio' => $condominio->id])
                    ->with($this->flashSuccess(
                        "Registrazione {$scrittura->numero_protocollo} eseguita. Il modulo è pronto per la prossima."
                    ));
            }

            // Destinazione: il dettaglio della scrittura appena creata. `movimenti.index`
            // rimbalza su Incassi rate (MovimentiController:27), dove il movimento non
            // compare: l'utente resterebbe senza riscontro di ciò che ha appena registrato.
            return redirect()
                ->route('admin.gestionale.scritture.show', [
                    'condominio' => $condominio->id,
                    'scrittura' => $scrittura->id,
                ])
                ->with($this->flashSuccess(
                    "Registrazione a regolazione immediata {$scrittura->numero_protocollo} eseguita."
                ));
        } catch (RegolazioneImmediataNonAmmessaException $e) {
            // Regola di dominio violata: il messaggio spiega quale percorso usare
            // al posto di questo, quindi va mostrato integralmente all'utente.
            return back()
                ->withInput()
                ->withErrors(['regolazione_non_ammessa' => $e->getMessage()]);
        }
    }
}
