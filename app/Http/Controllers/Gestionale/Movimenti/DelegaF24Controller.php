<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Actions\Gestionale\Movimenti\ConfermaVersamentoF24Action;
use App\Actions\Gestionale\Movimenti\GeneraDelegheF24Action;
use App\Actions\Gestionale\Movimenti\StornaVersamentoF24Action;
use App\Enums\Fiscale\StatoDelegaF24;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\DelegaF24;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lo scadenzario delle ritenute e le deleghe F24.
 *
 * È la schermata che risponde alla domanda che l'amministratore si fa ogni mese intorno al
 * 16: *«devo versare qualcosa, e quanto?»*. Fino a questa versione la risposta andava
 * ricostruita a mano, fattura per fattura, applicando due soglie diverse e tre regole di
 * scadenza — e il conto 2202 non si chiudeva mai perché il movimento che lo chiude non
 * esisteva.
 */
class DelegaF24Controller extends Controller
{
    use HandleFlashMessages;
    use HasCondomini;
    use HasEsercizio;

    public function __construct(
        private GeneraDelegheF24Action $genera,
        private ConfermaVersamentoF24Action $conferma,
        private StornaVersamentoF24Action $storna,
    ) {}

    public function index(Request $request, Condominio $condominio): Response
    {
        $esercizio = $this->esercizioCorrente($condominio);

        $base = DelegaF24::delCondominio($condominio->id)
            ->when($esercizio, fn ($q) => $q->where('esercizio_id', $esercizio->id));

        // I filtri restano in query string: così una vista filtrata si può mettere nei
        // preferiti o mandare per link, come nelle altre pagine del gestionale.
        $filtri = [
            'search' => $request->string('search')->toString() ?: null,
            'stato' => $request->string('stato')->toString() ?: null,
            'plafond' => $request->string('plafond')->toString() ?: null,
        ];

        $filtrata = (clone $base)
            ->when($filtri['search'], function ($q, $testo) {
                // Si cerca per codice tributo — il campo con cui si ragiona avendo l'F24
                // davanti — e per le note dell'amministratore.
                $q->where(function ($sub) use ($testo) {
                    $sub->whereHas('righe', fn ($r) => $r->where('codice_tributo', 'like', "%{$testo}%"))
                        ->orWhere('note', 'like', "%{$testo}%");
                });
            })
            ->when($filtri['stato'], function ($q, $stato) {
                $aperte = [StatoDelegaF24::BOZZA->value, StatoDelegaF24::CONFERMATA->value];

                // Due voci non sono stati del database ma domande che l'amministratore si
                // fa davvero: «cosa mi resta da versare» e «cosa è già in ritardo». La
                // seconda è la sola che costa sanzioni, quindi merita un filtro suo.
                match ($stato) {
                    'da_versare' => $q->whereIn('stato', $aperte),
                    'scadute' => $q->whereIn('stato', $aperte)->whereDate('data_scadenza', '<', now()->toDateString()),
                    default => $q->where('stato', $stato),
                };
            })
            ->when($filtri['plafond'], fn ($q, $p) => $q->where('plafond', $p));

        // Il riepilogo guarda TUTTE le deleghe dell'esercizio, non la pagina corrente: un
        // totale «da versare» che cambia sfogliando sarebbe un numero senza significato.
        $riepilogo = $this->riepilogo((clone $base)->get());

        $deleghe = $filtrata
            ->with('righe')
            // Le scadenze aperte per prime, e fra queste la più vicina in cima: è l'ordine
            // in cui vanno affrontate. Lo storico viene dopo, dalla più recente.
            ->orderByRaw("CASE WHEN stato IN ('bozza','confermata') THEN 0 ELSE 1 END")
            ->orderBy('data_scadenza')
            ->paginate((int) $request->input('per_page', 15))
            ->withQueryString();

        // Il paginatore di Laravel serializza i suoi contatori in piatto; il `Datatable`
        // del gestionale li vuole sotto `meta`, come glieli passano le altre pagine
        // tramite le API Resource. Qui non serve una Resource: basta dargli la forma giusta.
        $delegheProps = [
            'data' => $deleghe->items(),
            'meta' => [
                'current_page' => $deleghe->currentPage(),
                'per_page' => $deleghe->perPage(),
                'last_page' => $deleghe->lastPage(),
                'total' => $deleghe->total(),
            ],
        ];

        return Inertia::render('gestionale/movimenti/f24/F24List', [
            'condominio' => $condominio,
            'condomini' => $this->getCondomini(),
            'esercizio' => $esercizio,
            'deleghe' => $delegheProps,
            'banche' => $this->banche($condominio),
            'riepilogo' => $riepilogo,
            'filters' => $filtri,
            // Le soglie arrivano da `config/fiscale.php`, non riscritte a mano nel testo
            // della guida: se il legislatore le cambia, si tocca un posto solo e la
            // schermata smette di raccontare un numero che non è più quello.
            // Il pulsante «Aggiorna scadenze» sa se serve premerlo: qui contiamo le
            // ritenute operate che non stanno in nessuna delega viva. È la stessa query
            // che poi le raccoglie, quindi non può divergere dal risultato del ricalcolo.
            'da_calcolare' => $this->scoperte($condominio, $esercizio?->id),
            'soglie' => [
                'appalti_cents' => (int) config('fiscale.plafond.appalto_4'),
                'professionisti_cents' => (int) config('fiscale.plafond.lavoro_autonomo_20'),
            ],
        ]);
    }

