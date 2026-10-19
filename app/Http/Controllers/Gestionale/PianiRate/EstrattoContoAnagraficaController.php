<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Anagrafica;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RataQuote;
use App\Models\Saldo;
use App\Services\Gestionale\RiallineaCreditiService;
use App\Services\Riparto\CompetenzaDelPiano;
use App\Helpers\MoneyHelper;
use App\Models\Gestionale\Cassa;
use App\Services\Gestionale\CreditoService;
use App\Services\Gestionale\SaldoCassaService;
use App\Traits\HasEsercizio;
use App\Services\PDF\PdfService;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Illuminate\Http\Request;

class EstrattoContoAnagraficaController extends Controller
{
    use HasEsercizio;

    /**
     * Genera l'Estratto Conto finanziario di una singola Anagrafica (vista Inertia).
     * Costruisce un "Ledger" (Libro Mastro) cronologico in Dare/Avere con saldo progressivo
     * "Waterfall" al centesimo, basato sul pattern Double-Entry.
     */
    public function show(Request $request, Condominio $condominio, Anagrafica $anagrafica)
    {
        $esercizio = $this->getEsercizioCorrente($condominio);

        $anagrafica->load(['immobili' => function($q) use ($condominio) {
            $q->where('condominio_id', $condominio->id);
        }]);

        [$timeline, $stats] = $this->buildLedger($condominio, $esercizio, $anagrafica);

        return Inertia::render('gestionale/pianiRate/EstrattoContoAnagrafica', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'anagrafica' => $anagrafica,
            'timeline'   => $timeline,
            'stats'      => $stats,
            'rimborso'   => $this->datiRimborso($condominio, $anagrafica),
            // B2, S7: la solidarietà dell'art. 63 co. 4 verso chi è entrato — una nota, non una quota.
            'solidarieta' => app(\App\Services\Subentro\NotaSolidarieta::class)->per($condominio, $anagrafica),
            // Coda 167, beta.40: i versamenti fatti per il debito di un altro — un riquadro, non righe del libro.
            'versamenti_per_altri' => app(\App\Services\Gestionale\VersamentiPerContoDiAltri::class)->per($condominio, $anagrafica),
        ]);
    }

    /**
     * Ciò che il modulo «Rimborsa il credito» deve sapere (B2, S6): le quote a credito della persona in
     * questo condominio, ognuna con la sua origine (da saldi o da eccedenza: la contropartita cambia), e le
     * casse da cui il denaro può uscire, col saldo. Finché nessun piano ha assorbito la coppia del
     * passaggio, il credito di chi esce vive in `saldi` e qui non c'è una quota: il box lo dice.
     *
     * @return array{quote: list<array<string,mixed>>, casse: list<array<string,mixed>>, credito_in_saldi: int}
     */
    private function datiRimborso(Condominio $condominio, Anagrafica $anagrafica): array
    {
        $contoPassate = \App\Models\Gestionale\ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'passate_gestioni')->whereNull('deleted_at')->value('id');
        $quote = RataQuote::whereHas('rata.pianoRate', fn ($p) => $p->where('condominio_id', $condominio->id))
            ->where('anagrafica_id', $anagrafica->id)
            ->where(fn ($q) => $q->whereRaw('importo_pagato > importo')->orWhere('importo', '<', 0))
            ->with(['rata.pianoRate.gestione:id,nome', 'immobile:id,nome,interno'])
            ->get()
            ->filter(fn (RataQuote $q) => $q->credito_disponibile > 0)
            ->map(fn (RataQuote $q) => [
                'id'                   => $q->id,
                'rata'                 => $q->rata?->numero_rata,
                'piano'                => $q->rata?->pianoRate?->nome,
                'gestione'             => $q->rata?->pianoRate?->gestione?->nome,
                'immobile'             => $q->immobile ? $q->immobile->nome . ($q->immobile->interno ? " (Int. {$q->immobile->interno})" : '') : null,
                // Da dove viene il credito, letto dalle componenti e non dal segno (rilievo K2 della Fase 1-bis): un riporto da un
                // esercizio precedente («saldi»), la spesa negativa delle entrate dell'anno («entrate», una voce di tipo «entrata»),
                // tutte e due («misto»), o un versamento in eccesso («eccedenza»).
                'origine'              => self::origineDelCredito($q),
                // Da dove esce in contabilità (decisione 71): un credito riportato con la quota non ancora a giornale (la rata in
                // bozza, o una quota rimasta fuori dall'emissione) sta fra le «Passate gestioni»; quello già a giornale con la sua
                // quota, quello delle entrate e l'eccedenza fra i crediti verso condomini (`RataQuote::creditoSuPassate`). Con una
                // quota mista, «passate_gestioni» finché ne resta una parte lì.
                'conto'                => $q->creditoSuPassate($contoPassate === null ? null : (int) $contoPassate) > 0 ? 'passate_gestioni' : 'crediti_condomini',
                'a_giornale'           => $q->scrittura_contabile_id !== null,
                'credito_cents'        => (int) $q->credito_disponibile,
                'credito_formattato'   => MoneyHelper::format((int) $q->credito_disponibile),
                // Come stringa italiana: è il valore che il campo importo propone, e la conversione in
                // centesimi avviene una volta sola, nella Request.
                'credito_euro'         => number_format($q->credito_disponibile / 100, 2, ',', '.'),
            ])->values()->all();

        $saldoCassa = app(SaldoCassaService::class);
        $casse = Cassa::where('condominio_id', $condominio->id)
            ->whereIn('tipo', ['banca', 'contanti'])
            ->where('attiva', true)
            ->orderBy('nome')
            ->get()
            ->map(fn (Cassa $c) => [
                'id'               => $c->id,
                'nome'             => $c->nome,
                'tipo'             => $c->tipo,
                'saldo_cents'      => $saldoCassa->saldoDisponibile($c),
                'saldo_formattato' => MoneyHelper::format($saldoCassa->saldoDisponibile($c)),
            ])->values()->all();

        // La coppia del passaggio non ancora assorbita: credito vero, ma ancora in `saldi`. Dalla 1.11.0-beta.44 al netto per gestione e
        // unità, come la assorbirà il piano: con più coppie sulla stessa unità (gli eredi, i nudi) una persona può averne una a credito e
        // una a debito, e contare solo la prima gonfiava il credito. E senza le righe del defunto nelle successioni con l'arretrato agli
        // eredi (rilievo X10 della Fase 1-bis), né le righe dei passaggi precedenti che quell'arretrato ha letto (le sue fonti, G4 del giro
        // sulle correzioni): lì la posizione del defunto si chiude a zero per costruzione, e quel «credito» è già passato agli eredi. Con
        // l'arretrato a suo nome la coppia resta un credito vero; una successione annullata torna a contare tutto.
        $successioniDelDefunto = \App\Models\Gestionale\Subentro::where('condominio_id', $condominio->id)->where('tipo_passaggio', 'successione')
            ->where('anagrafica_uscente_id', $anagrafica->id)->get();
        $successioniChiuse = $successioniDelDefunto->filter(fn ($s) => $s->arretratoAgliEredi());
        // 1.11.0-beta.53 (Coda 244): anche i saldi del Wallet legati a un passaggio, lo stesso credito della coppia. Non quelli del defunto
        // legati alla sua successione con l'arretrato agli eredi (la scelta, anche quando l'arretrato non ha scritto righe): pareggiano la
        // quota che il piano calcola ancora per lui, e non diventano una quota da rimborsare.
        $legateAlDefunto = $successioniDelDefunto->filter(fn ($s) => ($s->capo()->registro['arretrato']['scelta'] ?? null) === \App\Models\Gestionale\Subentro::ARRETRATO_AGLI_EREDI)
            ->map(fn ($s) => (int) $s->capo()->id)->unique()->values()->all();
        $creditoInSaldi = (int) $anagrafica->saldi()
            ->where('condominio_id', $condominio->id)
            ->where('is_applicato', false)
            ->where(fn ($q) => $q->where(fn ($c) => $c->whereNotNull('subentro_id')->whereNotIn('subentro_id', $successioniChiuse->pluck('id')->all()))
                ->orWhere(fn ($l) => $l->whereNotNull('passaggio_id')->whereNotIn('passaggio_id', $legateAlDefunto)))
            ->whereNotIn('id', $successioniChiuse->flatMap(fn ($s) => $s->fontiDellArretrato())->all())
            ->get(['gestione_id', 'immobile_id', 'saldo_iniziale'])
            ->groupBy(fn ($r) => $r->gestione_id . '|' . $r->immobile_id)
            ->map(fn ($g) => (int) $g->sum('saldo_iniziale'))
            ->filter(fn (int $netto) => $netto < 0)
            ->sum();

        return ['quote' => $quote, 'casse' => $casse, 'credito_in_saldi' => abs($creditoInSaldi)];
    }

    /** @return 'saldi'|'entrate'|'misto'|'eccedenza' */
    private static function origineDelCredito(RataQuote $q): string
    {
        if ((int) $q->importo >= 0) {
            return 'eccedenza';
        }
        ['spesa' => $spesa, 'riporto' => $riporto] = $q->componenti();

        return match (true) {
            $riporto < 0 && $spesa < 0 => 'misto',
            $riporto < 0               => 'saldi',
            default                    => 'entrate',
        };
    }

    /**
     * Genera il PDF dell'Estratto Conto di una singola Anagrafica.
     * Aperto in una nuova scheda dal frontend tramite window.open().
     */
    public function print(Request $request, Condominio $condominio, Anagrafica $anagrafica, PdfService $pdfService)
    {
        $esercizio = $this->getEsercizioCorrente($condominio);

        $anagrafica->load(['immobili' => function($q) use ($condominio) {
            $q->where('condominio_id', $condominio->id);
        }]);

        [$timeline, $stats, $saldoInizialeCents] = $this->buildLedger($condominio, $esercizio, $anagrafica);

        $data = [
            'condominio'         => $condominio,
            'esercizio'          => $esercizio,
            'anagrafica'         => $anagrafica,
            'timeline'           => $timeline,
            'stats'              => $stats,
            'saldoInizialeCents' => $saldoInizialeCents,
            'solidarieta'        => app(\App\Services\Subentro\NotaSolidarieta::class)->per($condominio, $anagrafica),
            'versamentiPerAltri' => app(\App\Services\Gestionale\VersamentiPerContoDiAltri::class)->per($condominio, $anagrafica),
        ];

        $mpdf = $pdfService->generate('pdf.gestionale.estratto_conto_anagrafica', $data, [
            'orientation' => 'P',
            'margin_top'  => 30,
        ]);

        $mpdf->SetHeader($condominio->nome . '||Estratto Conto – ' . $anagrafica->nome);

        // ⚠️ `Output(..., 'I')` scrive il PDF sull'output buffer e torna vuota: mPDF mandava da sé,
        // con `header()` ed `echo`, intestazioni e corpo, e nella risposta Laravel non passava nulla —
        // nessun test la guardava (beta.28, Coda 151; stessa cura del Libro Giornale e delle stampe
        // del piano rate). Il nome era «EC_Nome_Cognome_Esercizio anno 2026.pdf», spazi compresi;
        // ora ha la forma della casa: libro, condominio, anno, e l'anagrafica in coda.
        $nomeFile = PdfService::nomeFile('estratto-conto', $condominio->nome, $esercizio->data_inizio?->format('Y'), Str::slug($anagrafica->nome));

        return response($mpdf->Output($nomeFile, \Mpdf\Output\Destination::STRING_RETURN))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$nomeFile.'"');
    }

    // -------------------------------------------------------------------------
    // HELPER PRIVATO — Logica condivisa tra show() e print()
    // -------------------------------------------------------------------------

    /** Il tipo della scrittura di una riga, come stringa (`tipo_movimento` è un Backed Enum dalla v1.9.1). */
    private static function tipoDi($riga): ?string
    {
        $tipo = $riga->scrittura->tipo_movimento ?? null;

        return $tipo instanceof \BackedEnum ? $tipo->value : $tipo;
    }

    /**
     * Il saldo iniziale della persona nell'esercizio (DC3, 1.11.0-beta.49, voce P5).
     *
     * Il pregresso che la persona paga sta nelle sue quote (`saldo_usato` del dettaglio del calcolo), anche la parte del saldo
     * dell'unità (art. 63, un saldo senza persona) che il generatore le ha ripartito e che dai suoi saldi non si vede: fino alla
     * beta.48 l'estratto sommava i soli saldi intestati a lei, e il pregresso dell'unità non entrava nell'estratto di chi lo paga.
     * I piani sono quelli dell'esercizio, anche quando l'esercizio si deduce (`esercizio_id` nullo, piani di prima della
     * migrazione 9). A questi si aggiungono i suoi saldi che nessun piano ha assorbito: i pregressi non applicati, le coppie di
     * conguaglio dei passaggi.
     *
     * Senza contare due volte i saldi della 1.7.x e della 1.8.x, che la migrazione del 01/03/2026 ha lasciato con `is_applicato`
     * falso anche se un piano li aveva assorbiti ({@see saldoGiaNelleQuote()}).
     *
     * I saldi di questo esercizio che ha assorbito il piano di un altro esercizio (rilievo K1 della Fase 1-bis): la gestione
     * ordinaria è una sola, riusata ogni anno, e il generatore prende ogni saldo libero della gestione, senza guardare l'esercizio.
     * Il riporto di quel piano sta nel saldo iniziale dell'esercizio dei saldi che ha assorbito, e le sue righe pesano la sola
     * spesa, come per i piani dell'esercizio ({@see pianiDellaPagina()}). Senza, il saldo spariva dall'estratto dell'esercizio di
     * partenza dopo la riapertura di un esercizio chiuso, o con due esercizi aperti come li crea l'importazione.
     */
    private function saldoIniziale(Condominio $condominio, Esercizio $esercizio, Anagrafica $anagrafica): int
    {
        $piani = $this->pianiDellaPagina($condominio, $esercizio, $anagrafica);
        $quote = $piani->isEmpty() ? collect() : RataQuote::where('anagrafica_id', $anagrafica->id)
            ->whereHas('rata', fn ($r) => $r->whereIn('piano_rate_id', $piani->pluck('id')))
            ->with('rata:id,piano_rate_id,numero_rata')
            ->get();

        $riportoNelleQuote = (int) $quote->sum(function (RataQuote $q) {
            if (! $q->componenti()['dettaglio']) {
                return 0; // senza dettaglio il riporto è dentro l'importo delle righe dell'emissione, che pesano per intero
            }
            $regole = is_string($q->regole_calcolo) ? json_decode($q->regole_calcolo, true) : $q->regole_calcolo;
            $importi = (array) ($regole['importi'] ?? []);

            return (int) (array_key_exists('saldo_usato', $importi) ? $importi['saldo_usato'] : $q->componenti()['riporto']);
        });

        $nate = $piani->isEmpty() ? collect() : \Illuminate\Support\Facades\DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
            ->whereIn('rate.piano_rate_id', $piani->pluck('id'))
            ->groupBy('rate.piano_rate_id')->selectRaw('rate.piano_rate_id, MIN(rate_quote.created_at) as nate')->pluck('nate', 'piano_rate_id');

        $liberi = $anagrafica->saldi()
            ->where('condominio_id', $condominio->id)
            ->where('esercizio_id', $esercizio->id)
            ->where('is_applicato', false)
            ->get()
            ->reject(fn (Saldo $saldo) => $this->saldoGiaNelleQuote($saldo, $piani, $nate, $quote, (int) $esercizio->id))
            ->sum('saldo_iniziale');

        return (int) $liberi + $riportoNelleQuote;
    }

    /**
     * I piani le cui quote, per questa persona, hanno il riporto nel saldo iniziale della pagina e pesano nelle righe la sola spesa:
     * i piani dell'esercizio, anche quando l'esercizio si deduce (`esercizio_id` nullo, piani di prima della migrazione 9), e i
     * piani di un altro esercizio che hanno assorbito saldi di questo esercizio, della persona o della sua unità (rilievo K1).
     * Le righe degli altri piani — quelli degli esercizi prima e dopo, i cui movimenti l'estratto elenca comunque — pesano
     * l'importo contabile intero, riporto compreso, perché il saldo iniziale della pagina quel riporto non lo contiene (rilievo K5).
     *
     * @return \Illuminate\Support\Collection<int, PianoRate>
     */
    private function pianiDellaPagina(Condominio $condominio, Esercizio $esercizio, Anagrafica $anagrafica): \Illuminate\Support\Collection
    {
        $competenza = app(CompetenzaDelPiano::class);
        $piani = PianoRate::where('condominio_id', $condominio->id)
            ->where(fn ($q) => $q->where('esercizio_id', $esercizio->id)->orWhereNull('esercizio_id'))
            ->get()
            ->filter(fn (PianoRate $p) => $p->esercizio_id !== null || (int) $competenza->esercizioDelPiano($p)?->id === (int) $esercizio->id)
            ->values();
        $assorbitori = Saldo::where('condominio_id', $condominio->id)
            ->where('esercizio_id', $esercizio->id)
            ->whereNotNull('piano_rate_id')
            ->whereNotIn('piano_rate_id', $piani->pluck('id')->all())
            ->where(fn ($w) => $w->where('anagrafica_id', $anagrafica->id)->orWhereNull('anagrafica_id'))
            ->distinct()
            ->pluck('piano_rate_id');

        return $assorbitori->isEmpty() ? $piani
            : $piani->merge(PianoRate::where('condominio_id', $condominio->id)->whereIn('id', $assorbitori)->get())->values();
    }

    /**
     * Un saldo con `is_applicato` falso che un piano dell'esercizio ha comunque assorbito (`PianoRate::saldoAssorbito`): i saldi
     * della 1.7.x e della 1.8.x dopo la migrazione del 01/03/2026. Con il dettaglio del calcolo il riporto è nelle quote della
     * persona su quell'unità (`saldo_usato`), e il saldo non si somma una seconda volta; se quelle quote non portano riporto il
     * piano non l'ha assorbito, e conta. Senza il dettaglio (1.7.x) il riporto è dentro l'importo della prima rata della persona
     * sull'unità (la rata zero, o la prima): finché quella rata non è a giornale il saldo conta, dopo pesa la riga dell'emissione.
     *
     * Limite dichiarato: con il metodo «tutte le rate» e i dati della 1.7.x il riporto è sparso su ogni rata, e una volta a
     * giornale la prima l'estratto conta il riporto delle sole rate emesse. Non si indovina la parte di ogni rata.
     *
     * @param \Illuminate\Support\Collection<int, PianoRate> $piani
     * @param \Illuminate\Support\Collection<int|string, string> $nate
     * @param \Illuminate\Support\Collection<int, RataQuote> $quote
     */
    private function saldoGiaNelleQuote(Saldo $saldo, \Illuminate\Support\Collection $piani, \Illuminate\Support\Collection $nate, \Illuminate\Support\Collection $quote, int $esercizioId): bool
    {
        foreach ($piani as $piano) {
            // Un piano prende i saldi della sua gestione (`GenerateSaldiAction`): il saldo di un'altra gestione dello stesso esercizio,
            // ancora libero, non è nelle sue quote anche se è nato prima.
            if (! PianoRate::saldoAssorbito($saldo, (int) $piano->id, $esercizioId, $nate[$piano->id] ?? null)
                || ($saldo->gestione_id !== null && (int) $saldo->gestione_id !== (int) $piano->gestione_id)) {
                continue;
            }
            $sue = $quote->filter(fn (RataQuote $q) => (int) $q->rata?->piano_rate_id === (int) $piano->id
                && ($saldo->immobile_id === null || (int) $q->immobile_id === (int) $saldo->immobile_id));
            if ($sue->isEmpty()) {
                continue;
            }
            if ($sue->contains(fn (RataQuote $q) => $q->componenti()['dettaglio'])) {
                if ($sue->sum(fn (RataQuote $q) => $q->componenti()['riporto']) !== 0) {
                    return true;
                }
                continue;
            }
            if ($sue->sortBy(fn (RataQuote $q) => (int) $q->rata?->numero_rata)->first()->scrittura_contabile_id !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * La parte in AVERE di una quota a giornale che l'estratto pesa nelle righe (rilievo K3): la spesa negativa delle entrate
     * dell'anno, quando la quota non ha una sua riga in DARE che la pesi già (`quotaPura`). Zero per ogni altra quota.
     */
    private static function entrateSenzaDare(RataQuote $q): int
    {
        if ((int) $q->importo > 0 || ! $q->componenti()['dettaglio']) {
            return 0;
        }
        ['spesa' => $spesa, 'riporto' => $riporto] = $q->componenti();

        return $spesa < 0 && $riporto <= 0 ? -$spesa : 0;
    }

    /**
     * Le rettifiche del riallineamento dei crediti e i loro storni, riconosciuti dalla struttura (rilievo S1): R è una rettifica
     * figlia di una compensazione (`storno_credito`) la cui causale comincia con il marcatore; il suo storno è una rettifica figlia
     * di una R. Le sole rettifiche figlie di una compensazione sono il suo storno («Storno: …») e la R del riallineamento.
     *
     * @return list<int>
     */
    private function rettificheDelRiallineamento(Condominio $condominio): array
    {
        $r = \App\Models\Gestionale\ScritturaContabile::where('condominio_id', $condominio->id)
            ->where('tipo_movimento', 'rettifica')
            ->where('causale', 'like', RiallineaCreditiService::MARCATORE . '%')
            ->whereHas('padre', fn ($p) => $p->where('tipo_movimento', 'storno_credito'))
            ->pluck('id');
        if ($r->isEmpty()) {
            return [];
        }

        return $r->merge(\App\Models\Gestionale\ScritturaContabile::where('tipo_movimento', 'rettifica')->whereIn('scrittura_padre_id', $r)->pluck('id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Costruisce la timeline (ledger) e le statistiche per una anagrafica.
     * Restituisce [$timeline, $stats, $saldoInizialeCents].
     */
    private function buildLedger(Condominio $condominio, Esercizio $esercizio, Anagrafica $anagrafica): array
    {
        // Calcolo Saldo Iniziale (Crediti/Debiti portati dall'anno precedente): vedi saldoIniziale()
        $saldoInizialeCents = $this->saldoIniziale($condominio, $esercizio, $anagrafica);

        // I piani le cui righe pesano la sola spesa, con il riporto nel saldo iniziale (rilievi K1 e K5): vedi pianiDellaPagina().
        $pianiPagina = $this->pianiDellaPagina($condominio, $esercizio, $anagrafica)->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Recupero tutti i movimenti dell'anagrafica escludendo i movimenti fittizi di cassa interna.
        // Fuori anche le rettifiche del riallineamento dei crediti e i loro storni (1.11.0-beta.49, P9): spostano un credito già
        // usato da «Crediti verso condomini» a «Passate gestioni» a nome della persona, e sulla sua posizione pesano zero; lette
        // come righe sarebbero uno storno mai avvenuto («Ripristino credito nel salvadanaio»), con i totali gonfiati. Si
        // riconoscono dalla struttura, come le riconosce lo storno (`StornoIncassoRateAction::famiglia`), non cercando il marcatore
        // dentro la causale (rilievo S1 della Fase 1-bis): la causale di un incasso è la sua descrizione, quella di un rimborso
        // contiene il nome dell'anagrafica, e lo storno la ricopia; con il marcatore nel testo libero spariva lo storno vero.
        $movimenti = $anagrafica->movimenti()
            ->whereHas('scrittura', fn ($q) => $q->where('condominio_id', $condominio->id))
            ->whereNotIn('scrittura_id', $this->rettificheDelRiallineamento($condominio))
            ->whereNull('cassa_id')
            ->with(['scrittura.gestione', 'scrittura.padre:id,tipo_movimento,stato', 'rata', 'immobile']) 
            ->orderBy('created_at', 'asc') 
            ->orderBy('id', 'asc')
            ->get();

        // --- STEP 1: PRE-CARICAMENTO QUOTE (singola query per prevenire N+1) ---
        // Anche le righe in AVERE dell'emissione: il credito riportato di una quota a credito (decisione 71), che si riconosce
        // dalla sua quota (più sotto).
        $rataIds = $movimenti
            ->filter(fn($r) => $r->rata && ($r->tipo_riga === 'dare' || self::tipoDi($r) === 'emissione_rata'))
            ->pluck('rata.id')
            ->unique()
            ->values();

        // Più di una quota per (rata, unità) della stessa persona è possibile dalla 1.11.0-beta.34: chi compra da un
        // comproprietario ha la sua quota e quella passata con la vendita (decisione 25). Un `keyBy` ne teneva una sola e
        // la applicava a tutte e due le righe dell'emissione (Fase 1-bis, R3: € 40,00 in meno in un estratto conto).
        $quoteMap = RataQuote::whereIn('rata_id', $rataIds)
            ->where('anagrafica_id', $anagrafica->id)
            ->orderBy('id')
            ->get()
            ->groupBy(fn($q) => $q->rata_id . '_' . $q->immobile_id);
        $quoteUsate = [];

        // --- STEP 2: PRE-ELABORAZIONE QUOTE PURE E SALDI USATI ---
        // Solo per le righe dei piani della pagina (rilievo K5): una riga d'emissione di un altro esercizio pesa l'importo
        // contabile intero, perché il suo riporto non è nel saldo iniziale della pagina.
        $movimenti->each(function ($riga) use ($quoteMap, &$quoteUsate, $pianiPagina) {
            $riga->quotaPura       = $riga->importo;
            $riga->saldoUsato      = 0;
            $riga->totaleRichiesto = $riga->importo;
            $riga->quotaRecord     = null;
            $dellaPagina = $riga->rata && in_array((int) $riga->rata->piano_rate_id, $pianiPagina, true);

            if ($riga->rata && $riga->tipo_riga === 'dare') {
                $key    = $riga->rata->id . '_' . $riga->immobile_id;
                $gruppo = $quoteMap->get($key) ?? collect();
                $tipoMov = $riga->scrittura->tipo_movimento ?? null;
                $tipoMov = $tipoMov instanceof \BackedEnum ? $tipoMov->value : $tipoMov;
                if ($tipoMov === 'emissione_rata' && $gruppo->count() > 1) {
                    // Una riga di emissione in DARE per quota: si abbina la quota di quella scrittura che l'ha scritta, poi
                    // un'altra quota di quella scrittura, poi una qualsiasi non ancora usata, e se non ne restano la prima. Le
                    // altre righe (rettifiche, storni) pesano l'importo contabile e non consumano quote. L'importo della riga è
                    // quello che l'emissione scrive per la quota (`RataQuote::dareDiEmissione`): l'importo di una quota a
                    // debito, la sola spesa di una quota a credito (decisione 71). Abbinata all'importo della quota, la spesa di
                    // una quota a credito finiva sulla sua gemella del pregresso (decisione 25), e pesava zero.
                    $quota = $gruppo->first(fn ($q) => ! isset($quoteUsate[$q->id]) && (int) $q->scrittura_contabile_id === (int) $riga->scrittura_id && $q->dareDiEmissione() === (int) $riga->importo)
                        ?? $gruppo->first(fn ($q) => ! isset($quoteUsate[$q->id]) && (int) $q->scrittura_contabile_id === (int) $riga->scrittura_id)
                        ?? $gruppo->first(fn ($q) => ! isset($quoteUsate[$q->id]))
                        ?? $gruppo->first();
                    $quoteUsate[$quota->id] = true;
                } else {
                    $quota = $gruppo->first();
                }

                if ($quota) {
                    $riga->quotaRecord = $quota;
                }
                if ($quota && $dellaPagina) {
                    $regole = $quota->regole_calcolo;

                    // Decodifica JSON Snapshot per isolare quota reale dal saldo compensato
                    if (!empty($regole)) {
                        $data = (array) $regole;
                        $importi = $data['importi'] ?? [];
                        $riga->saldoUsato      = $importi['saldo_usato'] ?? 0;
                        $riga->quotaPura       = $importi['quota_pura_gestione'] ?? ($riga->importo - $riga->saldoUsato);
                        $riga->totaleRichiesto = $importi['totale_calcolato'] ?? $riga->importo;
                    }
                }
            }
        });

        // Le righe in AVERE dell'emissione (decisione 71): il credito riportato di una quota a credito, la spesa negativa di una quota
        // delle entrate dell'anno (una voce di tipo «entrata», rilievo K3 della Fase 1-bis), e quello che la figlia di un'emissione
        // riporta fra i crediti dopo uno storno (`StornoIncassoRateAction`). La regola: la spesa di una quota pesa una volta sola, il
        // riporto sta nel saldo iniziale.
        // - Il credito riportato di una quota dei piani della pagina è già nel saldo iniziale (`saldo_usato` della quota) e la riga in
        //   DARE della stessa quota lo dice («Scontata da credito pregresso»): la riga non pesa e non si mostra. Era la riga fantasma
        //   «A copertura Rata n.1». Così anche la figlia di uno storno.
        // - Se la quota ha una sua riga in DARE, quella pesa già la spesa (`quotaPura`, anche negativa): le righe in AVERE si scartano.
        //   Se non ce l'ha (spesa negativa e riporto non positivo), resta una sola riga in AVERE, la prima (`righeDiEmissione` scrive
        //   la spesa prima del riporto), e pesa la spesa negativa: è una quota delle entrate dell'anno, non un credito riportato.
        // - Una quota senza il dettaglio del calcolo tiene la sua riga: il riporto non è nel saldo iniziale.
        // - Le righe dei piani di un altro esercizio restano tutte e pesano l'importo contabile intero (rilievo K5): il saldo iniziale
        //   della pagina quel riporto non lo contiene.
        $primaAvere = [];
        $movimenti = $movimenti->reject(function ($riga) use ($quoteMap, $pianiPagina, &$primaAvere) {
            if ($riga->tipo_riga !== 'avere' || self::tipoDi($riga) !== 'emissione_rata' || ! $riga->rata) {
                return false;
            }
            $chiave = $riga->scrittura_id . '_' . $riga->rata->id . '_' . $riga->immobile_id;
            $prima = ! isset($primaAvere[$chiave]);
            $primaAvere[$chiave] = true;
            $quote = ($quoteMap->get($riga->rata->id . '_' . $riga->immobile_id) ?? collect())
                ->filter(fn ($q) => (int) $q->scrittura_contabile_id === (int) $riga->scrittura_id);
            $entrate = (int) $quote->sum(fn (RataQuote $q) => self::entrateSenzaDare($q));
            $riga->entrateDellAnno = $prima && $entrate > 0;

            if (! in_array((int) $riga->rata->piano_rate_id, $pianiPagina, true)) {
                return false;
            }
            if ($quote->isEmpty()) {
                return true;
            }
            if ($quote->contains(fn (RataQuote $q) => ! $q->componenti()['dettaglio'])) {
                return false;
            }
            if (! $riga->entrateDellAnno) {
                return true;
            }
            $riga->importoNelLedger = $entrate;

            return false;
        })->values();

        // --- STEP 3: ORDINAMENTO VISIVO INTELLIGENTE ---
        $movimenti = $movimenti->sortBy(function($riga) {
            // 1. Raggruppamento per scrittura contabile originaria
            // 2. Dare (0) precede Avere (1) per garantire un flusso visivo lineare del Saldo Progressivo
            // 3. A parità di riga, ordina per importo decrescente
            $ordineRiga = $riga->tipo_riga === 'dare' ? 0 : 1; 
            return [$riga->scrittura_id, $ordineRiga, -abs($riga->quotaPura)];
        })->values();

        // --- STEP 4: CREAZIONE TIMELINE & CALCOLO SALDI PROGRESSIVI ---
        $runningBalance = $saldoInizialeCents;
        
        $timeline = $movimenti->map(function ($riga) use (&$runningBalance) {
            
            $importoContabile = $riga->importoNelLedger ?? $riga->importo;
            $quotaPura        = $riga->quotaPura;
            $saldoUsato       = $riga->saldoUsato;
            $totaleRichiesto  = $riga->totaleRichiesto;
            $quota            = $riga->quotaRecord;
            
            $waterfallStart = $runningBalance;
            $tipoMovimento = $riga->scrittura->tipo_movimento ?? 'generico';
            // ⚠️ `tipo_movimento` è un Backed Enum dalla v1.9.1: confrontarlo con `=== 'rettifica'` era sempre
            // falso, e i rami «storno incasso» e «prelievo dal salvadanaio» qui sotto non si accendevano mai —
            // la rettifica in DARE pesava la quota pura invece dell'importo stornato, senza etichetta né icona.
            // Trovato costruendo il ramo del rimborso (1.11.0-beta.31, B2 S6): si confronta il valore.
            if ($tipoMovimento instanceof \BackedEnum) {
                $tipoMovimento = $tipoMovimento->value;
            }
            
            // CALCOLO DARE E AVERE PROGRESSIVI (Motore Partita Doppia)
            if ($riga->tipo_riga === 'dare') {
                
                // Le rettifiche (storni) e gli utilizzi credito (salvadanaio) in Dare 
                // re-incrementano fisicamente il debito globale.
                // Il rimborso del credito (B2, S6) pesa l'importo contabile, come la rettifica: con `quotaPura`
                // la quota a credito della rata zero vale 0, e il saldo resterebbe «a credito» dopo il bonifico.
                if ($tipoMovimento === 'rettifica' || $tipoMovimento === 'storno_credito' || $tipoMovimento === 'rimborso_condomino') {
                    $importoDaSommare = $importoContabile;
                } else {
                    // Emissione standard (ignora i crediti figurativi)
                    $importoDaSommare = $quotaPura;
                }

                $runningBalance += $importoDaSommare;
                $dare  = $importoDaSommare; 
                $avere = 0;

            } else {
                // Gli incassi in contanti, compensazioni e i rimborsi abbattono il debito.
                $runningBalance -= $importoContabile;
                $dare  = 0; 
                $avere = $importoContabile;
            }
            
            $waterfallEnd = $runningBalance;

            // ASSEGNAZIONE ICONE VISIVE
            $icona = 'file'; 
            if ($tipoMovimento === 'emissione_rata') $icona = 'bill';
            if ($tipoMovimento === 'incasso_rata')   $icona = 'payment';
            if ($tipoMovimento === 'saldo_iniziale') $icona = 'landmark';
            // Lo storno (rettifica) e il prelievo dal salvadanaio (storno_credito) sono due fatti diversi: il primo
            // annulla, il secondo compensa. Fino alla beta.31 finivano nella stessa icona — e l'importo del prelievo
            // compariva barrato come fosse annullato (verifica S6, R18).
            if ($tipoMovimento === 'rettifica') $icona = 'rotate-ccw';
            if ($tipoMovimento === 'storno_credito') $icona = 'coins';
            if ($tipoMovimento === 'rimborso_condomino') $icona = 'banknote';
            
            $dettagli  = [];
            $breakdown = null;

            if ($riga->rata) {
    
                $labelBase = "Rata" . ($riga->rata->numero_rata ? " n.{$riga->rata->numero_rata}" : "");
                if ($riga->rata->data_scadenza) {
                    $labelBase .= " (Scad. " . $riga->rata->data_scadenza->format('d/m/Y') . ")";
                }

                // GESTIONE DESCRIZIONI E BREAKDOWN DELLE SINGOLE VOCI
                if ($riga->tipo_riga === 'dare') {
                    
                    // CASO A: È UNO STORNO O ANNULLAMENTO IN DARE (Ripristino Debito)
                    if ($tipoMovimento === 'rettifica') {
                        $dettagli[] = [
                            'type'   => 'rata',
                            'text'   => "Storno incasso su " . $labelBase,
                            'status' => 'stornata' // Badge grigio visivo
                        ];
                        $breakdown = [
                            'type'             => 'storno',
                            'start'            => MoneyHelper::fromCents($waterfallStart),
                            'cost'             => MoneyHelper::fromCents($importoContabile), 
                            'end'              => MoneyHelper::fromCents($waterfallEnd),
                            'immobile'         => $riga->immobile ? $riga->immobile->interno : 'Generico'
                        ];
                    }
                    // CASO B-bis: RIMBORSO DEL CREDITO (B2, S6) — denaro uscito dalla cassa a chi aveva il credito
                    elseif ($tipoMovimento === 'rimborso_condomino') {
                        $dettagli[] = [
                            'type'   => 'info',
                            'text'   => 'Rimborso del credito' . ($riga->scrittura->stato === 'annullata' ? ' (stornato)' : '') . ' — ' . $labelBase,
                            'status' => null,
                        ];
                        $breakdown = [
                            'type'             => 'rimborso',
                            'start'            => MoneyHelper::fromCents($waterfallStart),
                            'cost'             => MoneyHelper::fromCents($importoContabile),
                            'end'              => MoneyHelper::fromCents($waterfallEnd),
                            'immobile'         => $riga->immobile ? $riga->immobile->interno : 'Generico'
                        ];
                    }
                    // CASO B: PRELIEVO DAL SALVADANAIO
                    elseif ($tipoMovimento === 'storno_credito') {
                        $dettagli[] = [
                            'type'   => 'info',
                            'text'   => "Prelievo da salvadanaio per compensazione",
                            'status' => null
                        ];
                        $breakdown = [
                            'type'             => 'compensazione', 
                            'start'            => MoneyHelper::fromCents($waterfallStart),
                            'cost'             => MoneyHelper::fromCents($importoContabile), 
                            'end'              => MoneyHelper::fromCents($waterfallEnd),
                            'immobile'         => 'Generico'
                        ];
                    }
                    // CASO C: EMISSIONE STANDARD
                    else {
                        $statoRata = $quota ? $quota->stato : ($waterfallEnd <= 0 ? 'credito' : 'da_pagare');
                        
                        if ($saldoUsato > 0) {
                            $labelBase .= " + Recupero debito";
                        } elseif ($saldoUsato < 0) {
                            $labelBase .= " (Sconto da credito)";
                        }

                        $dettagli[] = [
                            'type'   => 'rata',
                            'text'   => $labelBase,
                            'status' => $statoRata 
                        ];

                        if ($saldoUsato != 0) {
                            if ($saldoUsato > 0) {
                                $dettagli[] = ['type' => 'info', 'text' => "Include recupero debito pregresso: " . MoneyHelper::format($saldoUsato), 'status' => null];
                                $dettagli[] = ['type' => 'info', 'text' => "Totale richiesto per questa rata: " . MoneyHelper::format($totaleRichiesto), 'status' => null];
                            } else {
                                $dettagli[] = ['type' => 'info', 'text' => "Scontata da credito pregresso: " . MoneyHelper::format(abs($saldoUsato)), 'status' => null];
                                $dettagli[] = ['type' => 'info', 'text' => "Valore originale della spesa: " . MoneyHelper::format($quotaPura), 'status' => null];
                            }
                        }

                        $breakdown = [
                            'type'             => 'emissione',
                            'start'            => MoneyHelper::fromCents($waterfallStart),
                            'cost'             => MoneyHelper::fromCents($quotaPura),
                            'totale_richiesto' => MoneyHelper::fromCents($totaleRichiesto),
                            'saldo_usato'      => $saldoUsato != 0 ? MoneyHelper::fromCents($saldoUsato) : null,
                            'end'              => MoneyHelper::fromCents($waterfallEnd),
                            'immobile'         => $riga->immobile ? $riga->immobile->interno : 'Generico'
                        ];
                    }

                } else {
                    // CASO D: AVERE (Incassi, Compensazioni, e RESTITUZIONE CREDITI DA STORNI)
                    
                    if ($tipoMovimento === 'rettifica') {
                        // FIX CHIRURGICO: Identifica le scritture in AVERE generate da uno Storno.
                        // B2, S6: se lo storno è di un rimborso, lo si dice — il credito torna sulla quota.
                        $padreRimborso = ($riga->scrittura->padre?->tipo_movimento?->value ?? null) === 'rimborso_condomino';
                        $dettagli[] = [
                            'type'   => 'rata',
                            'text'   => $padreRimborso ? "Storno del rimborso: il credito torna disponibile" : "Ripristino credito nel salvadanaio",
                            'status' => 'stornata' 
                        ];
                    } elseif ($tipoMovimento === 'storno_credito') {
                        $dettagli[] = [
                            'type'   => 'rata',
                            'text'   => "Pagamento tramite credito su " . $labelBase,
                            // Decisione 75.5 (1.11.0-beta.49): la compensazione stornata resta nell'elenco, con il bollino, ma non nei totali.
                            'status' => $riga->scrittura->stato === 'annullata' ? 'stornata' : null
                        ];
                    } elseif ($tipoMovimento === 'emissione_rata') {
                        // Arriva qui la riga in AVERE di una quota delle entrate dell'anno (rilievo K3), il credito riportato di una
                        // quota senza il dettaglio del calcolo, o una riga di un piano di un altro esercizio (vedi sopra).
                        $dettagli[] = [
                            'type'   => 'rata',
                            'text'   => ($riga->entrateDellAnno ? "Quota delle entrate dell'anno su " : "Credito riportato da esercizi precedenti su ") . $labelBase,
                            'status' => null
                        ];
                    } else {
                        $dettagli[] = [
                            'type'   => 'rata',
                            'text'   => "A copertura " . $labelBase,
                            // Decisione 75.5 (1.11.0-beta.49): l'incasso stornato resta nell'elenco, con il bollino, ma non nei totali.
                            'status' => $riga->scrittura->stato === 'annullata' ? 'stornata' : null
                        ];
                    }

                    $breakdown = [
                        'type'     => 'incasso',
                        'start'    => MoneyHelper::fromCents($waterfallStart),
                        'cost'     => MoneyHelper::fromCents($importoContabile),
                        'end'      => MoneyHelper::fromCents($waterfallEnd),
                        'immobile' => $riga->immobile ? $riga->immobile->interno : 'Generico'
                    ];
                }
            }

            if ($riga->immobile) {
                $dettagli[] = [
                    'type'   => 'immobile',
                    'text'   => $riga->immobile->nome . ($riga->immobile->interno ? " (Int. {$riga->immobile->interno})" : ""),
                    'status' => null
                ];
            }

            return [
                'id'          => $riga->id,
                'data'        => $riga->scrittura->data_registrazione ? $riga->scrittura->data_registrazione->format('d/m/Y') : '-',
                'protocollo'  => $riga->scrittura->numero_protocollo,
                'descrizione' => $riga->scrittura->causale ?: 'Movimento Contabile',
                'gestione'    => $riga->scrittura->gestione ? $riga->scrittura->gestione->nome : null,
                'dettagli'    => $dettagli, 
                'note'        => $riga->note, 
                'tipo_icona'  => $icona, 
                'dare'        => $dare, 
                'avere'       => $avere,
                'saldo'       => $waterfallEnd, 
                'breakdown'   => $breakdown,
                // B2, S6: il rimborso si storna dalla sua riga (stessa strada degli incassi).
                'scrittura_id'    => $riga->scrittura_id,
                'tipo_movimento'  => $tipoMovimento,
                'stato_scrittura' => $riga->scrittura->stato,
                'stornabile'      => ($tipoMovimento === 'rimborso_condomino') && $riga->scrittura->stato !== 'annullata',
                // La rettifica di uno storno (la scrittura madre è annullata): fuori dai totali, come la madre (decisione 75.5).
                'storna_annullata' => $tipoMovimento === 'rettifica' && $riga->scrittura->padre?->stato === 'annullata',
            ];
        });

        // Credito disponibile (saldo iniziale a credito, entrate dell'anno, strapagamenti), non
        // riusa $quoteMap perché quella contiene solo le quote delle rate che hanno
        // righe nella timeline: qui serve la fotografia completa dell'anagrafica. È lordo:
        // conta le quote a credito anche delle rate in bozza e non sottrae il debito delle
        // altre quote, quindi può stare accanto a un saldo finale «da versare».
        //
        // Lo scarto di perimetro vale anche per il `compensabile` qui sotto, ed è più visibile
        // lì: la frase nomina una RATA, e quella rata può appartenere a un esercizio diverso da
        // quello della pagina, quindi non comparire nella timeline sopra. È voluto — la
        // posizione del condòmino non si interrompe al 31 dicembre — ma va saputo. Vale anche
        // per il PDF: `estratto_conto_anagrafica.blade.php` stampa `credito_disponibile` e
        // `compensabile_frase` (verificato l'11/10/2026; il 06/08 non le stampava ancora).
        $credito = app(CreditoService::class)->perAnagrafica($condominio->id, $anagrafica->id);

        // COMPILAZIONE STATISTICHE CENTESIMALI FINALI
        // Decisione 75.5 (1.11.0-beta.49): una scrittura stornata e la sua rettifica restano nell'elenco — spiegano il saldo riga
        // per riga — ma non nei due totali, dove si sommavano a vuoto: un incasso da € 100,00 stornato faceva «Totale versato»
        // € 100,00 e «Totale addebiti» € 100,00 in più. La coppia pesa zero sul saldo, quindi saldo iniziale + addebiti − versato
        // resta il saldo finale. Le righe d'emissione in AVERE che restano qui (la quota delle entrate dell'anno, il credito
        // riportato di una quota senza il dettaglio del calcolo o di un piano di un altro esercizio) non sono versamenti: riducono
        // gli addebiti della loro rata.
        $neiTotali = $timeline->reject(fn (array $r) => $r['stato_scrittura'] === 'annullata' || $r['storna_annullata']);
        $stats = [
            'totale_addebiti'         => MoneyHelper::format($neiTotali->sum('dare') - $neiTotali->where('tipo_movimento', 'emissione_rata')->sum('avere')),
            'totale_versamenti'       => MoneyHelper::format($neiTotali->where('tipo_movimento', '!=', 'emissione_rata')->sum('avere')),
            'saldo_finale'            => MoneyHelper::format($runningBalance),
            'saldo_raw'               => $runningBalance,
            'saldo_iniziale'          => MoneyHelper::format($saldoInizialeCents),
            'saldo_iniziale_raw'      => $saldoInizialeCents,
            'credito_disponibile'     => $credito['totale_formatted'],
            'credito_disponibile_raw' => $credito['totale_cents'],
            'credito_per_gestione'    => $credito['per_gestione'],
            // Non solo quanto credito c'è, ma quale rata copre e dove si va a usarlo.
            'compensabile'            => $credito['compensabile']['importo_formatted'],
            'compensabile_raw'        => $credito['compensabile']['importo_cents'],
            'compensabile_frase'      => $credito['compensabile']['frase'],
            'compensabile_rata_id'    => $credito['compensabile']['rate_coperte'][0]['rata_id'] ?? null,
        ];

        return [$timeline, $stats, $saldoInizialeCents];
    }
}