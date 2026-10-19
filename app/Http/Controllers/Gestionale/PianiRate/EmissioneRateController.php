<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Models\Gestionale\RigaRiparto;
use App\Enums\NaturaGestione;
use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestionale\Subentro;
use App\Services\Gestionale\DoubleEntryValidator;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\RigaScrittura;
use App\Enums\StatoPianoRate;
use App\Enums\VisibilityStatus;
use App\Events\Gestionale\RataEmessa;
use App\Enums\EventoTipo;
use App\Models\Evento;
use App\Services\Gestionale\CreditoService;
use App\Services\Gestionale\EventiRataCondomino;
use App\Services\Gestionale\InboxService;
use App\Services\Subentro\FrasiObbligati;
use App\Traits\HandleFlashMessages;
use App\Traits\HasEsercizio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Controller responsabile per l'emissione e l'annullamento delle rate condominiali.
 * Gestisce la creazione delle scritture contabili in Prima Nota (Ciclo Attivo)
 * e la visibilità/notifica degli eventi nello scadenziario dei condòmini.
 */
class EmissioneRateController extends Controller
{
    use HandleFlashMessages, HasEsercizio;

    /**
     * Marcatore dell'unica eccezione di dominio che questo controller solleva da sé.
     * Serve perché il `catch (\Throwable)` in coda riduce tutto a «errore tecnico»: senza
     * un marcatore, a chi emette una rata con un riporto in un condominio a cui manca il
     * Fondo Passate Gestioni resterebbe un messaggio che non dice cosa fare.
     */
    private const ERRORE_PASSATE_GESTIONI = 'EMISSIONE_SENZA_FONDO_PASSATE_GESTIONI';