    public function show(Condominio $condominio, DelegaF24 $delega): Response|RedirectResponse
    {
        abort_if($delega->condominio_id !== $condominio->id, 403, 'Accesso non autorizzato.');

        $delega->load(['righe.pagamenti.fornitore', 'scrittura']);

        return Inertia::render('gestionale/movimenti/f24/F24Show', [
            'condominio' => $condominio,
            'condomini' => $this->getCondomini(),
            'delega' => $delega,
            'banche' => $this->banche($condominio),
            'motivo_blocco_modifica' => $delega->motivoBloccoModifica(),
        ]);
    }

    /**
     * Ricalcola le bozze dalle ritenute non ancora versate.
     *
     * Le bozze non sono documenti: sono una proposta, e si rifanno da zero ogni volta. È il
     * motivo per cui il plafond è una funzione pura e non un contatore — si ricostruisce
     * senza doversi fidare di uno stato accumulato.
     */
    public function genera(Condominio $condominio): RedirectResponse
    {
        $esercizio = $this->esercizioCorrente($condominio);

        if (! $esercizio) {
            return back()->with($this->flashError('Nessun esercizio aperto: non si può calcolare lo scadenzario.'));
        }

        try {
            $deleghe = $this->genera->esegui($condominio, $esercizio->id);

            if ($deleghe->isEmpty()) {
                return back()->with($this->flashSuccess(
                    'Nessuna ritenuta da versare: tutte quelle operate risultano già versate.'
                ));
            }

            $totale = $deleghe->sum('totale_debito');

            // Il messaggio descrive lo STATO risultante, non l'operazione.
            // Diceva «3 deleghe calcolate», e suonava come tre cose nuove: in realtà il
            // ricalcolo rifà da zero tutte le bozze, quindi due erano identiche a prima e
            // una sola era nuova. Chi lo leggeva accanto al pulsante che ne segnalava una
            // trovava due numeri diversi per lo stesso fatto — nessuno dei due sbagliato,
            // ma insieme incomprensibili.
            return back()->with($this->flashSuccess(sprintf(
                'Scadenzario aggiornato: %d %s da versare, per un totale di € %s.',
                $deleghe->count(),
                $deleghe->count() === 1 ? 'delega' : 'deleghe',
                number_format($totale / 100, 2, ',', '.')
            )));

        } catch (\Exception $e) {
            Log::error("Errore calcolo deleghe F24 per condominio {$condominio->id}: ".$e->getMessage());

            return back()->with($this->flashError($e->getMessage()));
        }
    }

    public function conferma(Request $request, Condominio $condominio, DelegaF24 $delega): RedirectResponse
    {
        abort_if($delega->condominio_id !== $condominio->id, 403, 'Accesso non autorizzato.');

        $dati = $request->validate([
            'data_versamento' => ['required', 'date'],
            'conto_corrente_id' => ['required', 'integer', 'exists:conti_contabili,id'],
            'cassa_id' => ['nullable', 'integer', 'exists:casse,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->conferma->esegui(
                $delega,
                $dati['data_versamento'],
                (int) $dati['conto_corrente_id'],
                $dati['cassa_id'] ?? null,
                $dati['note'] ?? null,
            );

            return back()->with($this->flashSuccess(
                'Versamento registrato: il debito verso l\'Erario è stato estinto.'
            ));

        } catch (\DomainException $e) {
            return back()->withErrors(['versamento' => $e->getMessage()]);

        } catch (\Exception $e) {
            Log::error("Errore versamento delega F24 #{$delega->id}: ".$e->getMessage());

            return back()->withErrors(['versamento' => $e->getMessage()]);
        }
    }

    public function storna(Request $request, Condominio $condominio, DelegaF24 $delega): RedirectResponse
    {
        abort_if($delega->condominio_id !== $condominio->id, 403, 'Accesso non autorizzato.');

        $dati = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:1000'],
            'data_storno' => ['nullable', 'date'],
        ]);

