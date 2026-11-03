<?php

namespace App\Http\Controllers\Gestionale\Contributi;

use App\Helpers\DateHelper;
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
use App\Services\Riparto\RisolutoreTitolari;

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
                    ->where('ai.tipologia', 'proprietario');
                app(RisolutoreTitolari::class)->vincolaQuery($q, null, 'ai');
            })
            ->pluck('i.id')
            ->all();

        $stimaSemplificata = $ripartizioneNonStandard || !empty($immobiliSenzaProprietarioAttivo);

        // D8 (docs/fondo_accantonato_e_quadratura_sp.md) e decisione 17 (B2): la copertura senza persona
        // è dell'IMMOBILE — su una ripartizione mista (proprietario/inquilino) il netting la sottrae dal
        // lordo dell'unità prima di spaccarlo fra i soggetti, e un versamento «dell'unità» sconta anche
        // l'inquilino. Dalla beta.31 la riga può portare la PERSONA («versato da»): allora sconta solo il
        // lordo di quella persona, e l'avanzo resta a suo nome (eccedenza). L'avviso resta per le righe
        // senza persona.
        $ripartizioneMista = $ripartizioneNonStandard;

        $giaVersato = ContributoVersato::query()
            ->where('target_type', Conto::class)
            ->where('target_id', $conto->id)
            ->get()
            ->groupBy('immobile_id');

        // Chi può aver versato, unità per unità: i titolari di diritto reale in corso OGGI (la pagina non ha
        // una data del versamento: «alla data» qui vuol dire oggi, e lo si dice a video).
        $oggi = DateHelper::oggiUtenteImmutable();
        $titolariPerImmobile = \App\Models\TitolaritaImmobile::with('anagrafica:id,nome')
            ->whereIn('immobile_id', array_keys($immobili))
            ->whereIn('tipologia', ['proprietario', 'nuda_proprietario', 'usufruttuario'])
            ->get()
            ->filter(fn ($t) => $t->inCorsoIl($oggi))
            ->groupBy('immobile_id')
            ->map(fn ($righe) => $righe->map(fn ($t) => [
                'id' => (int) $t->anagrafica_id, 'nome' => $t->anagrafica?->nome, 'tipologia' => $t->tipologia, 'quota' => (float) $t->quota, 'chiusa_il' => null,
            ])->values()->all());

        // Chi ha già una riga di versato su questa voce ma non è più titolare (ha venduto): resta nell'elenco con la
        // sua titolarità chiusa, così la sua riga si legge, si conserva e la quota lorda è quella della sua quota
        // storica — e l'acquirente può aggiungere la sua accanto (verifica S6, R15).
        foreach ($giaVersato as $immobileId => $righeVersato) {
            $presenti = collect($titolariPerImmobile->get($immobileId, []))->pluck('id')->all();
            foreach ($righeVersato as $cv) {
                if ($cv->anagrafica_id === null || in_array((int) $cv->anagrafica_id, $presenti, true)) {
                    continue;
                }
                $storica = \App\Models\TitolaritaImmobile::with('anagrafica:id,nome')
                    ->where('immobile_id', $immobileId)->where('anagrafica_id', $cv->anagrafica_id)
                    ->whereIn('tipologia', ['proprietario', 'nuda_proprietario', 'usufruttuario'])
                    ->orderByDesc('data_fine')->first();
                $elenco = $titolariPerImmobile->get($immobileId, []);
                $elenco[] = [
                    'id' => (int) $cv->anagrafica_id,
                    'nome' => $storica?->anagrafica?->nome ?? \App\Models\Anagrafica::whereKey($cv->anagrafica_id)->value('nome'),
                    'tipologia' => $storica?->tipologia ?? 'proprietario',
                    'quota' => (float) ($storica?->quota ?? 100),
                    'chiusa_il' => $storica?->data_fine?->toDateString(),
                ];
                $titolariPerImmobile->put($immobileId, $elenco);
                $presenti[] = (int) $cv->anagrafica_id;
            }
        }

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

        // Una riga per (unità, persona): le righe registrate come stanno; un'unità senza righe ne ha una,
        // con la persona già scelta se il proprietario in corso è uno solo (fatto, non deduzione), altrimenti
        // «l'unità» finché l'amministratore non sceglie. La quota lorda della persona è quella dell'unità
        // per la sua quota di possesso: è il tetto che il motore applica al suo versato.
        $righe = collect($immobili)->flatMap(function ($im) use ($lordi, $giaVersato, $titolariPerImmobile) {
            $lordoUnita = $lordi[$im['id']] ?? 0;
            $titolari = collect($titolariPerImmobile->get($im['id'], []));
            $rigaPer = function (?int $anagraficaId, ?ContributoVersato $cv) use ($im, $lordoUnita, $titolari) {
                $t = $anagraficaId ? $titolari->firstWhere('id', $anagraficaId) : null;
                $quotaPersona = $t ? (float) $t['quota'] : null;

                return [
                    'chiave'         => $im['id'] . ':' . ($anagraficaId ?? 0),
                    'immobile_id'    => $im['id'],
                    'anagrafica_id'  => $anagraficaId,
                    'nome'           => $im['nome'],
                    'interno'        => $im['interno'],
                    'millesimi'      => round($im['millesimi'], 2),
                    'quota_lorda'    => $quotaPersona !== null ? (int) round($lordoUnita * $quotaPersona / 100) : $lordoUnita,
                    'quota_lorda_unita' => $lordoUnita,
                    'gia_versato'    => (int) ($cv->importo_cents ?? 0),
                    'contributo_id'  => $cv?->id,
                ];
            };
            $esistenti = collect($giaVersato->get($im['id'], []));
            if ($esistenti->isNotEmpty()) {
                return $esistenti->map(fn ($cv) => $rigaPer($cv->anagrafica_id ? (int) $cv->anagrafica_id : null, $cv));
            }
            $proprietari = $titolari->where('tipologia', 'proprietario');

            return [$rigaPer($proprietari->count() === 1 ? (int) $proprietari->first()['id'] : null, null)];
        })->sortBy([['interno', 'asc'], ['anagrafica_id', 'asc']])->values();

        // La natura e la nota sono per-VOCE, non per-riga: tutte le righe di uno
        // stesso salvataggio le condividono (vedi update()). Una qualunque basta.
        $primaRiga           = $giaVersato->flatten()->first();
        $naturaCorrente      = $primaRiga?->natura ?? ContributoVersato::NATURA_FONDO_VINCOLATO;
        $descrizioneCorrente = $primaRiga?->descrizione;
        $liquiditaStato      = $primaRiga?->liquidita_stato;
        $cassaIdCorrente     = $primaRiga?->cassa_id;

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
            'titolari'           => $titolariPerImmobile,
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
            // B2, decisione 17: chi ha versato. Nullo = «l'unità» (la riga storica, D8); con la persona il motore
            // sconta solo il suo lordo. La persona deve essere titolare di diritto reale dell'unità, in corso oggi.
            'righe.*.anagrafica_id'  => ['nullable', 'integer', Rule::exists('anagrafiche', 'id')],
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

        // La persona di ogni riga è un titolare in corso dell'unità, e la coppia (unità, persona) è una sola.
        $oggi = DateHelper::oggiUtenteImmutable();
        $viste = [];
        foreach ($dati['righe'] as $i => $riga) {
            $chiave = $riga['immobile_id'] . ':' . ($riga['anagrafica_id'] ?? 0);
            if (isset($viste[$chiave])) {
                throw \Illuminate\Validation\ValidationException::withMessages(["righe.{$i}.anagrafica_id" => 'La stessa persona compare due volte sulla stessa unità.']);
            }
            $viste[$chiave] = true;
            // Una riga a zero non si scrive: non c'è niente da controllare (verifica S6, R15).
            if (! empty($riga['anagrafica_id']) && (int) $riga['gia_versato'] > 0) {
                $titolare = \App\Models\TitolaritaImmobile::where('immobile_id', $riga['immobile_id'])->where('anagrafica_id', $riga['anagrafica_id'])
                    ->whereIn('tipologia', ['proprietario', 'nuda_proprietario', 'usufruttuario'])->get()
                    ->first(fn ($t) => $t->inCorsoIl($oggi));
                // Chi ha venduto resta col suo versato: la riga esiste già a database ed è un fatto storico che si
                // conserva, non una dichiarazione nuova. Il motore la legge a suo nome (nettingGiaVersato: «il resto è
                // suo, non di chi gli è subentrato»). 422 solo per una persona mai titolare e mai registrata (R15).
                $storica = $titolare === null && ContributoVersato::where('target_type', Conto::class)->where('target_id', $conto->id)
                    ->where('immobile_id', $riga['immobile_id'])->where('anagrafica_id', $riga['anagrafica_id'])->exists();
                if ($titolare === null && ! $storica) {
                    throw \Illuminate\Validation\ValidationException::withMessages(["righe.{$i}.anagrafica_id" => 'Questa persona non è oggi titolare di diritto reale di questa unità, e non ha un versato già registrato su questa voce.']);
                }
            }
        }

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
                    'anagrafica_id'   => $riga['anagrafica_id'] ?? null,
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