    /**
     * Emette una o più rate di un piano approvato.
     * Genera le scritture contabili e gestisce l'emissione "Silenziosa" 
     * per evitare l'invio prematuro di notifiche (Finestra di Vulnerabilità).
     *
     * @param Request $request
     * @param Condominio $condominio
     * @param PianoRate $pianoRate
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request, Condominio $condominio, PianoRate $pianoRate)
    {
        Log::info("--- START EMISSIONE RATE ---", [
            'condominio_id' => $condominio->id,
            'rate_ids' => $request->rate_ids,
            'invia_notifiche' => $request->invia_notifiche
        ]);
  
        if ($pianoRate->stato !== StatoPianoRate::APPROVATO) {
            return back()->with($this->flashError('Devi approvare il piano rate prima di poter emettere le rate.'));
        }

        // S8-22: qui la quota diventa denaro. Se le righe congelate dello straordinario portano una delibera diversa
        // da quella registrata sul piano (data corretta dopo la generazione), le quote sono stantie: si ricalcola
        // prima — ed è ancora possibile, perché nessuna rata è emessa.
        if (NaturaGestione::daStringa($pianoRate->gestione?->tipo) === NaturaGestione::Straordinaria && $pianoRate->data_delibera_assemblea !== null) {
            $stantia = RigaRiparto::where('piano_rate_id', $pianoRate->id)->where('gradino_competenza', 'delibera')
                ->whereDate('competenza_dal', '!=', $pianoRate->data_delibera_assemblea->toDateString())->value('competenza_dal');
            if ($stantia !== null) {
                $formato = fn ($d) => \Carbon\CarbonImmutable::parse($d)->locale('it')->translatedFormat('j F Y');

                return back()->with($this->flashError(sprintf(
                    'Le quote di questo piano sono state calcolate con la delibera del %s, ma il piano registra una delibera del %s: ricalcola il piano prima di emettere.',
                    $formato($stantia), $formato($pianoRate->data_delibera_assemblea)
                )));
            }
        }

        // Decisioni 35 e 41 (1.11.0-beta.42, difetto U1): i passaggi registrati dopo la generazione che hanno lasciato il piano al
        // ricalcolo (non lo hanno conguagliato, decisione 21), e dalla beta.51 quelli annullati dopo un ricalcolo che li comprendeva. Emesse
        // così, quelle quote resterebbero a chi esce, o andrebbero a giornale a chi non è più titolare. Si guarda lo stato del piano all'ora
        // di ogni passaggio, non quello di adesso: un incasso arrivato dopo il passaggio non lo ha conguagliato.
        if (($passaggi = $pianoRate->passaggiDaSeguire()) !== []) {
            return back()->with($this->flashError(self::fraseDaRicalcolare($pianoRate, $passaggi)));
        }

        $request->validate([
            'rate_ids' => 'required|array|min:1',
            // Solo le rate di questo piano (D-3, 1.11.0-beta.42): fino alla beta.41 bastava che la rata esistesse, e la riga
            // che segna «emessa» in fondo la marcava anche se era di un altro piano, o di un altro condominio.
            'rate_ids.*' => ['integer', \Illuminate\Validation\Rule::exists('rate', 'id')->where('piano_rate_id', $pianoRate->id)],
            'data_emissione' => 'required|date',
            'descrizione_personalizzata' => 'nullable|string|max:255',
            'invia_notifiche' => 'boolean' // Validazione del nuovo interruttore
        ]);

        $esercizio = $this->getEsercizioCorrente($condominio);
        $inviaNotifiche = $request->boolean('invia_notifiche', true); // Default a true se non passato
        
        $contoCrediti = ContoContabile::where('condominio_id', $condominio->id)
            ->where('ruolo', 'crediti_condomini')
            ->first();
        $contoGestione = ContoContabile::where('condominio_id', $condominio->id)
            ->where('ruolo', 'gestione_rate')
            ->first();

        // Contropartita del riporto da esercizi precedenti. NON è obbligatoria qui: serve solo
        // alle rate che portano un pregresso, e pretenderla sempre bloccherebbe l'emissione
        // ordinaria dei condomìni che non ce l'hanno. Il controllo è dentro il ciclo, dove si
        // sa se serve davvero.
        $contoPassateGestioni = ContoContabile::where('condominio_id', $condominio->id)
            ->where('ruolo', 'passate_gestioni')
            ->whereNull('deleted_at')
            ->first();

        if (!$contoCrediti || !$contoGestione) {
            return back()->with($this->flashError('Mancano i conti contabili (Crediti o Gestione Rate).'));
        }

        // Decisione 34 (1.11.0-beta.42): «emessa» vuol dire a giornale. Le rate che vanno a giornale qui, e quelle che restano
        // fuori, ognuna con il numero per il messaggio: senza niente da portare a giornale (vuote, o con il credito già usato o
        // rimborsato per intero: rilievo LDA-3 della Fase 1-bis), già emesse, segnate «emesse» senza scritture e prese così da un
        // passaggio (75.2). E le quote ferme perché a credito, o a zero, senza il dettaglio del calcolo (76.3, ritoccata
        // l'11/10/2026 sul rilievo LDA-2: si ferma la sola quota, non la rata), con la persona e la rata.
        $aGiornale = [];
        $senzaQuote = [];
        $creditoUsato = [];
        $giaEmesse = [];
        $quoteFerme = [];
        $rateFerme = [];
        $restanoEmesse = [];
        $presiPrima = [];

        try {
            DB::transaction(function () use ($request, $condominio, $pianoRate, $esercizio, $contoCrediti, $contoGestione, $contoPassateGestioni, $inviaNotifiche, &$aGiornale, &$senzaQuote, &$creditoUsato, &$giaEmesse, &$quoteFerme, &$rateFerme, &$restanoEmesse, &$presiPrima) {
                
                // Le quote delle rate si bloccano come PRIMA istruzione: la scrittura va intestata al titolare di adesso, e
                // i promemoria del portale riscritti più sotto con gli importi di adesso. Un passaggio che riassegna le bozze,
                // o il suo annullamento (1.11.0-beta.37), può cambiare nome e importo delle quote mentre l'emissione è in
                // volo; su MySQL l'istantanea delle letture nasce alla prima SELECT senza lock, quindi il lock va prima di
                // tutto (giro di verifica della Fase 1-bis, C-R2). Non si bloccano prima le righe di `rate`: la
                // registrazione blocca quota e rata insieme, e l'ordine inverso incrocerebbe le due operazioni.
                DB::table('rate_quote')->whereIn('rata_id', (array) $request->rate_ids)->lockForUpdate()->pluck('id');

                $rateSelezionate = Rata::with('rateQuote.anagrafica:id,nome')
                    ->where('piano_rate_id', $pianoRate->id)
                    ->whereIn('id', $request->rate_ids)
                    ->get();

                // Decisione 75.2 (W1): sui dati della .41 un passaggio registrato allora può aver preso nel conguaglio una rata segnata
                // «emessa» senza scritture (`PianoRate::presoSoloInParteDa`). Con la decisione 71 quella rata andrebbe a giornale: il
                // piano non risulterebbe più preso da quel passaggio, l'annullamento dell'emissione tornerebbe possibile e il
                // ricalcolo farebbe pagare due volte a chi è entrato i suoi giorni. La sua emissione si rifiuta, e l'esito dice perché.
                $presiPrima = collect($pianoRate->passaggiCheLoHannoConguagliato())->filter(fn ($s) => $pianoRate->presoSoloInParteDa($s))->values()->all();

                foreach ($rateSelezionate as $rata) {
                    if ($rata->rateQuote->whereNotNull('scrittura_contabile_id')->isNotEmpty()) {
                        // Già a giornale: un doppio invio, o la pagina aperta in due schede. Il messaggio la nomina (rilievo S2/D7).
                        $giaEmesse[(int) $rata->id] = (int) $rata->numero_rata;
                        continue;
                    }
                    if ($presiPrima !== [] && $rata->stato === 'emessa') {
                        $restanoEmesse[(int) $rata->id] = (int) $rata->numero_rata;
                        continue;
                    }
                    // Decisione 76.3, ritoccata l'11/10/2026 (rilievo LDA-2): una quota a credito senza il dettaglio del calcolo (piani
                    // generati con la 1.7.x) non dice quanta parte è spesa dell'anno e quanta credito riportato, e il programma non lo
                    // indovina. Si ferma la sola quota, come il conguaglio di un passaggio salta per chiave le stesse quote: le altre
                    // quote della rata vanno a giornale, e la quota ferma resta fuori con il suo credito, dove `ruoloDelCredito` lo
                    // cerca. Fermare la rata intera lasciava fuori dal giornale anche i debiti degli altri condòmini. Anche una quota
                    // a zero (rilievo K6): la 1.7.0 non ne creava di vuote, quindi è un credito pari alla spesa, che non si separa.
                    $ferme = $rata->rateQuote->filter(fn (RataQuote $q) => $q->fermaSenzaDettaglio());
                    foreach ($ferme as $q) {
                        $quoteFerme[] = ['nome' => $q->anagrafica?->nome ?? 'un condòmino', 'rata' => (int) $rata->numero_rata, 'zero' => (int) $q->importo === 0];
                    }
                    $daEmettere = $rata->rateQuote->reject(fn (RataQuote $q) => $ferme->contains('id', $q->id))->values();

                    $totaleRataCentesimi = 0;
                    $totalePregressoCentesimi = 0;

                    // 1. Scrittura Testata
                    $scrittura = ScritturaContabile::create([
                        'condominio_id'      => $condominio->id,
                        'esercizio_id'       => $esercizio->id,
                        'gestione_id'        => $pianoRate->gestione_id,
                        'data_registrazione' => now(),
                        'data_competenza'    => $request->data_emissione,
                        'causale'            => $request->descrizione_personalizzata ?: "Emissione " . $rata->descrizione,
                        'tipo_movimento'     => 'emissione_rata',
                        'stato'              => 'registrata',
                    ]);

                    // 2. Scrittura Righe (Dettaglio quote)
                    //
                    // Decisione 71 (1.11.0-beta.49): ogni quota va a giornale con le sue due componenti, a nome del condòmino, su
                    // «Crediti verso condomini». Una quota a debito porta l'importo intero in DARE; una quota che il credito riportato
                    // porta a zero o sotto porta la spesa in DARE e il credito riportato in AVERE, meno la parte già uscita da
                    // «Passate gestioni» mentre la quota era senza scrittura. Il contributo della rata è dovuto comunque, e il credito
                    // lo paga: senza le due righe lo stato patrimoniale mostrava un credito che non c'era più, e la gestione rate
                    // contava meno contributi del riparto. Le righe le dice `RataQuote::scritturaDiEmissione()`, che usa anche il
                    // comando di riallineamento.
                    //
                    // La contropartita: la spesa deliberata per QUESTO esercizio (`quota_pura_gestione`) chiude su «Gestione rate»;
                    // il resto è il riporto da esercizi precedenti — la Rata 0 è fatta solo di quello — e non è un provento
                    // dell'anno: chiude su «Passate gestioni», la stessa contropartita con cui l'apertura di cassa porta dentro una
                    // posizione anteriore (RegistraAperturaCassaAction:70-73).
                    //
                    // ⚠️ **Perché la componente di riporto si DERIVA per differenza** invece di leggere `saldo_usato` dal dettaglio:
                    // così `DARE = AVERE` vale per costruzione, anche su una quota il cui dettaglio non quadri. Fidarsi di due numeri
                    // scritti da qualcun altro significa poter emettere una scrittura sbilanciata, e il DoubleEntryValidator la
                    // rifiuterebbe a fine rata, dopo aver già bruciato il protocollo.
                    $righeScritte = 0;
                    $perQuota = RataQuote::scritturaDiEmissione($daEmettere, $contoPassateGestioni?->id);
                    foreach ($daEmettere as $quota) {
                        $daScrivere = $perQuota[(int) $quota->id];
                        if ($daScrivere['righe'] === []) {
                            // Niente da portare: una quota a zero senza spesa né riporto, o un credito già usato per intero prima
                            // dell'emissione. La quota resta senza scrittura; se poi uno storno restituisce quel credito, la scrive lo
                            // storno con una figlia di questa emissione e la lega (`StornoIncassoRateAction`, rilievi K4 e LDA-1).
                            continue;
                        }

                        foreach ($daScrivere['righe'] as $i => [$verso, $importoRiga]) {
                            $scrittura->righe()->create([
                                'conto_contabile_id' => $contoCrediti->id,
                                'anagrafica_id'      => $quota->anagrafica_id,
                                'immobile_id'        => $quota->immobile_id,
                                'rata_id'            => $rata->id,
                                'tipo_riga'          => $verso,
                                'importo'            => $importoRiga,
                                'note'               => $quota->notaDiEmissione($i, $verso, (string) $rata->descrizione),
                            ]);
                            $righeScritte++;
                        }

                        $quota->update(['scrittura_contabile_id' => $scrittura->id]);

                        $totaleRataCentesimi      += $daScrivere['gestione'];
                        $totalePregressoCentesimi += $daScrivere['pregresso'];
                    }

                    // 3. Chiusura su due conti quando la rata porta un riporto.
                    //
                    // I due totali possono essere NEGATIVI: un condòmino che arriva a credito ha un riporto negativo, che riduce il
                    // debito. Un totale negativo si scrive nel verso opposto, e la quadratura regge comunque perché le righe delle
                    // quote sono già la somma algebrica delle due componenti. Possono anche essere tutti e due zero su una rata che
                    // ha righe: una rata zero fatta di un debito e di un credito riportati uguali (la coppia di un conguaglio) va a
                    // giornale per tutti e due, senza contropartite. La scrittura si tiene se ha righe, non se il totale è positivo:
                    // con quella condizione restava in bozza anche il debito di chi compra.
                    if ($righeScritte > 0) {

                        if ($totalePregressoCentesimi !== 0 && ! $contoPassateGestioni) {
                            throw new \RuntimeException(self::ERRORE_PASSATE_GESTIONI);
                        }

                        foreach ([
                            [$contoGestione, $totaleRataCentesimi, "Totale emissione " . $rata->descrizione],
                            [$contoPassateGestioni, $totalePregressoCentesimi, "Riporto esercizi precedenti — " . $rata->descrizione],
                        ] as [$conto, $totale, $nota]) {

                            if ($totale === 0) continue;

                            $scrittura->righe()->create([
                                'conto_contabile_id' => $conto->id,
                                'tipo_riga'          => $totale > 0 ? 'avere' : 'dare',
                                'importo'            => abs($totale),
                                'note'               => $nota
                            ]);
                        }

                    } else {
                        // Niente da portare a giornale (una rata vuota, le cui quote a credito sono già state usate per intero, o fatta
                        // solo di quote ferme): la testata resterebbe una scrittura SENZA RIGHE, che il DoubleEntryValidator approva
                        // (0 = 0) ma che sporca il giornale e brucia un numero di protocollo. Peggio: nessuna quota riceve
                        // scrittura_contabile_id, quindi il guard anti-doppia-emissione non scatta e ogni nuova emissione ne accumula
                        // un'altra. Ogni caso con la sua frase: «non ha quote da pagare» solo per le rate davvero vuote (decisione 71).
                        $scrittura->forceDelete();
                        if ($ferme->isNotEmpty()) {
                            $rateFerme[(int) $rata->id] = (int) $rata->numero_rata;
                        } elseif ($daEmettere->contains(fn (RataQuote $q) => (int) $q->importo < 0)) {
                            $creditoUsato[(int) $rata->id] = (int) $rata->numero_rata;
                        } else {
                            $senzaQuote[(int) $rata->id] = (int) $rata->numero_rata;
                        }
                        continue;
                    }
                    $aGiornale[] = (int) $rata->id;

                    // Quadratura dell'emissione: la somma delle quote a DARE deve
                    // corrispondere esattamente alla chiusura in AVERE. Un arrotondamento
                    // sbagliato sulle quote qui viene intercettato subito, invece di
                    // propagarsi a tutte le rate del piano e comparire nel rendiconto.
                    DoubleEntryValidator::validateOrFail($scrittura->id);

                    // 4. Gestione Eventi Condòmini (Rendiamo la query robusta)
                    $rataId = (int) $rata->id;
                    $userEvents = Evento::where('tipo', EventoTipo::SCADENZA_RATA_CONDOMINO->value)
                        ->where(function($q) use ($rataId) {
                            $q->where('meta->context->rata_id', $rataId)
                            ->orWhere('meta->context->rata_id', (string) $rataId);
                        })
                        ->get();

                    foreach ($userEvents as $evt) {
                        $meta = $evt->meta;
                        $meta['is_emitted'] = true;
                        $meta['is_published'] = $inviaNotifiche; 
                        
                        $evt->update([
                            'meta' => $meta,
                            'visibility' => $inviaNotifiche ? VisibilityStatus::PRIVATE->value : VisibilityStatus::HIDDEN->value
                        ]);
                    }

                    // 5. Invio Notifiche
                    if ($inviaNotifiche) {
                        RataEmessa::dispatch($rata);
                    }

                    // 6. Pulizia Task Admin (CORRETTO: usiamo where standard per i path JSON)
                    Evento::where('tipo', EventoTipo::EMISSIONE_RATA->value)
                        ->where(function($q) use ($rataId) {
                            $q->where('meta->context->rata_id', $rataId)
                            ->orWhere('meta->context->rata_id', (string) $rataId);
                        })
                        ->delete();

                }

                // «Emessa» solo la rata che è andata a giornale (decisione 34): una rata che non ha prodotto scritture resta in
                // bozza — anche se una versione prima della beta.42 l'aveva già segnata «emessa» (la pagina del piano la mostrava già
                // in bozza: guarda le scritture). Non quella che un passaggio della .41 ha preso così (75.2, sopra): riportarla in
                // bozza toglierebbe al piano l'unica traccia di quel conguaglio. Una rata con una quota ferma e le altre a giornale è
                // «emessa» (76.3 ritoccata): la quota ferma resta fuori, e la card del riallineamento la elenca.
                Rata::whereIn('id', $aGiornale)->update(['stato' => 'emessa']);
                Rata::whereIn('id', array_keys($senzaQuote + $creditoUsato + $rateFerme))->where('stato', 'emessa')->update(['stato' => 'bozza']);
            });

            InboxService::clearAdminCache();

            $esito = $this->esitoEmissione(count($aGiornale), $senzaQuote, $giaEmesse, $restanoEmesse, $presiPrima, $quoteFerme, $rateFerme, $creditoUsato);

            $msg = $aGiornale === [] ? $esito['testo'] : ($inviaNotifiche
                ? 'Rate emesse e notificate correttamente ai condòmini.'
                : 'Rate emesse in modalità silenziosa. I condòmini non vedranno gli importi finché non li pubblicherai.');

            // Proposta compensazione: se qualche intestatario delle rate appena
            // emesse ha un credito disponibile (saldo a credito o strapagamento),
            // lo segnaliamo così l'amministratore può compensare subito.
            $suggerimentoCrediti = $this->buildSuggerimentoCrediti($condominio, $request->rate_ids);

            // L'esito in una chiave sua, per la stessa ragione del suggerimento qui sotto: lo legge il modale dell'emissione.
            $risposta = back()->with($aGiornale === [] ? $this->flashWarning($msg) : $this->flashSuccess($msg))->with('esito_emissione', $esito);

            // In una chiave propria, non accodato a $msg: il banner di `flash.message` viene
            // dipinto e poi cancellato dal modale di conferma dell'emissione, quindi un
            // suggerimento scritto lì non fa in tempo a essere letto. Da qui lo raccoglie il
            // modale, che resta finché non lo si chiude ed è dove l'amministratore guarda.
            return $suggerimentoCrediti
                ? $risposta->with('suggerimento_crediti', $suggerimentoCrediti)
                : $risposta;

        } catch (\Throwable $e) {
            Log::error("Errore emissione rate: " . $e->getMessage());

            if (str_contains($e->getMessage(), self::ERRORE_PASSATE_GESTIONI)) {
                // Dalla 1.11.0-beta.49 anche una quota a credito porta un riporto (il credito riportato, decisione 71): senza il
                // conto non va a giornale, e l'errore ferma tutta la richiesta, nella stessa transazione.
                // Giro stretto sulle correzioni della Fase 1-bis (LT-2): non «ricrealo dal piano dei conti», che nel programma è il
                // preventivo; il conto di sistema dalle pagine non si ricrea (`ContoPassateGestioniMancanteException`).
                return back()->with($this->flashError(
                    'Questa rata porta un riporto da esercizi precedenti (un debito o un credito riportato), ma a questo '
                    .'condominio manca il conto di sistema «Fondo Passate Gestioni» (2301): senza, il riporto finirebbe fra le '
                    .'entrate dell\'anno, o un credito riportato resterebbe fuori dal giornale. Dalle pagine del programma non si '
                    .'ricrea: va ripristinato da chi gestisce l\'installazione. Nessuna rata di questa richiesta è stata emessa.'
                ));
            }

            if (str_contains($e->getMessage(), 'Duplicate entry') && str_contains($e->getMessage(), 'numero_protocollo_unique')) {
                return back()->with($this->flashError(
                    'Errore di numerazione: Il sistema ha tentato di usare un numero di protocollo già esistente.'
                ));
            }

            return back()->with($this->flashError('Si è verificato un errore tecnico durante l\'emissione.'));
        }
}

    /**
     * Decisioni 35, 41 e i rilievi R6 e R7 della Fase 1-bis della .42: il rifiuto dell'emissione dice il fatto e cosa fare, senza
     * promettere che il ricalcolo cambi le cifre (con le voci sul «Proprietario» di un usufrutto «come dice ogni voce», o una
     * straordinaria deliberata prima, le cifre restano quelle). Se il ricalcolo è fermo per un movimento, dice di annullarlo
     * prima; dopo una riserva con la legge, dice come lasciare l'ordinaria a chi resta usufruttuario.
     *
     * Verifica a video della .42: in capoversi, con i passaggi in elenco e i passi numerati (`elencoPuntato`, `passiNumerati`);
     * in una riga sola il testo era giusto ma non si leggeva.
     *
     * @param list<Subentro> $passaggi
     */
    public static function fraseDaRicalcolare(PianoRate $piano, array $passaggi): string
    {
        // Beta.51 (§6.3): fra i passaggi da seguire ci sono anche quelli annullati dopo il ricalcolo del piano. Hanno il loro paragrafo,
        // con le loro parole; il fermo del ricalcolo vale per tutti, le riserve d'usufrutto solo per i passaggi attivi.
        [$attivi, $annullati] = PianoRate::divisiPerStato($passaggi);
        $capoversi = [];
        if ($attivi !== []) {
            $voci = PianoRate::vociDeiPassaggi(PianoRate::senzaPertinenze($attivi));
            $uno = count($voci) === 1;
            $nomi = collect($attivi)->map(fn (Subentro $p) => $p->uscente?->nome)->filter()->unique()->values();
            $capoversi[] = ($uno ? 'Le quote di questo piano sono state calcolate prima di questo passaggio:' : 'Le quote di questo piano sono state calcolate prima di questi passaggi:')
                . "\n" . self::elencoPuntato($voci);
            $capoversi[] = sprintf('Ricalcola il piano prima di emettere, perché tenga conto %s. Se le voci o la data della delibera lasciano quelle quote %s, il ricalcolo dà le stesse cifre.',
                $uno ? 'del passaggio' : 'dei passaggi', FrasiObbligati::a($nomi->isEmpty() ? 'chi le aveva' : self::nomiInFila($nomi)));
        }
        if ($annullati !== []) {
            // Le quote nate dopo un passaggio poi annullato hanno la titolarità del passaggio: emesse così andrebbero a giornale a chi
            // non è più titolare. Il ricalcolo le riporta alla titolarità di prima.
            $voci = PianoRate::vociDeiPassaggi(PianoRate::senzaPertinenze($annullati));
            $uno = count($voci) === 1;
            $chi = $piano->nomiDiChiEntravaNegliAnnullati($annullati);
            $capoversi[] = ($uno ? 'Le quote di questo piano sono state calcolate con questo passaggio, che poi è stato annullato:' : 'Le quote di questo piano sono state calcolate con questi passaggi, che poi sono stati annullati:')
                . "\n" . self::elencoPuntato($voci);
            $capoversi[] = sprintf('Ricalcola il piano prima di emettere, perché torni alla titolarità di prima: emesse così, le quote di %s andrebbero a giornale per %s.',
                $chi === [] ? 'chi era entrato' : self::nomiInFila(collect($chi)), $uno ? 'un passaggio che non c\'è più' : 'passaggi che non ci sono più');
        }
        // Punto 8 della ripresa e rilievo V9: la ragione vera per cui oggi il ricalcolo si rifiuta, e il rimedio di quella ragione.
        // Le ragioni in una frase, e i passaggi del conguaglio (sempre l'ultima ragione) in elenco subito dopo; i passi arrivano
        // fino al ricalcolo, e un movimento annullato si registra di nuovo nell'ultimo.
        $fermo = $piano->conIPassaggiCalcolati(fn (PianoRate $p) => ($frase = $p->fraseDelFermo(passaggiAParte: true)) === null ? null
            : "Oggi il ricalcolo si rifiuta perché il piano {$frase}"
                . (in_array('conguaglio', $p->ragioniDelFermo(), true)
                    ? ":\n" . self::elencoPuntato(PianoRate::vociDeiPassaggi($p->passaggiDaAnnullare())) . "\nPer procedere:"
                    : '. Per procedere:')
                . "\n" . self::passiNumerati($p->rimediDelFermo(inElenco: true, finoAlRicalcolo: true)));
        if ($fermo !== null) {
            $capoversi[] = $fermo;
        }
        $riserve = PianoRate::senzaPertinenze(collect($attivi)->filter(fn (Subentro $p) => $p->riservaUsufrutto() && ($p->registro['ordinaria_dopo_atto'] ?? null) === Subentro::ORDINARIA_ALL_USUFRUTTUARIO));
        if ($riserve !== []) {
            // Non «metti le voci su Usufruttuario dalla loro pagina» (rilievo T-B2 della .41): fuori dal passaggio lo spostamento
            // non va nel registro, l'annullamento non lo nomina e non si vedono le altre unità che la voce tocca (31.5, 31.7).
            // Revisione della verifica a video: «a chi si riserva l'usufrutto», senza accordare la frase alla persona; il primo passo
            // dice dove si annulla, come tutti gli altri.
            $una = count($riserve) === 1;
            $chi = collect($riserve)->map(fn (Subentro $p) => $p->uscente?->nome)->filter()->unique()->values();
            $capoversi[] = sprintf('Se l\'ordinaria deve restare a chi si riserva l\'usufrutto%s, come scelto nel passaggio:', $chi->isEmpty() ? '' : ' (' . self::nomiInFila($chi) . ')')
                . "\n" . self::passiNumerati([
                    $una ? 'annulla il passaggio dallo storico della sua unità («Passaggi registrati», dall\'ultimo)'
                        : 'annulla tutti quei passaggi, ognuno dallo storico della sua unità («Passaggi registrati», dall\'ultimo)',
                    'riporta il piano in bozza',
                    $una ? 'registra di nuovo il passaggio, che sposterà le voci sul «Proprietario» su «Usufruttuario»'
                        : 'registrali di nuovo, quando sono annullati tutti: sposteranno le voci sul «Proprietario» su «Usufruttuario»',
                    'riapprova il piano e ricalcolalo',
                ]);
        }

        return implode("\n\n", $capoversi);
    }

