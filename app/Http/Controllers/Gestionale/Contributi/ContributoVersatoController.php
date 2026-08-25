<?php

namespace App\Http\Controllers\Gestionale\Contributi;

use App\Actions\Cassa\RegistraContributoInCassaAction;
use App\Enums\EventoTipo;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContributoVersato;
use App\Services\Gestionale\InboxService;
use App\Services\Gestionale\SaldoCassaService;
use App\Traits\HasEsercizio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Registrazione di quanto ciascuna unità ha GIÀ VERSATO verso una voce di spesa.
 *
 * Serve quando un condominio arriva da un altro gestionale portandosi dietro un
 * accantonamento già raccolto: senza questo dato il motore di riparto richiede
 * l'intera spesa una seconda volta (docs/fondo_accantonato_e_quadratura_sp.md §4).
 */
class ContributoVersatoController extends Controller
{
    use HasEsercizio;

    /** Elenco delle voci di spesa con lo stato della copertura già versata. */
    public function index(Condominio $condominio): Response
    {
        $voci = Conto::query()
            ->whereHas('pianoConto', fn ($q) => $q->where('condominio_id', $condominio->id))
            ->where('tipo', 'spesa')
            ->where('attivo', true)
            // I capitoli sono contenitori, non spese: non si versa nulla "verso" un
            // capitolo, si versa verso i sottoconti che lo compongono (beta.22).
            ->where('is_capitolo', false)
            // Solo le voci marcate esplicitamente "da esercizio precedente" in
            // creazione (beta.27): senza questo filtro comparivano TUTTE le voci
            // di spesa del piano dei conti, confuso su decine di voci quando solo
            // una richiede il già versato. Una voce con coperture GIÀ registrate
            // resta comunque visibile anche se non spuntata (o spuntata dopo):
            // i dati reali non scompaiono mai per un flag mancante.
            ->where(function ($q) {
                $q->where('richiede_gia_versato', true)
                    ->orWhereHas('contributiVersati');
            })
            ->with('pianoConto.gestione')
            ->get();

        $coperture = ContributoVersato::query()
            ->where('condominio_id', $condominio->id)
            ->where('target_type', Conto::class)
            ->selectRaw('target_id, SUM(importo_cents) as totale, COUNT(DISTINCT immobile_id) as unita')
            ->groupBy('target_id')
            ->get()
            ->keyBy('target_id');

        return Inertia::render('gestionale/contributi/ContributiList', [
            'condominio' => $condominio,
            // GestionaleHeader legge page.props.esercizio?.id per costruire i link
            // Gestioni/Piani Conti/Piano Rate: senza questo prop, generatePath()
            // produce un letterale "undefined" nell'URL — 404 al click, bug
            // pre-esistente (non di questa sessione) rilevato dall'utente proprio
            // su questa pagina. Stesso pattern (HasEsercizio) già usato altrove
            // nel gestionale (es. FatturaPassivaController).
            'esercizio' => $this->getEsercizioCorrente($condominio),
            'voci' => $voci->map(function (Conto $c) use ($coperture) {
                $cop = $coperture->get($c->id);

                return [
                    'id'              => $c->id,
                    'nome'            => $c->nome,
                    'gestione'        => $c->pianoConto?->gestione?->nome,
                    'gestione_tipo'   => $c->pianoConto?->gestione?->tipo,
                    'importo_cents'   => (int) $c->importo,
                    'coperto_cents'   => (int) ($cop->totale ?? 0),
                    'unita_coperte'   => (int) ($cop->unita ?? 0),
                ];
            })->values(),
        ]);
    }

