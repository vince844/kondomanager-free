<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Traits\OrdinaElenco;

use App\Actions\Gestionale\Movimenti\ConfermaVersamentoF24Action;
use App\Actions\Gestionale\Movimenti\GeneraDelegheF24Action;
use App\Actions\Gestionale\Movimenti\StornaVersamentoF24Action;
use App\Enums\Fiscale\StatoDelegaF24;
use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\DelegaF24;
use App\Services\Gestionale\F24\PromemoriaDelleRitenute;
use App\Services\Gestionale\ModelloF24Service;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use App\Traits\PaginaElenco;
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
    use OrdinaElenco;

    /**
     * Le colonne ordinabili dell'elenco deleghe F24.
     *
     * ⚠️ Fuori «Codici tributo», che è un elenco di righe: stessa famiglia delle colonne con
     * array spente nell'elenco unità.
     */
    public static function colonneOrdinabili(): array
    {
        return [
            'data_scadenza' => 'data_scadenza',
            'plafond'       => 'plafond',
            'totale_debito' => 'totale_debito',
            'stato'         => 'stato',
        ];
    }

    use HandleFlashMessages;
    use HasCondomini;
    use HasEsercizio;
    use PaginaElenco;

    public function __construct(
        private GeneraDelegheF24Action $genera,
        private ConfermaVersamentoF24Action $conferma,
        private StornaVersamentoF24Action $storna,
    ) {}

    public function index(Request $request, Condominio $condominio): Response
    {
        // ⚠️ Questo elenco dichiarava `colonneOrdinabili()`, rendeva cliccabili quattro
        // intestazioni e **non chiamava mai `ordina()`**: i clic non producevano alcun effetto.
        // Le due chiavi vanno anche validate, perché il nome della colonna finisce dentro
        // `orderBy()` e la lista delle ammesse è il confine contro l'iniezione.
        $ordinamento = $request->validate(
            self::regoleOrdinamento(array_keys(self::colonneOrdinabili()))
        );

        $esercizio = $this->esercizioCorrente($condominio);

        // ⚠️ **Le deleghe di tutti gli esercizi** (Coda 314, decisione 154.3). Fino alla 1.11.0-beta.58 l'elenco e i
        // contatori guardavano il solo esercizio corrente: chiuso il vecchio per aprire il nuovo, una bozza o una
        // confermata del vecchio spariva dall'elenco, da «Da versare» e da «Scadute», anche con la scadenza del 16
        // gennaio ancora davanti. Una delega aperta è un debito verso l'Erario finché non è versata, e lo storico — le
        // versate, le stornate e le annullate — non si perde al cambio dell'esercizio: ogni riga di un esercizio non
        // corrente ne porta il nome. Il «Versato», invece, resta dell'esercizio corrente (`riepilogo()`).
        $aperte = [StatoDelegaF24::BOZZA->value, StatoDelegaF24::CONFERMATA->value];

        $base = DelegaF24::delCondominio($condominio->id);

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
            ->when($filtri['stato'], function ($q, $stato) use ($aperte) {
                // Due voci non sono stati del database ma domande che l'amministratore si
                // fa davvero: «cosa mi resta da versare» e «cosa è già in ritardo». La
                // seconda è la sola che costa sanzioni, quindi merita un filtro suo.
                //
                // «Scadute» è la regola sola di `DelegaF24::scopeScadute()` (Coda 423): la scadenza prima di oggi, nel giorno
                // dell'amministratore. Il giorno stesso si può ancora versare.
                match ($stato) {
                    'da_versare' => $q->whereIn('stato', $aperte),
                    'scadute' => $q->scadute(),
                    default => $q->where('stato', $stato),
                };
            })
            ->when($filtri['plafond'], fn ($q, $p) => $q->where('plafond', $p));

        // Il riepilogo guarda TUTTE le deleghe dell'elenco, non la pagina corrente: un
        // totale «da versare» che cambia sfogliando sarebbe un numero senza significato.
        $riepilogo = $this->riepilogo((clone $base)->get(), $esercizio);

        // Le righe per pagina si risolvono qui, una volta: la scelta esplicita se c'è, altrimenti
        // quella che l'utente aveva già fatto su questo elenco, altrimenti le impostazioni generali.
        $perPage = $this->righePerPagina($request);

        $deleghe = $filtrata
            ->with('righe')
            // Le scadenze aperte per prime, e fra queste la più vicina in cima: è l'ordine
            // in cui vanno affrontate. Lo storico viene dopo, anch'esso per scadenza crescente.
            //
            // ⚠️ Resta l'ordine **finché nessuno chiede altro**: `ordina()` azzera ciò che trova
            // (`reorder()`) proprio perché un ordinamento chiesto dev'essere il criterio primario e
            // non uno spareggio dietro questo `CASE`. Senza `predefinita`, una richiesta senza
            // `sort` lascia la query com'è e l'ordine di lavoro sopravvive.
            ->orderByRaw("CASE WHEN stato IN ('bozza','confermata') THEN 0 ELSE 1 END")
            ->orderBy('data_scadenza')
            ->orderBy('id')
            ->tap(fn ($q) => $this->ordina($q, $ordinamento, self::colonneOrdinabili()))
            ->paginate($perPage)
            ->withQueryString();

        // Una delega di un esercizio che non è il corrente porta il nome del suo esercizio: è l'esercizio in cui
        // finisce la scrittura, se se ne registra il versamento (`ConfermaVersamentoF24Action`, Coda 314).
        $nomiEsercizi = $condominio->esercizi()->pluck('nome', 'id');

        // Il paginatore di Laravel serializza i suoi contatori in piatto; il `Datatable`
        // del gestionale li vuole sotto `meta`, come glieli passano le altre pagine
        // tramite le API Resource. Qui non serve una Resource: basta dargli la forma giusta.
        $delegheProps = [
            'data' => collect($deleghe->items())
                ->map(fn (DelegaF24 $d) => array_merge($d->toArray(), [
                    'esercizio_non_corrente' => $this->esercizioNonCorrente($d, $esercizio, $nomiEsercizi),
                ]))
                ->all(),
            'meta' => [
                'current_page' => $deleghe->currentPage(),
                'per_page' => $deleghe->perPage(),
                'last_page' => $deleghe->lastPage(),
                'total' => $deleghe->total(),
            ],
        ];

        // ⚠️ **Si dice PRIMA che si prema il pulsante, non dopo che fallisce.**
        // `GeneraDelegheF24Action` rifiuta di preparare la delega se anche un solo
        // fornitore non ha la natura del percipiente — senza, il codice tributo sarebbe
        // 1019 o 1020 a caso (Coda 119). Ma scoprire un blocco solo sbattendoci contro è
        // un blocco che si subisce: l'elenco arriva in pagina, con il collegamento a
        // ciascuna anagrafica, così il rimedio è un clic.
        $daClassificare = $esercizio
            ? $this->genera->fornitoriDaClassificare(
                $this->genera->ritenuteInAttesa($condominio)
            )
            : [];

        return Inertia::render('gestionale/movimenti/f24/F24List', [
            'fornitori_da_classificare' => $daClassificare,
            // Le deleghe da versare con la ritenuta di una fattura già versata con un'altra delega, per un pagamento poi stornato
            // (decisione 704): lo scadenzario lo dice prima del versamento, con il collegamento alla delega.
            'avvisi_ritenute_gia_versate' => (clone $base)->aperte()->orderBy('data_scadenza')->orderBy('id')->get()
                ->flatMap(fn (DelegaF24 $d) => array_map(fn (string $m) => ['delega_id' => $d->id, 'messaggio' => $m], $d->avvisiRitenuteGiaVersate()))
                ->values()
                ->all(),
            'condominio' => $condominio,
            'condomini' => $this->getCondomini(),
            'esercizio' => $esercizio,
            'deleghe' => $delegheProps,
            'banche' => $this->banche($condominio),
            'riepilogo' => $riepilogo,
            'filters' => $filtri,
            // La freccetta nell'intestazione legge questi, non uno stato locale del componente:
            // così non può dichiarare un ordinamento diverso da quello davvero applicato.
            'sort'      => $ordinamento['sort'] ?? null,
            'direction' => $ordinamento['direction'] ?? null,
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
            // Il codice fiscale della delega, o in mancanza quello del condominio di oggi: lo stesso della stampa del modello.
            'codice_fiscale_contribuente' => $delega->codiceFiscaleDelContribuente(),
            'esercizio_non_corrente' => $this->esercizioNonCorrente(
                $delega,
                $this->esercizioCorrente($condominio),
                $condominio->esercizi()->pluck('nome', 'id'),
            ),
            'banche' => $this->banche($condominio),
            'motivo_blocco_modifica' => $delega->motivoBloccoModifica(),
            // La ritenuta di una fattura già versata con un'altra delega, per un pagamento poi stornato (decisione 704): non va versata di nuovo.
            'avvisi_ritenute_gia_versate' => $delega->avvisiRitenuteGiaVersate(),
            // Il modello ministeriale si stampa comunque: questi campi non sbarrano la
            // stampa, la accompagnano. Dirlo qui — dove il pulsante sta — è l'unico momento
            // in cui l'informazione serve ancora a qualcosa: allo sportello è tardi.
            'campi_mancanti' => app(ModelloF24Service::class)->campiMancanti($delega),
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
                return back()->with($this->flashSuccess($this->fraseSenzaRitenuteNuove($condominio)));
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

            return back()->with($this->flashError(\App\Support\Errori\MessaggioPerLaPagina::di($e, 'DelegaF24Controller@genera')));
        }
    }

    public function conferma(Request $request, Condominio $condominio, DelegaF24 $delega): RedirectResponse
    {
        abort_if($delega->condominio_id !== $condominio->id, 403, 'Accesso non autorizzato.');

        $dati = $request->validate([
            'data_versamento' => ['required', 'date'],
            // ⚠️ Il conto del condominio, e il conto di una sua cassa da cui si paga (Coda 432): con il solo `exists` una richiesta
            // scritta a mano registrava il versamento sulla banca di un altro condominio. La stessa forma del pagamento di una fattura
            // (`StorePagamentoFornitoreRequest`).
            'conto_corrente_id' => [
                'required', 'integer',
                \Illuminate\Validation\Rule::exists('conti_contabili', 'id')->where('condominio_id', $condominio->id),
                \Illuminate\Validation\Rule::exists('casse', 'conto_contabile_id')
                    ->where('condominio_id', $condominio->id)
                    ->whereIn('tipo', ['banca', 'contanti', 'virtuale']),
            ],
            // ⚠️ Scopata al condominio e ai soli tipi da cui si paga (beta.19): prima
            // accettava la cassa di un altro condominio e una cassa `fondo`, e il versamento
            // da un fondo spariva dal registro di contabilità (D15). Revisione della beta.24.
            'cassa_id' => [
                'nullable', 'integer',
                \Illuminate\Validation\Rule::exists('casse', 'id')
                    ->where('condominio_id', $condominio->id)
                    ->whereIn('tipo', ['banca', 'contanti', 'virtuale']),
            ],
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

            return back()->withErrors(['versamento' => \App\Support\Errori\MessaggioPerLaPagina::di($e, 'DelegaF24Controller@conferma')]);
        }
    }

    /**
     * Conferma la delega: dalla bozza a «Confermata» (Coda 754 (10), decisione 696 D6).
     *
     * Una delega confermata è quella che l'amministratore ha deciso di versare: «Aggiorna scadenze» non la cancella e non la rifà
     * (`GeneraDelegheF24Action` cancella solo le bozze, e le sue ritenute risultano già in una delega), e il suo promemoria la segue.
     * Non scrive niente in contabilità: il versamento si registra dopo, come per una bozza. Mai su una delega versata, stornata o annullata.
     */
    public function confermaDelega(Condominio $condominio, DelegaF24 $delega): RedirectResponse
    {
        abort_if($delega->condominio_id !== $condominio->id, 403, 'Accesso non autorizzato.');

        if ($delega->stato !== StatoDelegaF24::BOZZA) {
            return back()->withErrors(['stato_delega' => $this->frasePerLoStatoNonAmmesso($delega, 'si conferma')]);
        }

        // Una bozza con la ritenuta di un pagamento stornato non si tiene ferma: «Aggiorna scadenze» la rifà senza (Coda 754 (10)).
        $stornati = $delega->motivoBloccoPerPagamentiStornati();
        if ($stornati !== null) {
            return back()->withErrors(['stato_delega' => $stornati]);
        }

        $delega->update(['stato' => StatoDelegaF24::CONFERMATA]);

        return back()->with($this->flashSuccess(
            'Delega confermata: «Aggiorna scadenze» non la rifà più. Per modificarla, riportala in bozza.'
        ));
    }

    /** Riporta in bozza una delega confermata: «Aggiorna scadenze» torna a rifarla. Mai su una versata, stornata o annullata. */
    public function riportaInBozza(Condominio $condominio, DelegaF24 $delega): RedirectResponse
    {
        abort_if($delega->condominio_id !== $condominio->id, 403, 'Accesso non autorizzato.');

        if ($delega->stato !== StatoDelegaF24::CONFERMATA) {
            return back()->withErrors(['stato_delega' => $this->frasePerLoStatoNonAmmesso($delega, 'si riporta in bozza')]);
        }

        $delega->update(['stato' => StatoDelegaF24::BOZZA]);

        return back()->with($this->flashSuccess('Delega riportata in bozza: «Aggiorna scadenze» può rifarla.'));
    }

    /** Perché la delega, nello stato in cui è, non si conferma o non si riporta in bozza: la frase dice lo stato e che cosa fare. */
    private function frasePerLoStatoNonAmmesso(DelegaF24 $delega, string $azione): string
    {
        return match ($delega->stato) {
            StatoDelegaF24::VERSATA => 'La delega è già stata versata: non '.$azione.'. Per correggerla va stornata.',
            StatoDelegaF24::STORNATA => 'La delega è stata stornata: non '.$azione.'.',
            StatoDelegaF24::ANNULLATA => 'La delega è stata annullata: non '.$azione.'.',
            StatoDelegaF24::CONFERMATA => 'La delega è già confermata.',
            StatoDelegaF24::BOZZA => 'La delega è già in bozza.',
        };
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

        // Le ritenute della delega annullata tornano senza delega: il suo promemoria si chiude e torna il «Prepara le deleghe F24»
        // (Coda 718).
        app(PromemoriaDelleRitenute::class)->allineaSenzaFermare($condominio);

        return back()->with($this->flashSuccess('Delega annullata.'));
    }

    // ── Supporto ─────────────────────────────────────────────────────────────

    /**
     * Che cosa dice «Aggiorna scadenze» quando non c'è niente da mettere in una delega nuova (Coda 754 (10)).
     *
     * Le ritenute non versate possono stare in una delega **confermata**, che il pulsante non rifà: dire «tutte versate» in quel caso
     * era falso, e la confermata da versare spariva dal messaggio. «Già versate» resta per quando non ce n'è nessuna.
     */
    private function fraseSenzaRitenuteNuove(Condominio $condominio): string
    {
        $confermate = DelegaF24::where('condominio_id', $condominio->id)
            ->where('stato', StatoDelegaF24::CONFERMATA->value)
            ->orderBy('data_scadenza')
            ->get();

        if ($confermate->isEmpty()) {
            return 'Nessuna ritenuta da versare: tutte quelle operate risultano già versate.';
        }

        $prima = $confermate->first()->data_scadenza->format('d/m/Y');
        $totale = MoneyHelper::format((int) $confermate->sum('totale_debito'));

        return $confermate->count() === 1
            ? "Nessuna ritenuta nuova: quelle non ancora versate sono già nella delega confermata in scadenza il {$prima} ({$totale})."
            : sprintf('Nessuna ritenuta nuova: quelle non ancora versate sono già nelle %d deleghe confermate (%s), la prima in scadenza il %s.',
                $confermate->count(), $totale, $prima);
    }

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

        // L'esercizio serve solo a sapere se ce n'è uno: le ritenute sono di tutti gli esercizi (Coda 314).
        $ritenute = $this->genera->ritenuteDaVersare($condominio);

        return [
            'numero' => $ritenute->count(),
            'totale_cents' => (int) $ritenute->sum('importo'),
        ];
    }

    /**
     * Il nome dell'esercizio della delega quando non è quello corrente, altrimenti `null`.
     *
     * @param  \Illuminate\Support\Collection<int,string>  $nomiEsercizi
     */
    private function esercizioNonCorrente(DelegaF24 $delega, $corrente, $nomiEsercizi): ?string
    {
        if ($corrente !== null && $delega->esercizio_id === $corrente->id) {
            return null;
        }

        return $nomiEsercizi[$delega->esercizio_id] ?? null;
    }

    /** L'esercizio aperto (con due, il più recente: Coda 646, decisione 707 (4), `HasEsercizio`); senza un aperto, l'ultimo scritto. */
    private function esercizioCorrente(Condominio $condominio)
    {
        return \App\Models\Esercizio::apertoPiuRecente((int) $condominio->id)
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
     *
     * «Da versare», «Scadute» e la prossima scadenza guardano le deleghe aperte di tutti gli esercizi. Il «Versato» è
     * quello delle deleghe versate **dell'esercizio corrente**: la scrittura del versamento sta nell'esercizio della
     * delega (`ConfermaVersamentoF24Action`), e le versate dei vecchi esercizi si vedono nello storico senza sommarsi.
     */
    private function riepilogo($deleghe, $esercizio): array
    {
        $aperte = $deleghe->whereIn('stato', [StatoDelegaF24::BOZZA, StatoDelegaF24::CONFERMATA]);
        $versate = $deleghe->where('stato', StatoDelegaF24::VERSATA)
            ->when($esercizio, fn ($d) => $d->where('esercizio_id', $esercizio->id));

        return [
            'da_versare_cents' => (int) $aperte->sum('totale_debito'),
            'versato_cents' => (int) $versate->sum('totale_debito'),
            // La stessa regola del filtro «Scadute» (Coda 423): prima `isPast()` contava la delega già il giorno della scadenza.
            'scadute' => $aperte->filter(fn (DelegaF24 $d) => $d->isScaduta())->count(),
            'prossima_scadenza' => $aperte->sortBy('data_scadenza')->first()?->data_scadenza?->toDateString(),
        ];
    }
}