    /** «Marco Bassi, Ugo Ferri ed Elena Fabbri»: «ed» davanti a un nome che comincia per «e». */
    private static function nomiInFila(\Illuminate\Support\Collection $nomi): string
    {
        return $nomi->join(', ', preg_match('/^[eE]/u', (string) $nomi->last()) ? ' ed ' : ' e ');
    }

    /**
     * Le righe «• …» che la modale della pagina del piano mostra come elenco puntato (`blocchiMessaggio.ts`).
     *
     * @param list<string> $voci
     */
    private static function elencoPuntato(array $voci): string
    {
        return implode("\n", array_map(fn (string $v) => '• ' . $v, $voci));
    }

    /**
     * Le righe «1. …» che la modale mostra come passi numerati: ogni passo comincia con la maiuscola e chiude con il punto.
     *
     * @param list<string> $passi
     */
    private static function passiNumerati(array $passi): string
    {
        return implode("\n", array_map(fn (string $p, int $i) => sprintf('%d. %s%s', $i + 1, mb_strtoupper(mb_substr($p, 0, 1)) . mb_substr($p, 1),
            str_ends_with($p, '.') ? '' : '.'), array_values($passi), array_keys(array_values($passi))));
    }

    /**
     * Che cosa ha fatto l'emissione, contato sulle rate andate a giornale e non su quelle chieste (decisione 34). Ogni rata rimasta
     * fuori ha la sua frase: vuota (la frase «non ha quote da pagare» è solo per queste rate, dalla decisione 71), con il credito già
     * usato o rimborsato per intero (rilievo LDA-3 della Fase 1-bis), già emessa, presa da un passaggio della .41 così com'era
     * (75.2). Le quote ferme perché senza il dettaglio del calcolo (76.3, ritoccata l'11/10/2026 sul rilievo LDA-2) hanno una frase
     * loro, che nomina la persona e la rata e dice la strada; la rata fatta solo di quelle resta in bozza, e lo si dice.
     *
     * Le rate rimaste fuori «restano in bozza», non «tornano»: la pagina del piano le mostrava già in bozza anche quando una
     * versione di prima le aveva segnate «emesse» (guarda le scritture), e la frase dice quello che l'amministratore vede.
     *
     * @param array<int, int> $senzaQuote id della rata => numero della rata
     * @param array<int, int> $giaEmesse id della rata => numero della rata, per le rate che erano già a giornale
     * @param array<int, int> $restanoEmesse le rate «emesse» senza scritture prese da un passaggio della .41
     * @param list<Subentro> $presiPrima quei passaggi
     * @param list<array{nome: string, rata: int, zero: bool}> $quoteFerme le quote a credito, o a zero, senza il dettaglio del calcolo
     * @param array<int, int> $rateFerme le rate fatte solo di quote ferme, rimaste in bozza
     * @param array<int, int> $creditoUsato le rate senza niente da portare perché il loro credito è già uscito tutto
     * @return array{titolo: string, testo: string}
     */
    private function esitoEmissione(int $emesse, array $senzaQuote, array $giaEmesse = [], array $restanoEmesse = [], array $presiPrima = [], array $quoteFerme = [], array $rateFerme = [], array $creditoUsato = []): array
    {
        $frasi = [];
        if ($emesse > 0) {
            $frasi[] = $emesse === 1 ? 'È stata emessa 1 rata.' : "Sono state emesse {$emesse} rate.";
        }
        if ($giaEmesse !== []) {
            $numeri = collect($giaEmesse)->sort()->values();
            $frasi[] = $numeri->count() === 1 ? "La rata {$numeri[0]} era già emessa." : 'Le rate ' . $numeri->join(', ', ' e ') . ' erano già emesse.';
        }
        if ($senzaQuote !== []) {
            $numeri = collect($senzaQuote)->sort()->values();
            $frasi[] = $numeri->count() === 1
                ? "La rata {$numeri[0]} non è stata emessa: non ha quote da pagare, e a giornale non c'è niente da portare. Resta in bozza."
                : 'Le rate ' . $numeri->join(', ', ' e ') . " non sono state emesse: non hanno quote da pagare, e a giornale non c'è niente da portare. Restano in bozza.";
        }
        if ($creditoUsato !== []) {
            $numeri = collect($creditoUsato)->sort()->values();
            $frasi[] = $numeri->count() === 1
                ? "La rata {$numeri[0]} non è stata emessa: il suo credito è già stato usato o rimborsato per intero, e a giornale non c'è niente da portare. Resta in bozza."
                : 'Le rate ' . $numeri->join(', ', ' e ') . " non sono state emesse: il loro credito è già stato usato o rimborsato per intero, e a giornale non c'è niente da portare. Restano in bozza.";
        }
        if ($quoteFerme !== []) {
            $frasi[] = self::fraseQuoteFerme($quoteFerme);
            if ($rateFerme !== []) {
                $numeri = collect($rateFerme)->sort()->values();
                // «quella quota» o «quelle quote» si conta sulle quote ferme di quelle rate, non sulle rate (giro stretto sulle
                // correzioni della Fase 1-bis, LT-8): due quote ferme sulla stessa rata sono «quelle quote».
                $quante = collect($quoteFerme)->whereIn('rata', $numeri->all())->count();
                $frasi[] = $numeri->count() === 1
                    ? "La rata {$numeri[0]} resta in bozza: " . ($quante === 1 ? 'senza quella quota' : 'senza quelle quote') . ' non ha niente da portare a giornale.'
                    : 'Le rate ' . $numeri->join(', ', ' e ') . ' restano in bozza: senza quelle quote non hanno niente da portare a giornale.';
            }
        }

        if ($restanoEmesse !== []) {
            $numeri = collect($restanoEmesse)->sort()->values();
            $una = $numeri->count() === 1;
            // Rilievo LDA-5: la strada con le parole della pagina del piano, dove il pulsante «Ricalcola» dice il motivo del blocco e,
            // sotto «Per riaprirlo», i passi; non con i nomi del codice.
            $frasi[] = sprintf('%s: %s senza scritture, e il conguaglio %s, registrato con una versione di prima, %s così. %s a giornale adesso, il piano non risulterebbe più preso da quel conguaglio, e un ricalcolo farebbe pagare due volte a chi è entrato i suoi giorni. Per %s, segui i passi sul pulsante «Ricalcola» della pagina del piano, sotto «Per riaprirlo».',
                $una ? "La rata {$numeri[0]} non è stata emessa" : 'Le rate ' . $numeri->join(', ', ' e ') . ' non sono state emesse',
                $una ? 'è segnata come emessa' : 'sono segnate come emesse',
                PianoRate::descriviPassaggi($presiPrima),
                $una ? 'l\'ha presa' : 'le ha prese',
                $una ? 'Portata' : 'Portate',
                $una ? 'emetterla' : 'emetterle');
        }

        $fuori = $senzaQuote === [] && $creditoUsato === [] && $rateFerme === [] && $restanoEmesse === [];

        return ['titolo' => match (true) { $emesse > 0 => 'Emissione completata', $fuori && $giaEmesse !== [] => 'Rate già emesse', default => 'Nessuna rata emessa' }, 'testo' => implode(' ', $frasi)];
    }