    /** Form di inserimento: unità, millesimi, quota lorda e contributo già versato. */
    public function edit(Condominio $condominio, Conto $conto): Response
    {
        abort_unless($conto->pianoConto?->condominio_id === $condominio->id, 404);
        abort_if((bool) $conto->is_capitolo, 404);

        $conto->load('tabelleMillesimali.tabella.quote.immobile', 'tabelleMillesimali.ripartizioni');

        $importo = (int) $conto->importo;

        // Peso di ciascuna unità sulla spesa, mediando le tabelle collegate secondo
        // il loro coefficiente: è la stessa base che usa il motore di riparto.
        //
        // ATTENZIONE — questa è una STIMA, non l'algoritmo del motore reale
        // (CalcoloQuoteService::distribuisciSuTabelle): non conosce le
        // ripartizioni per soggetto (proprietario/inquilino) né la cascata di
        // risoluzione su anagrafiche non attive. Su una voce con tabelle
        // "semplici" (100% proprietario, tutti attivi — il caso comune per un
        // accantonamento migrato) il risultato coincide; altrimenti no. Per
        // questo sotto si rileva quando siamo in un caso "semplice" o no, e la
        // UI lo segnala esplicitamente invece di mostrare un numero come se
        // fosse definitivo.
        $pesi = [];
        $immobili = [];
        $ripartizioneNonStandard = false;

        foreach ($conto->tabelleMillesimali as $ctm) {
            $tabella = $ctm->tabella;
            $coeff   = (float) $ctm->coefficiente;

            if (! $tabella || $coeff <= 0) {
                continue;
            }

            // Una sola riga proprietario=100% è la ripartizione di default,
            // implicita anche quando non c'è alcuna riga configurata. Qualunque
            // altra cosa (più soggetti, percentuali diverse, o soggetto diverso
            // da proprietario) non è rappresentata da questa stima.
            $rip = $ctm->ripartizioni;
            if ($rip->isNotEmpty()
                && !($rip->count() === 1
                    && $rip->first()->soggetto === 'proprietario'
                    && (float) $rip->first()->percentuale === 100.0)) {
                $ripartizioneNonStandard = true;
            }

            $somma = (float) $tabella->quote->sum('valore');
            if ($somma <= 0) {
                continue;
            }

            foreach ($tabella->quote as $q) {
                if (! $q->immobile || (float) $q->valore <= 0) {
                    continue;
                }

                $id = $q->immobile->id;
                $immobili[$id] ??= [
                    'id'        => $id,
                    'nome'      => $q->immobile->nome,
                    'interno'   => $q->immobile->interno,
                    'millesimi' => 0.0,
                ];
                $immobili[$id]['millesimi'] += (float) $q->valore;

                $pesi[$id] = ($pesi[$id] ?? 0) + ((float) $q->valore / $somma) * ($coeff / 100);
            }
        }

        $pesoTotale = array_sum($pesi) ?: 1.0;

        // Con ripartizione di default (proprietario 100%), il motore NON applica
        // alcuna cascata di fallback se manca un proprietario attivo — l'unità va
        // dritta nel bucket "scoperto" (CalcoloQuoteService::distribuisciSuTabelle).
        // Qui basta verificare se ogni immobile coinvolto ha almeno un
        // proprietario attivo per sapere se rischia di finire scoperta.
        $immobiliSenzaProprietarioAttivo = DB::table('immobili as i')
            ->whereIn('i.id', array_keys($immobili))
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('anagrafica_immobile as ai')
                    ->whereColumn('ai.immobile_id', 'i.id')
                    ->where('ai.tipologia', 'proprietario')
                    ->where('ai.attivo', true);
            })
            ->pluck('i.id')
            ->all();

        $stimaSemplificata = $ripartizioneNonStandard || !empty($immobiliSenzaProprietarioAttivo);

        // D8 (docs/fondo_accantonato_e_quadratura_sp.md): la copertura è per
        // IMMOBILE, non per soggetto. Su un conto con ripartizione mista
        // (proprietario/inquilino) il netting sottrae il versamento dal lordo
        // aggregato dell'unità PRIMA di spaccarlo fra i soggetti — un versamento
        // fatto dal solo proprietario finisce per scontare anche l'inquilino.
        // Deciso di bloccare con avviso invece di correggere il calcolo: caso
        // raro, verificare a mano finché non si presenta davvero.
        $ripartizioneMista = $ripartizioneNonStandard;

        $giaVersato = ContributoVersato::query()
            ->where('target_type', Conto::class)
            ->where('target_id', $conto->id)
            ->get()
            ->keyBy('immobile_id');

        // Quote lorde penny-perfect: floor() per ciascun immobile, poi il resto
        // (mai negativo: la somma dei floor è sempre ≤ importo) va — un centesimo
        // alla volta — a chi ha il resto frazionario più alto. Stesso principio
        // "largest remainder" già usato dal motore in distribuisciImporto(): senza
        // questo, la somma delle "Quote dovute" mostrate in pagina può non
        // coincidere nemmeno con l'importo della voce (round() indipendente per
        // riga può sballare la somma di ±1 centesimo per ogni immobile coinvolto).
        $lordi = [];
        $assegnato = 0;
        foreach ($immobili as $id => $im) {
            $peso = ($pesi[$id] ?? 0) / $pesoTotale;
            $lordi[$id] = (int) floor($importo * $peso);
            $assegnato += $lordi[$id];
        }
        $resto = $importo - $assegnato;
        if ($resto > 0) {
            $idOrdinati = array_keys($immobili);
            usort($idOrdinati, function ($a, $b) use ($pesi, $pesoTotale, $importo) {
                $fracA = fmod($importo * (($pesi[$a] ?? 0) / $pesoTotale), 1);
                $fracB = fmod($importo * (($pesi[$b] ?? 0) / $pesoTotale), 1);
                return $fracB <=> $fracA;
            });
            foreach ($idOrdinati as $id) {
                if ($resto <= 0) break;
                $lordi[$id]++;
                $resto--;
            }
        }

        $righe = collect($immobili)->map(function ($im) use ($lordi, $giaVersato) {
            $cv = $giaVersato->get($im['id']);

            return [
                'immobile_id'    => $im['id'],
                'nome'           => $im['nome'],
                'interno'        => $im['interno'],
                'millesimi'      => round($im['millesimi'], 2),
                'quota_lorda'    => $lordi[$im['id']] ?? 0,
                'gia_versato'    => (int) ($cv->importo_cents ?? 0),
                'contributo_id'  => $cv->id ?? null,
            ];
        })->sortBy('interno')->values();

        // La natura e la nota sono per-VOCE, non per-riga: tutte le righe di uno
        // stesso salvataggio le condividono (vedi update()). Una qualunque basta.
        $naturaCorrente      = $giaVersato->first()?->natura ?? ContributoVersato::NATURA_FONDO_VINCOLATO;
        $descrizioneCorrente = $giaVersato->first()?->descrizione;
        $liquiditaStato      = $giaVersato->first()?->liquidita_stato;
        $cassaIdCorrente     = $giaVersato->first()?->cassa_id;

        // Elenco casse/fondi per il selettore dello Scenario A. Include anche i
        // fondi (a differenza del selettore di PagamentoFornitoreController, che
        // li esclude apposta): i soldi già versati possono benissimo essere fermi
        // in un fondo, non solo in banca — è anzi il caso più comune per un
        // accantonamento deliberato ex art. 1135 c.c.
        //
        // is_utilizzabile_per_imprevisti viaggia col dato: un fondo vincolato
        // (natura=fondo_vincolato) non va MAI registrato su una cassa liberamente
        // prelevabile per imprevisti (sottotipo_fondo=generico) — altrimenti
        // quei soldi diventerebbero formalmente disponibili per QUALUNQUE sforo
        // futuro su un'altra voce, aggirando il vincolo di destinazione che la
        // qualificazione "fondo deliberato" esiste per proteggere. Il filtro vero
        // e proprio è lato frontend (ModalLiquiditaGiaVersato.vue) e lato server
        // in update(); qui si espone solo il dato necessario a farlo.
        $casse = app(SaldoCassaService::class)->saldiPerCondominio($condominio)
            ->map(fn ($c) => [
                'id'                             => $c['id'],
                'nome'                           => $c['nome'],
                'tipo'                           => $c['tipo'],
                'saldo_cents'                    => $c['saldo_cents'],
                'is_utilizzabile_per_imprevisti' => $c['is_utilizzabile_per_imprevisti'],
            ])->values();

        return Inertia::render('gestionale/contributi/ContributiEdit', [
            'condominio' => $condominio,
            // Vedi commento identico in index(): senza questo, i link Gestioni/
            // Piani Conti/Piano Rate nel menu producono un "undefined" nell'URL.
            'esercizio' => $this->getEsercizioCorrente($condominio),
            'voce' => [
                'id'            => $conto->id,
                'nome'          => $conto->nome,
                'importo_cents' => $importo,
                'gestione'      => $conto->pianoConto?->gestione?->nome,
            ],
            'righe'              => $righe,
            'natura'             => $naturaCorrente,
            'descrizione'        => $descrizioneCorrente,
            'stima_semplificata' => $stimaSemplificata,
            'ripartizione_mista' => $ripartizioneMista,
            'liquidita_stato'    => $liquiditaStato,
            'cassa_id'           => $cassaIdCorrente,
            'casse'              => $casse,
        ]);
    }

    /** Salva i contributi: una riga per unità, sostituendo quanto già presente. */
    public function update(Request $request, Condominio $condominio, Conto $conto)
    {
        abort_unless($conto->pianoConto?->condominio_id === $condominio->id, 404);
        abort_if((bool) $conto->is_capitolo, 404);

        // Letto PRIMA di qualunque modifica: dice se la domanda "dove sono
        // questi soldi?" è già stata risposta in un salvataggio precedente. Un
        // resave (es. correggere un importo) non deve MAI riaccreditare la
        // cassa né riaprire un secondo task di audit — gli effetti collaterali
        // sotto scattano solo alla PRIMA dichiarazione.
        $liquiditaGiaDichiarata = ContributoVersato::where('target_type', Conto::class)
            ->where('target_id', $conto->id)
            ->whereNotNull('liquidita_stato')
            ->exists();

        $dati = $request->validate([
            'natura'                 => ['required', 'in:fondo_vincolato,avanzo'],
            'righe'                  => ['required', 'array'],
            // Scopato al condominio della rotta: senza questo vincolo un
            // amministratore multi-condominio potrebbe (per errore o payload
            // manomesso) registrare una copertura sull'immobile di UN ALTRO
            // condominio — il netting non la applicherebbe mai a nulla, ma la
            // riga resterebbe orfana nel ledger, invisibile e cross-tenant.
            'righe.*.immobile_id'    => [
                'required', 'integer',
                Rule::exists('immobili', 'id')->where('condominio_id', $condominio->id),
            ],
            'righe.*.gia_versato'    => ['required', 'integer', 'min:0'],
            'descrizione'            => ['nullable', 'string', 'max:255'],
            // "Dove sono questi soldi?" (beta.27, D8-bis). Nessun required_if
            // qui apposta: cassa_id/nota_acconto servono SOLO alla prima
            // dichiarazione (vedi il blocco più sotto, gated da
            // $liquiditaGiaDichiarata) — un required_if costringerebbe il
            // frontend a rimandarli in eterno anche sui resave successivi, per
            // un dato che dopo la prima volta non viene più letto.
            'liquidita_stato'        => ['nullable', Rule::in([
                ContributoVersato::LIQUIDITA_REGISTRATA_IN_CASSA,
                ContributoVersato::LIQUIDITA_GIA_SPESO_ACCONTO,
                ContributoVersato::LIQUIDITA_GIA_IN_APERTURA,
            ])],
            'cassa_id'               => [
                'nullable',
                Rule::exists('casse', 'id')->where('condominio_id', $condominio->id),
            ],
            'nota_acconto'           => ['nullable', 'string', 'max:1000'],
        ]);

        // Un fondo vincolato (delibera ex art. 1135 c.c.) non può finire su una
        // cassa liberamente prelevabile per imprevisti: altrimenti quei soldi
        // diventerebbero formalmente disponibili per QUALUNQUE sforo futuro su
        // un'altra voce, aggirando il vincolo di destinazione che
        // "natura=fondo_vincolato" esiste per proteggere. Nessuna deroga qui
        // (a differenza del fondo di riserva sullo sforo): non c'è urgenza
        // operativa che la giustifichi — l'admin sceglie un'altra cassa o, se
        // serve davvero un override, lo fa dove il vincolo vive per davvero
        // (gestione fondi), non da questa modale.
        //
        // REVISIONE AVVERSARIALE: questo controllo va rifatto ad OGNI
        // salvataggio, non solo alla prima dichiarazione — a differenza dei
        // controlli di "campo obbligatorio" qui sotto (che hanno senso solo la
        // prima volta, quando il dato non esiste ancora), il vincolo può essere
        // violato anche su un resave: un admin potrebbe dichiarare "avanzo" +
        // fondo generico (legittimo, nessun vincolo), poi cambiare "natura" in
        // "fondo_vincolato" mantenendo la stessa cassa — senza questo controllo
        // fuori dal blocco $liquiditaGiaDichiarata, quel secondo salvataggio
        // passava senza errori. Il frontend rimanda comunque cassa_id/natura ad
        // ogni resave (vedi ContributiEdit.vue), quindi il dato è sempre presente.
        if (($dati['liquidita_stato'] ?? null) === ContributoVersato::LIQUIDITA_REGISTRATA_IN_CASSA
            && $dati['natura'] === 'fondo_vincolato'
            && ! empty($dati['cassa_id'])) {
            $cassaScelta = Cassa::find($dati['cassa_id']);
            if ($cassaScelta && $cassaScelta->is_utilizzabile_per_imprevisti) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'cassa_id' => 'Questo è un fondo deliberato (vincolo di destinazione): non può essere '
                        .'registrato su una cassa liberamente utilizzabile per altri imprevisti. Scegli un '
                        .'fondo dedicato a quest\'opera, oppure la banca.',
                ]);
            }
        }

        // Sulla PRIMA dichiarazione, la risposta deve essere completa: una
        // cassa scelta per lo Scenario A, una nota per lo Scenario B. Controllo
        // manuale (non una regola di validazione) perché si applica solo
        // quando conta davvero — vedi commento sopra.
        if (! $liquiditaGiaDichiarata) {
            if (($dati['liquidita_stato'] ?? null) === ContributoVersato::LIQUIDITA_REGISTRATA_IN_CASSA
                && empty($dati['cassa_id'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'cassa_id' => 'Seleziona la cassa o il fondo dove si trovano questi soldi.',
                ]);
            }

            if (($dati['liquidita_stato'] ?? null) === ContributoVersato::LIQUIDITA_GIA_SPESO_ACCONTO
                && empty(trim($dati['nota_acconto'] ?? ''))) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'nota_acconto' => 'Descrivi brevemente quando e a chi è stato versato questo acconto.',
                ]);
            }
        }

        DB::transaction(function () use ($dati, $condominio, $conto, $liquiditaGiaDichiarata) {
            // Sostituzione integrale: l'insieme inviato è la nuova verità per questa voce.
            ContributoVersato::where('target_type', Conto::class)
                ->where('target_id', $conto->id)
                ->delete();

            $liquiditaStato = $dati['liquidita_stato'] ?? null;
            $cassaId = $liquiditaStato === ContributoVersato::LIQUIDITA_REGISTRATA_IN_CASSA
                ? ($dati['cassa_id'] ?? null) : null;

            $totaleGiaVersato = 0;

            foreach ($dati['righe'] as $riga) {
                if ((int) $riga['gia_versato'] <= 0) {
                    continue;
                }

                $totaleGiaVersato += (int) $riga['gia_versato'];

                ContributoVersato::create([
                    'condominio_id'   => $condominio->id,
                    'target_type'     => Conto::class,
                    'target_id'       => $conto->id,
                    'immobile_id'     => $riga['immobile_id'],
                    'importo_cents'   => (int) $riga['gia_versato'],
                    'natura'          => $dati['natura'],
                    'origine'         => 'migrazione',
                    'descrizione'     => $dati['descrizione'] ?? null,
                    'liquidita_stato' => $liquiditaStato,
                    'cassa_id'        => $cassaId,
                ]);
            }

            if ($liquiditaGiaDichiarata || $totaleGiaVersato <= 0) {
                return;
            }

            if ($liquiditaStato === ContributoVersato::LIQUIDITA_REGISTRATA_IN_CASSA && $cassaId) {
                $cassa = Cassa::find($cassaId);
                if ($cassa) {
                    app(RegistraContributoInCassaAction::class)->execute(
                        $cassa,
                        $totaleGiaVersato,
                        mb_substr('Già versato — '.$conto->nome, 0, 255)
                    );
                }
            } elseif ($liquiditaStato === ContributoVersato::LIQUIDITA_GIA_SPESO_ACCONTO) {
                try {
                    // Il link porta a "Registra fattura pregressa", non più alla
                    // pagina del già-versato stessa: quel debito verso il fornitore
                    // va registrato con `is_pregresso` (già esistente, già testato,
                    // con le sue coperture rata_0/fondo_riserva/sopravvenienza) —
                    // non un secondo sistema parallelo. Il già-versato (lato
                    // condòmini) e il pregresso (lato fornitore) restano due
                    // registri distinti e indipendenti: l'admin deve comunque
                    // scegliere lui il fornitore e valutare se la FUTURA fattura
                    // reale rappresenterà il costo totale dell'opera (il netting
                    // resta corretto) o solo il residuo dopo questo acconto (in tal
                    // caso il già-versato non va applicato una seconda volta) —
                    // nessuna delle due scritture può saperlo da sola.
                    InboxService::createTask(
                        tipo: EventoTipo::GIA_VERSATO_ACCONTO_DICHIARATO,
                        title: 'Già versato dichiarato come acconto già speso — '.$conto->nome,
                        description: 'L\'amministratore ha dichiarato che il già versato per "'.$conto->nome.'" '
                            .'(€ '.number_format($totaleGiaVersato / 100, 2, ',', '.').') è già stato versato al '
                            .'fornitore come acconto, prima di Kondomanager. Nota: '.($dati['nota_acconto'] ?? '—')
                            .'. Registra questo importo come fattura pregressa (is_pregresso) verso il fornitore '
                            .'corretto: è lo stesso meccanismo già usato per i debiti ereditati da altri gestionali. '
                            .'Quando arriverà la fattura reale per il residuo dei lavori, verifica se rappresenta il '
                            .'costo totale dell\'opera o solo il saldo dopo questo acconto — il già-versato va '
                            .'applicato solo nel primo caso.',
                        scadenza: now(),
                        createdByUserId: auth()->id() ?? 1,
                        condominioId: $condominio->id,
                        context: [
                            'conto_id'      => $conto->id,
                            'conto_nome'    => $conto->nome,
                            'importo_cents' => $totaleGiaVersato,
                        ],
                        actionUrl: route('admin.gestionale.fatture.create', ['condominio' => $condominio->id]),
                        priorita: 'alta'
                    );
                } catch (\Throwable $e) {
                    // Non blocca il salvataggio se il task inbox fallisce — stesso
                    // principio di GeneratePianoRateAction per SCOPERTO_DOCUMENTATO.
                    report($e);
                }
            }
        });

        return back()->with('message', [
            'type' => 'success',
            'text' => 'Contributi già versati aggiornati: il riparto chiederà solo il residuo.',
        ]);
    }
}