        try {
            $this->storna->esegui($delega, $dati['motivo'], $dati['data_storno'] ?? null);

            return back()->with($this->flashSuccess(
                'Versamento stornato: le ritenute tornano da versare e rientrano nel prossimo calcolo.'
            ));

        } catch (\DomainException $e) {
            return back()->withErrors(['storno' => $e->getMessage()]);
        }
    }

    /**
     * Annulla una delega non ancora versata.
     *
     * Non è una cancellazione: la delega resta, con il motivo scritto. Una delega va
     * conservata dieci anni, ed è la ragione per cui la tabella non ha `softDeletes`.
     */
    public function annulla(Request $request, Condominio $condominio, DelegaF24 $delega): RedirectResponse
    {
        abort_if($delega->condominio_id !== $condominio->id, 403, 'Accesso non autorizzato.');

        $dati = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:1000']]);

        if ($delega->stato->haEffettoContabile()) {
            return back()->withErrors([
                'annullamento' => 'La delega ha già prodotto movimenti contabili: va stornata, non annullata.',
            ]);
        }

        $delega->update([
            'stato' => StatoDelegaF24::ANNULLATA,
            'motivo_annullamento' => $dati['motivo'],
        ]);

        return back()->with($this->flashSuccess('Delega annullata.'));
    }

    // ── Supporto ─────────────────────────────────────────────────────────────

    /**
     * Quante ritenute, e per quanto, aspettano ancora di finire in una delega.
     *
     * Zero significa che lo scadenzario è allineato ai pagamenti registrati; un numero
     * diverso da zero è l'unica condizione in cui premere «Aggiorna scadenze» cambia
     * qualcosa — ed è per questo che il pulsante lo dice invece di stare lì muto.
     *
     * @return array{numero: int, totale_cents: int}
     */
    private function scoperte(Condominio $condominio, ?int $esercizioId): array
    {
        if (! $esercizioId) {
            return ['numero' => 0, 'totale_cents' => 0];
        }

        $ritenute = $this->genera->ritenuteDaVersare($condominio, $esercizioId);

        return [
            'numero' => $ritenute->count(),
            'totale_cents' => (int) $ritenute->sum('importo'),
        ];
    }

    private function esercizioCorrente(Condominio $condominio)
    {
        return $condominio->esercizi()->where('stato', 'aperto')->latest('id')->first()
            ?? $condominio->esercizi()->latest('id')->first();
    }

    /**
     * I conti da cui può uscire il denaro. Esclude i fondi: un F24 si paga dal conto
     * corrente, non da una partizione di accantonamento.
     */
    private function banche(Condominio $condominio): array
    {
        return DB::table('casse')
            ->join('conti_contabili', 'conti_contabili.id', '=', 'casse.conto_contabile_id')
            ->where('casse.condominio_id', $condominio->id)
            ->where('casse.attiva', true)
            ->where('casse.tipo', '!=', 'fondo')
            ->select([
                'conti_contabili.id',
                'casse.id as cassa_id',
                'casse.nome',
                'casse.tipo',
            ])
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * I tre numeri in cima alla pagina: quanto si deve, quanto è già uscito, cosa è in
     * ritardo. Il terzo è quello che conta — una scadenza fiscale mancata costa sanzioni.
     */
    private function riepilogo($deleghe): array
    {
        $aperte = $deleghe->whereIn('stato', [StatoDelegaF24::BOZZA, StatoDelegaF24::CONFERMATA]);

        return [
            'da_versare_cents' => (int) $aperte->sum('totale_debito'),
            'versato_cents' => (int) $deleghe->where('stato', StatoDelegaF24::VERSATA)->sum('totale_debito'),
            'scadute' => $aperte->filter(fn ($d) => $d->data_scadenza->isPast())->count(),
            'prossima_scadenza' => $aperte->sortBy('data_scadenza')->first()?->data_scadenza?->toDateString(),
        ];
    }
}