    /**
     * La frase delle quote ferme (76.3 ritoccata, rilievi LDA-2 e K6): chi e su quale rata, perché, che cosa succede al credito e
     * la strada. La strada non è il solo ricalcolo: il ricalcolo chiede di stornare prima gli incassi, le compensazioni e i
     * rimborsi e di annullare le emissioni del piano, e rilegge il saldo pregresso con il segno di oggi, mentre le versioni di prima
     * della 1.8.0 lo sottraevano (misurato su LDA-2: un credito di −7500 a rata diventava un debito di +17500). Va
     * controllato prima.
     *
     * Che cosa succede al credito lo si dice per tipo di quota (giro stretto sulle correzioni della Fase 1-bis, LT-3 e G3): una quota
     * a credito lascia un credito da usare o rimborsare; una quota a zero no, perché il credito riportato paga per intero la spesa
     * della rata, e restano fuori tutti e due. «Il credito resta a …» nomina solo chi ha una quota a credito.
     *
     * @param list<array{nome: string, rata: int, zero: bool}> $quoteFerme
     */
    private static function fraseQuoteFerme(array $quoteFerme): string
    {
        $pezzi = fn (\Illuminate\Support\Collection $quote) => $quote->groupBy('nome')->map(function ($sue, $nome) {
            $numeri = $sue->pluck('rata')->unique()->sort()->values();

            return 'di ' . $nome . ($numeri->count() === 1 ? " sulla rata {$numeri[0]}" : ' sulle rate ' . $numeri->join(', ', ' e '));
        })->values()->join(' e ');
        $tutte = collect($quoteFerme);
        $crediti = $tutte->reject(fn ($q) => $q['zero'])->values();
        $zeri = $tutte->filter(fn ($q) => $q['zero'])->values();
        $una = $tutte->count() === 1;
        $chi = self::nomiInFila($tutte->pluck('nome')->unique()->values());
        $tipo = $crediti->isEmpty()
            ? ($una ? 'è a zero' : 'sono a zero')
            : ($zeri->isNotEmpty() ? 'sono a credito o a zero' : ($una ? 'è a credito' : 'sono a credito'));

        $frasi = [sprintf('%s %s non %s andat%s a giornale: %s senza il dettaglio del calcolo (piano generato con una versione di prima della 1.8.0), che non dice quanta parte è spesa dell\'anno e quanta credito riportato, e il programma non lo indovina.',
            $una ? 'La quota' : 'Le quote',
            $pezzi($tutte),
            $una ? 'è' : 'sono',
            $una ? 'a' : 'e',
            $tipo)];
        if ($crediti->isNotEmpty()) {
            $frasi[] = sprintf('Il credito resta %s, da usare o rimborsare.', FrasiObbligati::a(self::nomiInFila($crediti->pluck('nome')->unique()->values())));
        }
        if ($zeri->isNotEmpty()) {
            $unoZero = $zeri->count() === 1;
            $frasi[] = sprintf('%s la spesa %s e il credito riportato che la paga si pareggiano, e restano fuori dal giornale tutti e due: non c\'è un credito da usare né da rimborsare, anche se l\'estratto conto può mostrare a credito il saldo pregresso.',
                $crediti->isEmpty()
                    ? 'Per ' . self::nomiInFila($zeri->pluck('nome')->unique()->values())
                    : ($unoZero ? 'Per la quota a zero ' : 'Per le quote a zero ') . $pezzi($zeri),
                $unoZero ? 'della rata' : 'di ogni rata');
        }
        $frasi[] = sprintf('Per portare %s a giornale si ricalcola il piano, dopo aver stornato gli incassi, le compensazioni e i rimborsi e annullato le emissioni; prima controlla il saldo pregresso di %s: le versioni di prima della 1.8.0 lo sottraevano invece di sommarlo.',
            $una ? 'la quota' : 'quelle quote',
            $chi);

        return implode(' ', $frasi);
    }

