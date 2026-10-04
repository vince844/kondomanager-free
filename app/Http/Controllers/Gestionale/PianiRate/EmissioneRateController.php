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
use App\Services\Gestionale\InboxService;
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
        // ricalcolo (non lo hanno conguagliato, decisione 21). Emesse così, quelle quote resterebbero a chi esce anche per i
        // giorni dopo, e un passaggio successivo le tratterebbe come passate. Si guarda lo stato del piano all'ora di ogni
        // passaggio, non quello di adesso: un incasso arrivato dopo il passaggio non lo ha conguagliato (rilievo R1).
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

        // Decisione 34 (1.11.0-beta.42): «emessa» vuol dire a giornale. Le rate che vanno a giornale qui, e quelle che non
        // hanno quote da pagare e restano in bozza, con il numero per il messaggio.
        $aGiornale = [];
        $senzaQuote = [];
        $giaEmesse = [];
        $restanoEmesse = [];
        $presiPrima = [];

        try {
            DB::transaction(function () use ($request, $condominio, $pianoRate, $esercizio, $contoCrediti, $contoGestione, $contoPassateGestioni, $inviaNotifiche, &$aGiornale, &$senzaQuote, &$giaEmesse, &$restanoEmesse, &$presiPrima) {
                
                // Le quote delle rate si bloccano come PRIMA istruzione: la scrittura va intestata al titolare di adesso, e
                // i promemoria del portale riscritti più sotto con gli importi di adesso. Un passaggio che riassegna le bozze,
                // o il suo annullamento (1.11.0-beta.37), può cambiare nome e importo delle quote mentre l'emissione è in
                // volo; su MySQL l'istantanea delle letture nasce alla prima SELECT senza lock, quindi il lock va prima di
                // tutto (giro di verifica della Fase 1-bis, C-R2). Non si bloccano prima le righe di `rate`: la
                // registrazione blocca quota e rata insieme, e l'ordine inverso incrocerebbe le due operazioni.
                DB::table('rate_quote')->whereIn('rata_id', (array) $request->rate_ids)->lockForUpdate()->pluck('id');

                $rateSelezionate = Rata::with('rateQuote')
                    ->where('piano_rate_id', $pianoRate->id)
                    ->whereIn('id', $request->rate_ids)
                    ->get();

                foreach ($rateSelezionate as $rata) {
                    if ($rata->rateQuote->whereNotNull('scrittura_contabile_id')->isNotEmpty()) {
                        // Già a giornale: un doppio invio, o la pagina aperta in due schede. Il messaggio la nomina (rilievo S2/D7).
                        $giaEmesse[(int) $rata->id] = (int) $rata->numero_rata;
                        continue;
                    }

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
                    // Il condòmino deve l'INTERA quota, quindi il DARE su Crediti v/Condòmini è
                    // sempre `importo` pieno. A cambiare è la contropartita, perché una quota può
                    // portare dentro due cose di competenza diversa, e lo snapshot lo dice già:
                    //
                    //     importo = quota_pura_gestione + saldo_usato
                    //
                    // `quota_pura_gestione` è la spesa deliberata per QUESTO esercizio e chiude su
                    // Gestione Rate. `saldo_usato` è il riporto da esercizi precedenti — la Rata 0
                    // è fatta solo di quello — e non è un provento dell'anno: chiude sul Fondo
                    // Passate Gestioni, la stessa contropartita con cui l'apertura di cassa porta
                    // dentro una posizione anteriore (RegistraAperturaCassaAction:70-73).
                    //
                    // ⚠️ **Perché la componente di riporto si DERIVA per differenza** invece di
                    // leggere `saldo_usato` dallo snapshot: così `DARE = AVERE` vale per
                    // costruzione, anche su una quota il cui snapshot non quadri. Fidarsi di due
                    // numeri scritti da qualcun altro significa poter emettere una scrittura
                    // sbilanciata, e il DoubleEntryValidator la rifiuterebbe a fine rata, dopo aver
                    // già bruciato il protocollo.
                    foreach ($rata->rateQuote as $quota) {

                        $importoQuota = (int) $quota->importo;

                        if ($importoQuota <= 0) continue;

                        // ⚠️ `regole_calcolo` ha il cast `'json'` sul Model, quindi qui arriva un
                        // ARRAY. Fino alla beta.62 questa lettura faceva `(object) $array` e poi
                        // `isset($json->importi->quota_pura_gestione)`: il cast a oggetto è
                        // SUPERFICIALE, `$json->importi` restava un array e l'isset era sempre
                        // falso. Il ramo non si è mai eseguito, e il riporto finiva su Gestione
                        // Rate insieme al deliberato. Invisibile sulle rate ordinarie, dove i due
                        // numeri coincidono; visibile solo dove divergono, cioè sulla Rata 0.
                        $componenteGestione = $importoQuota;
                        $regole = $quota->regole_calcolo;

                        if (is_string($regole)) {
                            $regole = json_decode($regole, true);
                        }

                        if (is_array($regole) && isset($regole['importi']['quota_pura_gestione'])) {
                            $componenteGestione = (int) $regole['importi']['quota_pura_gestione'];
                        }

                        $componentePregresso = $importoQuota - $componenteGestione;

                        $scrittura->righe()->create([
                            'conto_contabile_id' => $contoCrediti->id,
                            'anagrafica_id'      => $quota->anagrafica_id,
                            'immobile_id'        => $quota->immobile_id,
                            'rata_id'            => $rata->id,
                            'tipo_riga'          => 'dare',
                            'importo'            => $importoQuota,
                            'note'               => "Quota " . $rata->descrizione
                        ]);

                        $quota->update(['scrittura_contabile_id' => $scrittura->id]);

                        $totaleRataCentesimi     += $componenteGestione;
                        $totalePregressoCentesimi += $componentePregresso;
                    }

                    // 3. Chiusura in Avere, su due conti quando la rata porta un riporto.
                    //
                    // I due totali possono essere NEGATIVI: un condòmino che arriva a credito ha
                    // `saldo_usato < 0`, quindi la sua componente di riporto riduce il debito. Un
                    // totale negativo si scrive nel verso opposto, e la quadratura regge comunque
                    // perché il DARE delle quote è già la somma algebrica delle due componenti.
                    $totaleDareQuote = $totaleRataCentesimi + $totalePregressoCentesimi;

                    if ($totaleDareQuote > 0) {

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
                        // Nessuna quota da emettere (es. rata di soli conguagli a credito):
                        // la testata resterebbe una scrittura SENZA RIGHE, che il
                        // DoubleEntryValidator approva (0 = 0) ma che sporca il giornale e
                        // brucia un numero di protocollo. Peggio: nessuna quota riceve
                        // scrittura_contabile_id, quindi il guard anti-doppia-emissione non
                        // scatta e ogni nuova emissione ne accumula un'altra.
                        $scrittura->forceDelete();
                        $senzaQuote[(int) $rata->id] = (int) $rata->numero_rata;
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

                // «Emessa» solo la rata che è andata a giornale (decisione 34): una rata senza quote da pagare non ha prodotto
                // scritture e resta in bozza — anche se una versione prima della beta.42 l'aveva già segnata «emessa».
                // Rilievo W1 del giro sulle correzioni: sui dati della .41 un passaggio registrato allora può aver preso nel conguaglio
                // proprio una di queste rate «emesse» senza scrittura (`PianoRate::presoSoloInParteDa`). Riportarla in bozza toglierebbe
                // al piano l'unica traccia di quel conguaglio, e il ricalcolo che ne segue farebbe pagare la coppia due volte: resta
                // «emessa», e l'esito lo dice.
                $presiPrima = collect($pianoRate->passaggiCheLoHannoConguagliato())->filter(fn ($s) => $pianoRate->presoSoloInParteDa($s))->values()->all();
                if ($presiPrima !== []) {
                    $restanoEmesse = Rata::whereIn('id', array_keys($senzaQuote))->where('stato', 'emessa')->pluck('id')
                        ->mapWithKeys(fn ($id) => [(int) $id => $senzaQuote[(int) $id]])->all();
                    $senzaQuote = array_diff_key($senzaQuote, $restanoEmesse);
                }
                Rata::whereIn('id', $aGiornale)->update(['stato' => 'emessa']);
                Rata::whereIn('id', array_keys($senzaQuote))->where('stato', 'emessa')->update(['stato' => 'bozza']);
            });

            InboxService::clearAdminCache();

            $esito = $this->esitoEmissione(count($aGiornale), $senzaQuote, $giaEmesse, $restanoEmesse, $presiPrima);

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
                return back()->with($this->flashError(
                    'Questa rata porta un riporto da esercizi precedenti, ma nel piano dei conti di '
                    .'questo condominio manca il «Fondo Passate Gestioni» (2301). Ricrealo dal piano '
                    .'dei conti e riprova: senza, il riporto finirebbe fra le entrate dell\'anno.'
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
        $voci = PianoRate::vociDeiPassaggi(PianoRate::senzaPertinenze($passaggi));
        $uno = count($voci) === 1;
        $nomi = collect($passaggi)->map(fn (Subentro $p) => $p->uscente?->nome)->filter()->unique()->values();
        $capoversi = [
            ($uno ? 'Le quote di questo piano sono state calcolate prima di questo passaggio:' : 'Le quote di questo piano sono state calcolate prima di questi passaggi:')
                . "\n" . self::elencoPuntato($voci),
            sprintf('Ricalcola il piano prima di emettere, perché tenga conto %s. Se le voci o la data della delibera lasciano quelle quote a %s, il ricalcolo dà le stesse cifre.',
                $uno ? 'del passaggio' : 'dei passaggi', $nomi->isEmpty() ? 'chi le aveva' : self::nomiInFila($nomi)),
        ];
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
        $riserve = PianoRate::senzaPertinenze(collect($passaggi)->filter(fn (Subentro $p) => $p->riservaUsufrutto() && ($p->registro['ordinaria_dopo_atto'] ?? null) === Subentro::ORDINARIA_ALL_USUFRUTTUARIO));
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
     * Che cosa ha fatto l'emissione, contato sulle rate andate a giornale e non su quelle chieste (decisione 34): una rata
     * senza quote da pagare resta in bozza, e il messaggio la nomina.
     *
     * @param array<int, int> $senzaQuote id della rata => numero della rata
     * @param array<int, int> $giaEmesse id della rata => numero della rata, per le rate che erano già a giornale
     * @return array{titolo: string, testo: string}
     */
    private function esitoEmissione(int $emesse, array $senzaQuote, array $giaEmesse = [], array $restanoEmesse = [], array $presiPrima = []): array
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

        if ($restanoEmesse !== []) {
            $numeri = collect($restanoEmesse)->sort()->values();
            $frasi[] = sprintf('%s a giornale non c\'è niente da portare; %s segnat%s come emess%s, perché il conguaglio %s, registrato con una versione di prima, %sha pres%s così.',
                $numeri->count() === 1 ? "La rata {$numeri[0]} non ha quote da pagare e" : 'Le rate ' . $numeri->join(', ', ' e ') . ' non hanno quote da pagare e',
                $numeri->count() === 1 ? 'resta' : 'restano', $numeri->count() === 1 ? 'a' : 'e', $numeri->count() === 1 ? 'a' : 'e',
                PianoRate::descriviPassaggi($presiPrima), $numeri->count() === 1 ? 'l\'' : 'le ', $numeri->count() === 1 ? 'a' : 'e');
        }

        return ['titolo' => match (true) { $emesse > 0 => 'Emissione completata', $senzaQuote === [] && $restanoEmesse === [] && $giaEmesse !== [] => 'Rate già emesse', default => 'Nessuna rata emessa' }, 'testo' => implode(' ', $frasi)];
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
                
                // 1. Sgancio e rimozione Scritture (Perfetto, non toccato)
                $scrittureIds = $rata->rateQuote()->pluck('scrittura_contabile_id')->filter()->unique();
                $rata->rateQuote()->update(['scrittura_contabile_id' => null]);

                if ($scrittureIds->isNotEmpty()) {
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
                        $paganteId = $evt->anagrafiche->first()->id ?? null;
                        
                        if ($paganteId) {
                            // Ora sommiamo SOLO i soldi di questo specifico condomino
                            $importoPagato = DB::table('rate_quote')
                                ->where('rata_id', $rataId)
                                ->where('anagrafica_id', $paganteId) 
                                ->sum('importo_pagato');
                                
                            $importoTotale = DB::table('rate_quote')
                                ->where('rata_id', $rataId)
                                ->where('anagrafica_id', $paganteId) 
                                ->sum('importo');

                            if ($importoPagato > 0 && $importoPagato >= $importoTotale) {
                                $meta['status'] = 'paid'; 
                            } elseif ($importoPagato > 0) {
                                $meta['status'] = 'partial'; 
                            }
                            
                            $meta['importo_pagato'] = $importoPagato;
                            $meta['importo_restante'] = max(0, $importoTotale - $importoPagato);
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