    /**
     * Verifica se gli intestatari delle rate appena emesse hanno crediti
     * disponibili (saldi a credito non consumati o quote strapagate) e
     * costruisce il testo del suggerimento di compensazione da accodare
     * al messaggio flash. Ritorna null se nessuno ha credito.
     */
    private function buildSuggerimentoCrediti(Condominio $condominio, array $rateIds): ?string
    {
        $anagraficheIds = RataQuote::whereIn('rata_id', $rateIds)
            ->whereNotNull('anagrafica_id')
            ->pluck('anagrafica_id')
            ->unique();

        if ($anagraficheIds->isEmpty()) {
            return null;
        }

        $crediti = app(CreditoService::class)->perCondominio($condominio->id, $anagraficheIds->all());

        if ($crediti->isEmpty()) {
            return null;
        }

        // Contano solo quelli il cui credito copre DAVVERO qualcosa: segnalare un credito
        // che non ha niente da compensare manda l'amministratore su una pagina dove non c'è
        // nulla da fare, che è il difetto che questa versione sta chiudendo.
        $compensabili = $crediti->filter(fn($c) => $c['compensabile']['importo_cents'] > 0)->values();

        if ($compensabili->isEmpty()) {
            return null;
        }

        // Con un solo condòmino si può essere precisi: si dice quale rata copre.
        if ($compensabili->count() === 1) {
            $c = $compensabili->first();

            return 'Nota: ' . $c['nome'] . ' ha ' . MoneyHelper::format($c['compensabile']['importo_cents'])
                . ' di credito spendibile subito. ' . $c['compensabile']['frase']
                . ' Lo compensi da "Nuovo incasso".';
        }

        $elenco = $compensabili->take(3)
            ->map(fn($c) => $c['nome'] . ' (' . MoneyHelper::format($c['compensabile']['importo_cents']) . ')')
            ->join(', ');

        if ($compensabili->count() > 3) {
            $elenco .= ' e altri ' . ($compensabili->count() - 3);
        }

        return 'Nota: ' . $compensabili->count() . ' condòmini hanno un credito che copre rate già aperte — '
            . $elenco . '. Li compensi da "Nuovo incasso".';
    }

    /**
     * Annulla l'emissione di una singola rata.
     * Rimuove la scrittura contabile e ripristina lo stato dell'evento utente in "Bozza".
     *
     * @param Request $request
     * @param Condominio $condominio
     * @param PianoRate $pianoRate
     * @param Rata $rata
     * @return \Illuminate\Http\RedirectResponse
     */

    public function destroy(Request $request, Condominio $condominio, PianoRate $pianoRate, Rata $rata)
    {
        // `≠ 0` e non `> 0`: una quota a credito compensata o rimborsata ha `importo_pagato` negativo (B2, S6).
        $haPagamenti = DB::table('rate_quote')
            ->where('rata_id', $rata->id)
            ->where('importo_pagato', '!=', 0)
            ->exists();

        if ($haPagamenti) {
            return back()->with($this->flashError('Impossibile annullare: ci sono già incassi registrati, o crediti già usati o rimborsati su questa rata.'));
        }

        // B2 (S5, D9): se un passaggio di titolarità ha già conguagliato le quote emesse di questo piano
        // (coppia in `saldi` con `subentro_id` sulla stessa gestione, **registrato dopo la generazione**
        // della rata — `rate.data_emissione` è scritta alla generazione, e la decorrenza può essere
        // anteriore: un rogito di dicembre registrato a febbraio conguaglia tutto l'anno), riportare la
        // rata in bozza e rigenerarla farebbe pagare due volte a chi entra — il pro rata del motore E il
        // conguaglio. Si rifiuta e si dice dove guardare (verifica S5, R1).
        // Al grano della coppia (S8-19): dalla R9 ogni gamba porta l'esercizio del piano che l'ha prodotta, quindi
        // con `esercizio_id` noto si guarda solo quello — una gestione riusata su due esercizi non blocca il piano
        // dell'anno prima. Piani senza `esercizio_id` (prima della migrazione 9): come prima, conservativo.
        // Rilievo V7 del giro di verifica della .42 e decisione 42: al grano del piano, non della gestione. Prima una coppia di un
        // altro piano della stessa gestione bloccava l'annullamento anche qui, e una coppia annullata lo sbloccava anche se il
        // passaggio aveva preso questo piano. Contano i passaggi che hanno preso il piano, registrati dopo la generazione della rata.
        // Rilievo V1 del giro, nella prova della strada: l'ora vera dell'emissione è quella della sua scrittura, non un campo della
        // rata (`data_emissione` è l'ora della generazione, `GenerateRateQuotesAction`, e non dice se il passaggio è venuto prima o
        // dopo l'emissione): una rata emessa a chi è entrato dopo il passaggio sembrava emessa prima, e l'annullamento del passaggio, che chiede
        // di annullare quell'emissione, finiva in un giro chiuso. Senza scrittura (dati di prima), la data come prima.
        // Decisione 49 (rilievo T3 del giro sulle correzioni): contano solo i passaggi senza `piani_presi`, registrati prima della
        // .42, per i quali il piano risulta preso proprio grazie a una scrittura entro l'ora del passaggio. Un passaggio della .42
        // tiene fermo il piano finché c'è (decisioni 42 e 43): annullare l'emissione e rifarla dà le stesse quote.
        $aGiornaleDal = DB::table('rate_quote')->join('scritture_contabili', 'scritture_contabili.id', '=', 'rate_quote.scrittura_contabile_id')
            ->where('rate_quote.rata_id', $rata->id)->min('scritture_contabili.created_at');
        $emessaIl = $aGiornaleDal !== null ? \Illuminate\Support\Carbon::parse($aGiornaleDal) : ($rata->data_emissione ?? $rata->created_at);
        $passaggiConguagliati = collect($pianoRate->passaggiCheLoHannoConguagliato())
            ->filter(fn ($s) => ! PianoRate::haIPianiPresi($s) && $s->created_at !== null && $s->created_at->greaterThanOrEqualTo($emessaIl))
            ->values();
        if ($passaggiConguagliati->isNotEmpty()) {
            // Rilievi T4 e W9 del giro sulle correzioni: lo stesso elenco delle altre frasi, con l'unità e senza doppioni delle
            // pertinenze; e l'ordine giusto — annullati i passaggi, prima si annulla l'emissione, poi si registrano di nuovo
            // (registrati prima, riprenderebbero il piano grazie alla scrittura ancora lì).
            $passaggi = PianoRate::senzaPertinenze($passaggiConguagliati);
            $uno = count($passaggi) === 1;

            // Tre capoversi, con i passaggi in elenco e i passi numerati (verifica a video della .42): la modale della pagina del
            // piano li mostra così (`blocchiMessaggio.ts`), e letti in fila erano un blocco.
            return back()->with($this->flashError(
                ($uno ? 'Un passaggio di titolarità registrato dopo l\'emissione ha preso questo piano nel conguaglio:' : 'Più passaggi di titolarità registrati dopo l\'emissione hanno preso questo piano nel conguaglio:')
                . "\n" . self::elencoPuntato(PianoRate::vociDeiPassaggi($passaggi))
                // Rilievo T-A del terzo giro: la ragione vera — una scrittura entro l'ora del passaggio, oppure (dati della .41) la
                // rata segnata «emessa» senza scritture che quel conguaglio ha preso così.
                // Rilievo T1: dopo una rinuncia o un conguaglio annullato, quella parte l'hanno regolata le parti, non il conguaglio.
                . sprintf("\n\n%s prima della versione 1.11.0-beta.42: il piano risulta preso grazie %s. Annullare l'emissione lo riaprirebbe al ricalcolo, che rifarebbe per giorni ciò che %s.",
                    $uno ? 'È un passaggio registrato' : 'Sono passaggi registrati',
                    collect($passaggi)->contains(fn ($p) => $pianoRate->presoSoloInParteDa($p))
                        ? ($uno ? 'a una rata segnata «emessa», senza scritture, che quel conguaglio ha preso così' : 'a una rata segnata «emessa», senza scritture, che quei conguagli hanno preso così')
                        : 'alle quote già a giornale',
                    // Revisione della verifica a video: al plurale con più passaggi, e nel caso misto (uno con le parti che hanno
                    // regolato fra loro, uno no) tutti e due.
                    match (true) {
                        ($fuori = collect($passaggi)->filter(fn ($p) => $p->conguaglioRinunciato() || $p->conguaglioAnnullato())->count()) === 0 => $uno ? 'il conguaglio ha già regolato' : 'quei conguagli hanno già regolato',
                        $fuori === count($passaggi) => 'le parti hanno già regolato fra loro',
                        default => 'quei conguagli, o le parti fra loro, hanno già regolato',
                    })
                // Decisioni 43 e 49: solo l'annullamento del passaggio riapre il piano; «annulla il conguaglio» vuol dire che le parti
                // hanno regolato fra loro, e il piano resta fermo. Rilievi T4 e W9: la nuova registrazione dopo l'annullamento
                // dell'emissione, perché registrato prima il passaggio riprenderebbe il piano grazie alla scrittura ancora lì.
                . "\n\nPer annullare l'emissione:\n" . self::passiNumerati($uno ? [
                    'annulla quel passaggio dallo storico della sua unità («Passaggi registrati», dall\'ultimo). Se dopo il passaggio sono state emesse altre rate, l\'annullamento chiede di annullare prima quelle',
                    'annulla l\'emissione',
                    'solo a questo punto registra di nuovo il passaggio',
                ] : [
                    'annulla tutti quei passaggi, ognuno dallo storico della sua unità («Passaggi registrati», dall\'ultimo). Se dopo un passaggio sono state emesse altre rate, l\'annullamento chiede di annullare prima quelle',
                    'annulla l\'emissione',
                    'solo a questo punto registrali di nuovo',
                ])
            ));
        }

        $esercizio = $this->getEsercizioCorrente($condominio);

        if (!$esercizio) {
            return back()->with($this->flashError('Nessun esercizio aperto trovato per generare il link del task.'));
        }

        try {
            DB::transaction(function () use ($rata, $condominio, $pianoRate, $request, $esercizio) { 
                
                // 1. Sgancio e rimozione Scritture
                $scrittureIds = $rata->rateQuote()->pluck('scrittura_contabile_id')->filter()->unique();
                $rata->rateQuote()->update(['scrittura_contabile_id' => null]);

                if ($scrittureIds->isNotEmpty()) {
                    // Con l'emissione se ne vanno le sue figlie di tipo emissione (1.11.0-beta.49, decisione 76.3): lo storno di un
                    // credito usato prima che la rata andasse a giornale riporta fra i crediti verso condomini la parte restituita
                    // con una scrittura figlia dell'emissione (`StornoIncassoRateAction`), che senza l'emissione non ha ragione
                    // d'essere. Lasciata lì (la chiave `scrittura_padre_id` si azzera da sola), il credito resterebbe a giornale
                    // con la rata di nuovo in bozza.
                    $scrittureIds = $scrittureIds->merge(ScritturaContabile::whereIn('scrittura_padre_id', $scrittureIds)
                        ->where('tipo_movimento', 'emissione_rata')->pluck('id'))->unique()->values();
                    ScritturaContabile::whereIn('id', $scrittureIds)->whereNotNull('scrittura_padre_id')->update(['scrittura_padre_id' => null]);
                    RigaScrittura::whereIn('scrittura_id', $scrittureIds)->delete();
                    ScritturaContabile::whereIn('id', $scrittureIds)->forceDelete();
                }

                // Riportiamo la rata in stato di bozza
                $rata->update(['stato' => 'bozza']);

                $rataId = (int) $rata->id; // Cast sicuro

                // 2. Ripristino Eventi Utente (Query Robusta Applicata)
                $userEvents = Evento::where('tipo', EventoTipo::SCADENZA_RATA_CONDOMINO->value)
                    ->where(function($q) use ($rataId) {
                        $q->where('meta->context->rata_id', $rataId)
                          ->orWhere('meta->context->rata_id', (string) $rataId);
                    })
                    ->get();

                foreach ($userEvents as $evt) {
                    $meta = $evt->meta;
                    $meta['is_emitted'] = false; 
                    $meta['is_published'] = false; 
                    
                    $evt->update([
                        'meta' => $meta,
                        'visibility' => VisibilityStatus::PRIVATE->value 
                    ]);
                }
                
                // 3. Rigenerazione Task Admin tramite Builder
                $dataPromemoria = $rata->data_scadenza->copy()->subDays(7)->setTime(9, 0);
                
                $eventoAdmin = InboxService::createTask(
                    tipo: EventoTipo::EMISSIONE_RATA,
                    title: "Emettere rata {$rata->numero_rata} - {$condominio->nome}",
                    description: "Ricordati di emettere le ricevute per questa rata entro la scadenza. (Riemissione dopo annullamento)",
                    scadenza: $dataPromemoria,
                    createdByUserId: $request->user()->id,
                    condominioId: $condominio->id,
                    context: [
                        'piano_rate_id' => $pianoRate->id,
                        'rata_id'       => $rataId 
                    ],
                    actionUrl: route('admin.gestionale.esercizi.piani-rate.show', [
                        'condominio' => $condominio->id,
                        'esercizio'  => $esercizio->id, 
                        'pianoRate'  => $pianoRate->id
                    ]),
                    extraMeta: [
                        'gestione'          => $pianoRate->gestione->nome ?? 'Gestione',
                        'condominio_nome'   => $condominio->nome,
                        'totale_rata'       => $rata->importo_totale,
                        'anagrafiche_count' => $rata->rateQuote->unique('anagrafica_id')->count(),
                        'scadenza_reale'    => $rata->data_scadenza->toDateString(),
                        'numero_rata'       => $rata->numero_rata,
                        'piano_nome'        => $pianoRate->nome,
                    ]
                );
                
                $eventoAdmin->condomini()->syncWithoutDetaching([$condominio->id]);
                if ($request->user()->anagrafica_id) {
                    $eventoAdmin->anagrafiche()->syncWithoutDetaching([$request->user()->anagrafica_id]);
                }
            });

            InboxService::clearAdminCache();

            return back()->with($this->flashSuccess('Emissione annullata. La rata è tornata in bozza e il promemoria è stato ripristinato.'));

        } catch (\Throwable $e) {
            Log::error("Errore annullamento: " . $e->getMessage());
            return back()->with($this->flashError('Si è verificato un errore durante l\'annullamento.'));
        }
    }

    /**
     * Sblocca la visibilità delle rate emesse in modalità "Silenziosa".
     * Le rende visibili nell'app e invia finalmente le notifiche ai condòmini.
     */
   /*
    * `Esercizio $esercizio` tipizzato (rilievo S1 della Fase 1-bis della 1.11.0-beta.42): senza tipo il genitore del piano
    * nell'indirizzo restava una stringa, e il piano si risolveva senza vincolo — il piano di un altro condominio, sotto il
    * proprio, si pubblicava e mandava le notifiche ai suoi condòmini. Il vincolo è la coppia esercizio > piano.
    */
   public function publishSilent(Request $request, Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate)
    {
        try {
            $idPiano = (int) $pianoRate->id;

            // 1. Trova gli eventi usando la ricerca robusta per ID Piano
            $hiddenEvents = Evento::where('tipo', EventoTipo::SCADENZA_RATA_CONDOMINO->value)
                ->where(function($q) use ($idPiano) {
                    $q->where('meta->context->piano_rate_id', $idPiano)
                      ->orWhere('meta->context->piano_rate_id', (string) $idPiano);
                })
                ->where('visibility', VisibilityStatus::HIDDEN->value)
                ->get();

            if ($hiddenEvents->isEmpty()) {
                return back()->with($this->flashWarning('Nessuna rata nascosta trovata.'));
            }

            DB::transaction(function () use ($hiddenEvents) {
                $rataIds = [];

                foreach ($hiddenEvents as $evt) {
                    $meta = $evt->meta;
                    $meta['is_published'] = true; 
                    $meta['is_emitted'] = true; // Assicuriamoci che ci sia!
                    
                    // RETE DI SICUREZZA: Controlliamo se nel frattempo l'admin l'ha incassata
                    if (isset($meta['context']['rata_id'])) {
                        $rataId = $meta['context']['rata_id'];
                        $rataIds[] = $rataId;
                        
                        // FIX: Recuperiamo l'ID di Marta (o del condomino a cui appartiene l'evento)
                        $pagante = $evt->anagrafiche->first();

                        if ($pagante) {
                            // Lo stato si calcola come per ogni altro scrittore del promemoria: dalle quote **di questa
                            // persona** su questa rata, con il segno. Un credito puro resta un credito, non si azzera.
                            $eventiRata = app(EventiRataCondomino::class);
                            $quotePersona = RataQuote::with('immobile')->where('rata_id', $rataId)->where('anagrafica_id', $pagante->id)->get();
                            $evt->meta = $meta;
                            $eventiRata->applica($evt, $eventiRata->stato($quotePersona), $pagante, Rata::findOrFail($rataId));
                            $meta = $evt->meta;
                        }
                    }

                    $evt->update([
                        'meta' => $meta,
                        'visibility' => VisibilityStatus::PRIVATE->value 
                    ]);
                }

                // 2. Notifiche (Dispatch una sola volta per rata id)
                foreach (array_unique($rataIds) as $rId) {
                    $rata = Rata::find($rId);
                    if ($rata) RataEmessa::dispatch($rata);
                }
            });

            return back()->with($this->flashSuccess('Rate pubblicate! I condòmini ora le vedono.'));

        } catch (\Throwable $e) {
            Log::error("Errore sblocco rate: " . $e->getMessage());
            return back()->with($this->flashError('Errore durante la pubblicazione.'));
        }
    }
}