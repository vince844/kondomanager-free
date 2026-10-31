<?php

namespace App\Models\Gestionale;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany; 
use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Immobile;
use Database\Factories\Gestionale\RataQuoteFactory;

class RataQuote extends Model 
{
    use HasFactory;

    protected $table = 'rate_quote';

    protected $fillable = [
        'rata_id',
        'anagrafica_id',
        'immobile_id',
        'importo',
        'importo_pagato',
        'stato',
        'data_scadenza',
        'data_pagamento',
        'riferimento_pagamento',
        'scrittura_contabile_id', 
        'note',
        'regole_calcolo'
    ];

    protected $casts = [
        'importo'        => 'integer',
        'importo_pagato' => 'integer',
        'data_scadenza'  => 'date',
        'data_pagamento' => 'date',
        'regole_calcolo' => 'json'
    ];

    // === RELAZIONI ===
    public function rata(): BelongsTo
    {
        return $this->belongsTo(Rata::class, 'rata_id');
    }

    public function anagrafica(): BelongsTo
    {
        return $this->belongsTo(Anagrafica::class);
    }

    public function immobile(): BelongsTo
    {
        return $this->belongsTo(Immobile::class);
    }

    /**
     * NUOVA RELAZIONE FONDAMENTALE (Pivot)
     * Collega la quota ai movimenti bancari (scritture) che l'hanno pagata.
     */
    public function pagamenti(): BelongsToMany
    {
        return $this->belongsToMany(
            ScritturaContabile::class, 
            'quota_scrittura',      // Tabella pivot corretta
            'rate_quota_id',        // FK di questo modello sulla pivot
            'scrittura_contabile_id'// FK dell'altro modello sulla pivot
        )
        ->withPivot(['importo_pagato', 'data_pagamento'])
        ->withTimestamps();
    }

    // === ACCESSORS (Mantieni pure i tuoi helper UI, sono ottimi) ===
    public function getImportoFormattatoAttribute(): string
    {
        return MoneyHelper::format($this->importo);
    }

    public function getImportoPagatoFormattatoAttribute(): string
    {
        return MoneyHelper::format($this->importo_pagato);
    }

    public function getImportoResiduoAttribute(): int
    {
        return max(0, $this->importo - $this->importo_pagato);
    }

    /**
     * Credito che questa quota mette a disposizione per le compensazioni.
     * Due forme possibili:
     * - quota a importo negativo: la parte non ancora consumata. Il credito può venire da un saldo a credito riportato da un
     *   esercizio precedente, da una spesa negativa dell'anno (le entrate del piano superano le spese di quell'unità: una voce di
     *   tipo «entrata» ripartita su una tabella dove l'unità ha poche o nessuna spesa), o da tutte e due: le due parti stanno su
     *   conti diversi finché la quota non è a giornale ({@see creditoSuPassate()});
     * - quota strapagata (importo_pagato > importo): l'eccedenza incassata.
     */
    public function getCreditoDisponibileAttribute(): int
    {
        if ($this->importo < 0) {
            return max(0, abs($this->importo) - abs($this->importo_pagato));
        }

        return max(0, $this->importo_pagato - $this->importo);
    }

    public function getImportoResiduoFormattatoAttribute(): string
    {
        return MoneyHelper::format($this->importo_residuo);
    }

    // === LA CONTABILITÀ DEL CREDITO (decisioni 71, 75 e 76, 1.11.0-beta.49) ===
    //
    // Un criterio solo, con un nome solo, per tutti quelli che scrivono o leggono il credito di una quota: l'emissione, l'incasso
    // (prelievo e riaccredito), il rimborso, lo storno, l'estratto conto e il comando `kondomanager:riallinea-crediti`. Due copie
    // della stessa regola sono il difetto che la beta corregge: l'emissione saltava le quote a credito e l'incasso le prelevava
    // da «Crediti verso condomini», dove nessuno le aveva scritte (DC1 e DC2 del laboratorio).

    /**
     * Il conto in cui sta adesso il credito di questa quota, per ruolo. Un credito riportato da un esercizio precedente (il riporto
     * negativo di una quota a credito) entra nel giornale con l'emissione della sua rata, che lo scrive in AVERE su «Crediti verso
     * condomini» (decisione 71): finché la quota non ha scrittura sta ancora fra le «Passate gestioni», e da lì escono la
     * compensazione e il rimborso. Ogni altro credito — una quota a credito già a giornale, una quota strapagata, la spesa negativa
     * di una quota delle entrate dell'anno — sta fra i crediti verso condomini.
     *
     * Si decide dal riporto, non dal segno (rilievo K2 della Fase 1-bis della beta.49): una voce di tipo «entrata» rende negativa la
     * quota senza nessun credito riportato, e quel credito non è mai entrato in «Passate gestioni». Con una quota che ha tutte e
     * due le parti il ruolo dice dove sta la parte riportata; quanto ne resta lì lo dice {@see creditoSuPassate()}.
     *
     * @return 'passate_gestioni'|'crediti_condomini'
     */
    public function ruoloDelCredito(): string
    {
        return (int) $this->importo < 0 && $this->scrittura_contabile_id === null && $this->componenti()['riporto'] < 0
            ? 'passate_gestioni'
            : 'crediti_condomini';
    }

    /**
     * Quanta parte del credito disponibile di questa quota sta ancora fra le «Passate gestioni» (rilievo K2 della Fase 1-bis della
     * beta.49): il riporto a credito non ancora uscito da lì, al più il credito disponibile. Zero per una quota già a giornale, per
     * una quota a debito o strapagata, e per la spesa negativa delle entrate dell'anno, che sta fra i crediti verso condomini come
     * un incasso su una quota ancora in bozza: l'AVERE dell'emissione la chiude. Il resto del credito disponibile esce da «Crediti
     * verso condomini». La usano il prelievo dell'incasso e il rimborso, che dividono il movimento sui due conti.
     *
     * Senza il conto (`$contoPassateId` nullo) dice comunque quanta parte dovrebbe uscire da lì: chi la chiama si ferma
     * ({@see \App\Exceptions\Gestionale\ContoPassateGestioniMancanteException}), invece di ricadere su «Crediti» in silenzio.
     */
    public function creditoSuPassate(?int $contoPassateId): int
    {
        if ((int) $this->importo >= 0 || $this->scrittura_contabile_id !== null) {
            return 0;
        }
        $riporto = $this->componenti()['riporto'];
        if ($riporto >= 0) {
            return 0;
        }
        $uscito = $contoPassateId === null ? 0 : $this->creditoUscitoDaPassate($contoPassateId);

        return max(0, min((int) $this->credito_disponibile, -$riporto - $uscito));
    }

    /**
     * Le due componenti della quota: la spesa dell'esercizio (`quota_pura_gestione`) e il riporto da esercizi precedenti, per
     * differenza (così dare e avere quadrano per costruzione, anche su un dettaglio che non quadri). `dettaglio` dice se la quota
     * ha il dettaglio del calcolo (`regole_calcolo`, dalla 1.8.0). Senza: una quota a debito è tutta spesa, come l'emissione l'ha
     * sempre trattata; una quota a credito, o a zero, non si separa, e chi la deve scrivere si ferma (decisione 76.3, ritoccata
     * l'11/10/2026: si ferma la sola quota). Una quota a zero della 1.7.x non è mai vuota — la 1.7.0 saltava le unità senza spesa —
     * quindi è un credito riportato pari alla spesa, e il programma non indovina quanta parte è spesa e quanta credito.
     *
     * ⚠️ `regole_calcolo` ha il cast `'json'`: arriva un array. Fino alla beta.62 della 1.10 l'emissione lo leggeva come oggetto
     * (`(object) $array`, un cast superficiale) e il ramo della spesa non scattava mai: il riporto finiva su «Gestione rate».
     *
     * @return array{spesa: int, riporto: int, dettaglio: bool}
     */
    public function componenti(): array
    {
        $importo = (int) $this->importo;
        $regole = is_string($this->regole_calcolo) ? json_decode($this->regole_calcolo, true) : $this->regole_calcolo;
        $dettaglio = is_array($regole) && isset($regole['importi']['quota_pura_gestione']);
        $spesa = $dettaglio ? (int) $regole['importi']['quota_pura_gestione'] : max(0, $importo);

        return ['spesa' => $spesa, 'riporto' => $importo - $spesa, 'dettaglio' => $dettaglio];
    }

    /**
     * La quota che nessuno porta a giornale (decisione 76.3, ritoccata l'11/10/2026): a credito, o a zero, senza il dettaglio del
     * calcolo ({@see componenti()}). La tengono ferma, con lo stesso criterio, l'emissione, il comando di riallineamento e lo storno
     * (giro stretto sulle correzioni della Fase 1-bis della beta.49, G1: lo storno la portava a giornale con spesa 0 e tutto
     * l'importo come riporto, la divisione che la 76.3 vieta di indovinare). Il suo credito resta fra le «Passate gestioni».
     */
    public function fermaSenzaDettaglio(): bool
    {
        return (int) $this->importo <= 0 && ! $this->componenti()['dettaglio'];
    }

    /**
     * L'importo della riga in DARE che l'emissione scrive per questa quota (0 se non ne scrive): l'importo intero per una quota a
     * debito, la sola spesa per una quota che il credito riportato porta a zero o sotto. Serve all'estratto conto per abbinare la
     * riga dell'emissione alla sua quota quando la persona ne ha due sulla stessa rata e unità (decisione 25).
     */
    public function dareDiEmissione(): int
    {
        return (int) $this->importo > 0 ? (int) $this->importo : max(0, $this->componenti()['spesa']);
    }

    /**
     * Le righe che l'emissione scrive su «Crediti verso condomini» a nome della persona, come [verso, importo] (decisione 71). Una
     * quota a debito: una riga in DARE per l'importo intero. Una quota che il credito riportato porta a zero o sotto: la spesa in
     * DARE e il credito riportato in AVERE, meno la parte già uscita da «Passate gestioni» mentre la quota era senza scrittura
     * (compensata o rimborsata: quella parte non è più un credito da portare). Nessuna riga se non c'è niente da portare.
     *
     * Una spesa negativa (le entrate dell'anno di una voce di tipo «entrata», rilievo K2) va in AVERE per intero, prima del
     * riporto: non è un credito riportato e da «Passate gestioni» non esce mai. Se è stata usata o rimborsata con la quota ancora
     * in bozza, quel movimento è passato da «Crediti verso condomini» ({@see creditoSuPassate()}), e questo AVERE lo chiude.
     *
     * @param int $giaUscito il credito di questa quota già uscito da «Passate gestioni» ({@see creditoUscitoDaPassate()})
     * @return list<array{0: 'dare'|'avere', 1: int}>
     */
    public function righeDiEmissione(int $giaUscito = 0): array
    {
        if ((int) $this->importo > 0) {
            return [['dare', (int) $this->importo]];
        }
        ['spesa' => $spesa, 'riporto' => $riporto] = $this->componenti();
        $riporto += max(0, min($giaUscito, -$riporto));
        $righe = [];
        foreach ([$spesa, $riporto] as $componente) {
            if ($componente !== 0) {
                $righe[] = [$componente > 0 ? 'dare' : 'avere', abs($componente)];
            }
        }

        return $righe;
    }

    /**
     * La nota di una riga che l'emissione scrive a nome della persona ({@see righeDiEmissione()}, nell'ordine in cui le dà): una
     * spesa negativa è una quota delle entrate dell'anno, non un credito riportato (rilievo K3 della Fase 1-bis della beta.49).
     *
     * @param int $indice la posizione della riga fra quelle della quota
     * @param 'dare'|'avere' $verso
     */
    public function notaDiEmissione(int $indice, string $verso, string $descrizione): string
    {
        if ($verso === 'dare') {
            return 'Quota ' . $descrizione;
        }
        $spesa = $this->componenti()['spesa'];

        return $indice === 0 && $spesa < 0 && (int) $this->importo <= 0
            ? 'Quota delle entrate dell\'anno — ' . $descrizione
            : 'Credito riportato usato — quota ' . $descrizione;
    }

    /**
     * Quanto del credito di questa quota è già uscito da «Passate gestioni», letto dal giornale (contrasto 1 del progetto della
     * beta.49): le righe su quel conto a nome della persona, sulla rata e sull'unità della quota, fuori dall'emissione —
     * compensazioni, riaccrediti, rimborsi, i loro storni, le rettifiche del riallineamento. Dal giornale e non da
     * `importo_pagato`, perché il credito usato con la 1.9 e la 1.10 è uscito da «Crediti verso condomini» (DC1), e uno storno
     * dopo l'emissione lo rimette fra le «Passate gestioni»: in tutti e due i casi da lì non è uscito niente, e all'emissione il
     * credito va portato intero. Con due regole diverse emissione e riallineamento contavano due volte la stessa compensazione.
     */
    public function creditoUscitoDaPassate(int $contoPassateId): int
    {
        if ((int) $this->importo > 0) {
            return 0;
        }

        return max(0, (int) RigaScrittura::where('conto_contabile_id', $contoPassateId)
            ->where('anagrafica_id', $this->anagrafica_id)
            ->where('rata_id', $this->rata_id)
            ->when($this->immobile_id === null, fn ($q) => $q->whereNull('immobile_id'), fn ($q) => $q->where('immobile_id', $this->immobile_id))
            ->whereHas('scrittura', fn ($s) => $s->where(fn ($t) => $t->whereNull('tipo_movimento')->orWhere('tipo_movimento', '!=', 'emissione_rata')))
            ->sum(\Illuminate\Support\Facades\DB::raw("CASE WHEN tipo_riga = 'dare' THEN importo ELSE -importo END")));
    }

    /**
     * La nota della riga che chiude l'anticipazione della spesa ad personam nell'emissione (Coda 313), seguita dalla descrizione della
     * rata. La scrivono i tre scrittori dell'emissione ({@see scritturaDiEmissione()}).
     */
    public const NOTA_CHIUSURA_ANTICIPAZIONE = 'Chiusura anticipazione spesa ad personam — ';

    /**
     * La parte ad personam di ogni quota data, in centesimi, per id della quota (Coda 313, decisione 153.1): la spesa di una sola
     * unità che la fattura ha già scritto su «Crediti verso condomini» con l'unità («Anticipazione spesa ad personam (Art. 63)»), e
     * che l'emissione deve chiudere invece di contarla fra le entrate di «Gestione rate».
     *
     * Da dove si legge: la chiave `regole_calcolo.importi.addebito_diretto`, che la generazione scrive dalla 1.11.0-beta.59 con la
     * fettina della quota (`GenerateRateQuotesAction`); per una quota generata prima, il registro del riparto del piano
     * (`righe_riparto`, tipo `ad_personam`, del soggetto del riparto: `riassegnazione.righe_di`, o la persona della quota) spalmato con
     * la stessa fettina (`GenerateRateQuotesAction::fettina`, con il numero della rata e delle rate dello snapshot). Senza chiave né
     * registro (i piani generati prima della 1.11.0-beta.29) è zero, e l'emissione resta quella di prima.
     *
     * Mai sotto zero: una parte negativa (una riga ad personam in diminuzione che supera le altre) resta com'è, un limite dichiarato.
     * Può superare la spesa della quota: se il resto della spesa del soggetto è negativo (una rettifica comune), la quota chiede meno
     * della sua parte ad personam, e «Gestione rate» riceve quella differenza in DARE; l'anticipazione si chiude per intero. Zero per una
     * quota senza unità.
     *
     * `$registro` (piano|persona|unità => centesimi) prende il posto del registro del database: lo passa il rilevamento delle emissioni
     * già scritte per un piano senza `righe_riparto`, con le righe della ricostruzione (`RiallineaCreditiService`).
     *
     * @param iterable<RataQuote> $quote
     * @param array<string, int>|null $registro
     * @return array<int, int>
     */
    public static function partiAdPersonam(iterable $quote, ?array $registro = null): array
    {
        $quote = collect($quote);
        $regole = $quote->mapWithKeys(fn (RataQuote $q) => [(int) $q->id => (is_string($q->regole_calcolo) ? json_decode($q->regole_calcolo, true) : $q->regole_calcolo) ?: []]);
        $senzaChiave = $quote->filter(fn (RataQuote $q) => $q->immobile_id !== null && ! array_key_exists('addebito_diretto', (array) ($regole[(int) $q->id]['importi'] ?? [])));

        $pianoDellaRata = [];
        if ($senzaChiave->isNotEmpty()) {
            $pianoDellaRata = \Illuminate\Support\Facades\DB::table('rate')->whereIn('id', $senzaChiave->pluck('rata_id')->unique()->values())->pluck('piano_rate_id', 'id')->all();
        }
        if ($registro === null) {
            $registro = $pianoDellaRata === [] ? [] : self::registroAdPersonam(array_values(array_unique($pianoDellaRata)), conRicostruzione: true);
        }

        $esito = [];
        foreach ($quote as $quota) {
            $sue = $regole[(int) $quota->id];
            if ($quota->immobile_id === null) {
                $parte = 0;
            } elseif (array_key_exists('addebito_diretto', (array) ($sue['importi'] ?? []))) {
                $parte = (int) $sue['importi']['addebito_diretto'];
            } elseif (isset($sue['divisa_da'])) {
                // La quota del solo pregresso che un passaggio ha lasciato a chi esce (`RegistraSubentroAction::riassegnaBozze`) prima
                // che la chiave ci fosse: non ha spesa dell'anno, quindi nemmeno una parte ad personam. Il registro è di chi esce, come
                // per la quota passata a chi entra (`righe_di`): letta di nuovo qui, la stessa parte si chiuderebbe due volte.
                $parte = 0;
            } else {
                $soggetto = (int) ($sue['riassegnazione']['righe_di'] ?? $quota->anagrafica_id);
                $totale = $registro[($pianoDellaRata[$quota->rata_id] ?? '') . '|' . $soggetto . '|' . $quota->immobile_id] ?? 0;
                $numero = (int) ($sue['parametri']['numero_rata'] ?? 0);
                $rate = (int) ($sue['parametri']['totale_rate_piano'] ?? 0);
                $parte = $numero > 0 && $rate > 0 ? \App\Actions\PianoRate\GenerateRateQuotesAction::fettina($totale, $rate, $numero) : 0;
            }
            $esito[(int) $quota->id] = max(0, $parte);
        }

        return $esito;
    }

    /**
     * Le righe `ad_personam` del registro del riparto dei piani dati, sommate per piano, persona e unità (piano|persona|unità =>
     * centesimi): la spesa di una sola unità che ogni soggetto del riparto ha nel piano (Coda 313).
     *
     * @param list<int> $pianiIds
     * @return array<string, int>
     */
    public static function registroAdPersonam(array $pianiIds, bool $conRicostruzione = false): array
    {
        $registro = [];
        RigaRiparto::whereIn('piano_rate_id', $pianiIds)->where('tipo', RigaRiparto::TIPO_AD_PERSONAM)
            ->whereNotNull('anagrafica_id')->whereNotNull('immobile_id')
            ->get(['piano_rate_id', 'anagrafica_id', 'immobile_id', 'importo'])
            ->each(function (RigaRiparto $r) use (&$registro) {
                $chiave = $r->piano_rate_id . '|' . $r->anagrafica_id . '|' . $r->immobile_id;
                $registro[$chiave] = ($registro[$chiave] ?? 0) + (int) $r->importo;
            });

        // Decisione 154.2: i piani senza il registro (generati con la 1.9 o la 1.10) usano la ricostruzione controllata del rilevamento,
        // solo se torna con le quote registrate; altrimenti niente, e l'emissione resta quella di prima.
        if ($conRicostruzione && $pianiIds !== []) {
            $conRegistro = RigaRiparto::whereIn('piano_rate_id', $pianiIds)->distinct()->pluck('piano_rate_id')->map(fn ($id) => (int) $id)->all();
            foreach (array_diff(array_map('intval', $pianiIds), $conRegistro) as $pianoId) {
                $piano = PianoRate::find($pianoId);
                $ricostruito = $piano === null ? null : self::registroRicostruito($piano);
                foreach ($ricostruito ?? [] as $chiave => $importo) {
                    $registro[$chiave] = ($registro[$chiave] ?? 0) + $importo;
                }
            }
        }

        return $registro;
    }

    /**
     * Le righe ad personam della ricostruzione di un piano senza `righe_riparto` (le stabili 1.9 e 1.10), con la riga di fattura da cui
     * vengono, o null se il piano non si ricostruisce o la ricostruzione non torna con le quote registrate (Coda 313, decisione 154.2).
     *
     * Si ricostruisce solo un piano straordinario con una riga ad personam fra le sue fatture (`DettaglioRiparto::perPiano`, il motore in
     * sola lettura con le titolarità di oggi), e la ricostruzione si usa solo se, per ogni soggetto (persona e unità), la somma delle sue
     * righe è la somma della spesa delle quote di cui è il soggetto del riparto (`riassegnazione.righe_di`, o la persona della quota):
     * l'invariante di `RigaRiparto`. Un motore che si ferma è una ricostruzione che non torna. La usano il rilevamento delle emissioni
     * già scritte (`RiallineaCreditiService`), l'emissione e le guardie dello storno e delle note di credito ({@see rateEmesseConAdPersonam()}).
     *
     * @return list<array{anagrafica_id: int, immobile_id: int, riga_fattura_id: ?int, importo: int}>|null
     */
    public static function righeRicostruiteAdPersonam(PianoRate $piano): ?array
    {
        if ($piano->tipo !== 'straordinario' || ! \Illuminate\Support\Facades\DB::table('piano_rate_fatture as prf')
            ->join('righe_fattura as rf', 'rf.fattura_passiva_id', '=', 'prf.fattura_passiva_id')
            ->where('prf.piano_rate_id', $piano->id)->whereNotNull('rf.immobile_id')->exists()) {
            return null;
        }
        try {
            $dettaglio = \App\Services\Riparto\DettaglioRiparto::perPiano($piano);
        } catch (\Throwable $e) {
            return null;
        }
        if (($dettaglio['fonte']['tipo'] ?? null) !== \App\Services\Riparto\DettaglioRiparto::RICOSTRUITO) {
            return null;
        }
        $righe = [];
        $adPersonam = [];
        foreach ($dettaglio['righe'] as $r) {
            if (($r['anagrafica_id'] ?? null) === null) {
                continue;
            }
            $chiave = (int) $r['anagrafica_id'] . '|' . ($r['immobile_id'] ?? '');
            $righe[$chiave] = ($righe[$chiave] ?? 0) + (int) $r['importo'];
            if (($r['tipo'] ?? null) === RigaRiparto::TIPO_AD_PERSONAM && ($r['immobile_id'] ?? null) !== null) {
                $adPersonam[] = ['anagrafica_id' => (int) $r['anagrafica_id'], 'immobile_id' => (int) $r['immobile_id'],
                    'riga_fattura_id' => isset($r['riga_fattura_id']) ? (int) $r['riga_fattura_id'] : null, 'importo' => (int) $r['importo']];
            }
        }
        $quote = [];
        foreach (self::whereIn('rata_id', $piano->rate()->pluck('id'))->get() as $q) {
            $regole = is_string($q->regole_calcolo) ? json_decode($q->regole_calcolo, true) : $q->regole_calcolo;
            $chiave = (int) ($regole['riassegnazione']['righe_di'] ?? $q->anagrafica_id) . '|' . ($q->immobile_id ?? '');
            $quote[$chiave] = ($quote[$chiave] ?? 0) + $q->componenti()['spesa'];
        }
        foreach (array_keys($righe + $quote) as $chiave) {
            if (($righe[$chiave] ?? 0) !== ($quote[$chiave] ?? 0)) {
                return null;
            }
        }

        return $adPersonam;
    }

    /**
     * Il registro ricostruito di un piano senza `righe_riparto` (piano|persona|unità => centesimi delle righe ad personam), o null se il
     * piano non si ricostruisce o la ricostruzione non torna ({@see righeRicostruiteAdPersonam()}).
     *
     * @return array<string, int>|null
     */
    public static function registroRicostruito(PianoRate $piano): ?array
    {
        $righe = self::righeRicostruiteAdPersonam($piano);
        if ($righe === null) {
            return null;
        }
        $registro = [];
        foreach ($righe as $r) {
            $chiave = $piano->id . '|' . $r['anagrafica_id'] . '|' . $r['immobile_id'];
            $registro[$chiave] = ($registro[$chiave] ?? 0) + $r['importo'];
        }

        return $registro;
    }

    /**
     * Le righe che chiudono l'anticipazione della spesa ad personam in un'emissione, una per unità (Coda 313): la somma della parte
     * `anticipazione` delle quote date ({@see scritturaDiEmissione()}), per unità, solo dove è maggiore di zero.
     *
     * @param array<int, array{anticipazione: int}> $perQuota l'esito di {@see scritturaDiEmissione()}, per id della quota
     * @param iterable<RataQuote> $quote le quote che la scrittura porta a giornale
     * @return array<int, int> per id dell'unità
     */
    public static function anticipazioniPerUnita(array $perQuota, iterable $quote): array
    {
        $perUnita = [];
        foreach ($quote as $quota) {
            $parte = (int) ($perQuota[(int) $quota->id]['anticipazione'] ?? 0);
            if ($parte > 0 && $quota->immobile_id !== null) {
                $perUnita[(int) $quota->immobile_id] = ($perUnita[(int) $quota->immobile_id] ?? 0) + $parte;
            }
        }

        return $perUnita;
    }

    /**
     * Le rate già emesse che chiedono una spesa ad personam sulle unità date (Coda 313, decisione 160): una rata con almeno una quota a
     * giornale, e una sua quota sull'unità con la parte ad personam maggiore di zero ({@see partiAdPersonam()}: la chiave, il registro del
     * riparto o la ricostruzione controllata). Conta la parte, non la riga di chiusura: anche le emissioni scritte fino alla 1.11.0-beta.58,
     * che la riga non l'hanno, hanno chiesto la spesa alla persona; e anche la quota rimasta fuori da una rata emessa (una quota a credito),
     * che il riallineamento o lo storno di un incasso portano a giornale dopo, con la chiusura. Con `$pianiIds` solo le rate di quei piani.
     *
     * La leggono le guardie dello storno della fattura e delle note di credito: con una di queste rate lo storno o la nota toglierebbero
     * dall'unità una spesa che le rate hanno già chiesto a qualcuno, e si rifiutano con la strada (`FatturaPassiva::motivoBloccoStorno`).
     *
     * @param list<int> $unita
     * @param list<int>|null $pianiIds
     * @return list<array{piano_id: int, piano: string, rate: list<int>, unita: list<int>}> per piano, nell'ordine degli id
     */
    public static function rateEmesseConAdPersonam(array $unita, ?array $pianiIds = null): array
    {
        $unita = array_values(array_unique(array_map('intval', $unita)));
        if ($unita === [] || $pianiIds === []) {
            return [];
        }
        $quote = self::whereIn('immobile_id', $unita)
            ->whereExists(fn ($q) => $q->select(\Illuminate\Support\Facades\DB::raw(1))->from('rate_quote as emessa')
                ->whereColumn('emessa.rata_id', 'rate_quote.rata_id')->whereNotNull('emessa.scrittura_contabile_id'))
            ->when($pianiIds !== null, fn ($q) => $q->whereIn('rata_id', \Illuminate\Support\Facades\DB::table('rate')->whereIn('piano_rate_id', $pianiIds)->select('id')))
            ->orderBy('id')->get();
        if ($quote->isEmpty()) {
            return [];
        }
        $parti = self::partiAdPersonam($quote);
        $conParte = $quote->filter(fn (RataQuote $q) => ($parti[(int) $q->id] ?? 0) > 0);
        if ($conParte->isEmpty()) {
            return [];
        }
        $rate = \Illuminate\Support\Facades\DB::table('rate')->whereIn('id', $conParte->pluck('rata_id')->unique()->values())
            ->get(['id', 'piano_rate_id', 'numero_rata'])->keyBy('id');
        $nomi = \Illuminate\Support\Facades\DB::table('piani_rate')->whereIn('id', $rate->pluck('piano_rate_id')->unique()->values())->pluck('nome', 'id');

        $voci = [];
        foreach ($conParte as $q) {
            $rata = $rate[$q->rata_id];
            $pianoId = (int) $rata->piano_rate_id;
            $voci[$pianoId] ??= ['piano_id' => $pianoId, 'piano' => (string) ($nomi[$pianoId] ?? ''), 'rate' => [], 'unita' => []];
            $voci[$pianoId]['rate'][(int) $rata->numero_rata] = (int) $rata->numero_rata;
            $voci[$pianoId]['unita'][(int) $q->immobile_id] = (int) $q->immobile_id;
        }
        ksort($voci);

        return array_values(array_map(function (array $v) {
            ksort($v['rate']);
            ksort($v['unita']);

            return ['piano_id' => $v['piano_id'], 'piano' => $v['piano'], 'rate' => array_values($v['rate']), 'unita' => array_values($v['unita'])];
        }, $voci));
    }

    /**
     * «la rata n. 1», «le rate n. 1 e 2», «le rate n. 1, 2 e 3».
     *
     * @param list<int> $numeri
     */
    public static function elencoRate(array $numeri): string
    {
        $numeri = array_values($numeri);

        return count($numeri) === 1 ? 'la rata n. ' . $numeri[0] : 'le rate n. ' . self::elenco(array_map('strval', $numeri));
    }

    /**
     * Le etichette delle unità fra caporali, nell'ordine degli id: «Int. 1», oppure «Int. 1» e «Int. 2».
     *
     * @param list<int> $unita
     */
    public static function elencoUnita(array $unita): string
    {
        $etichette = Immobile::whereIn('id', $unita)->orderBy('id')->get()->map(fn (Immobile $i) => '«' . $i->etichetta . '»')->all();

        return self::elenco($etichette);
    }

    /**
     * «il piano rate «A» ha già emesso la rata n. 1», o con più piani «i piani rate «A» (la rata n. 1) e «B» (le rate n. 1 e 2) hanno già
     * emesso rate» ({@see rateEmesseConAdPersonam()}). Il soggetto della frase, senza la maiuscola.
     *
     * @param list<array{piano: string, rate: list<int>}> $voci
     */
    public static function fraseRateEmesse(array $voci): string
    {
        if (count($voci) === 1) {
            return 'il piano rate «' . $voci[0]['piano'] . '» ha già emesso ' . self::elencoRate($voci[0]['rate']);
        }

        return 'i piani rate ' . self::elenco(array_map(fn (array $v) => '«' . $v['piano'] . '» (' . self::elencoRate($v['rate']) . ')', $voci)) . ' hanno già emesso rate';
    }

    /** @param list<string> $parti */
    private static function elenco(array $parti): string
    {
        return count($parti) <= 1 ? (string) ($parti[0] ?? '') : implode(', ', array_slice($parti, 0, -1)) . ' e ' . end($parti);
    }

    /**
     * Perché l'emissione di queste rate si rifiuta, per la spesa ad personam (Coda 313, decisione 160) — o null. Lo storno della fattura e le
     * note di credito si rifiutano finché una rata emessa ne chiede la spesa; queste sono le strade di prima, ancora aperte nei dati: la rata
     * in bozza di un piano la cui fattura è stata stornata, o rettificata da una nota collegata dopo la generazione (le versioni fino alla
     * 1.11.0-beta.58 lo lasciavano fare con il piano che aveva incassato), e la nota senza fattura registrata prima delle emissioni. La
     * chiusura dell'emissione toglierebbe dall'unità, una seconda volta, una spesa che la nota ha già tolto: l'unità resterebbe a credito
     * senza persona, in nessuna pagina. Il programma non scrive un credito al posto delle persone: rifiuta e dice la strada.
     *
     * Tre controlli, nell'ordine: (a) il piano contiene una fattura stornata con una riga sull'unità — il piano non si ricalcola
     * (`FatturaStornataNelPianoException`), si elimina e si crea di nuovo; (b) una fattura del piano con una nota collegata sull'unità, e i
     * piani che la contengono chiedono per quell'unità più di quanto la fattura ne ha al netto delle note (il registro del riparto, scritto
     * prima della nota; un piano senza registro conta tutta la riga) — dal carrello un piano nuovo la chiede al netto; (c) per unità, la
     * chiusura di questa richiesta supera l'anticipazione ancora aperta («Crediti verso condomini» con l'unità e senza persona, su tutto il
     * giornale): una nota non collegata, o un'altra scrittura, l'ha già ridotta. (a) e (b) leggono la fattura, e reggono con un'altra spesa
     * ad personam sulla stessa unità; (c) è il pavimento per quello che non dice la sua fattura.
     *
     * @param iterable<Rata> $rate le rate della richiesta, con `rateQuote`
     * @param string $fine la frase in coda al motivo (gli scrittori della quota rimasta fuori la tolgono: {@see quoteFuoriConSpesaTolta()})
     */
    public static function motivoBloccoEmissioneAdPersonam(PianoRate $piano, iterable $rate, ?int $contoPassateId, string $fine = ' Nessuna rata di questa richiesta è stata emessa.'): ?string
    {
        $chiusura = [];
        $rateDi = [];
        foreach ($rate as $rata) {
            if ($rata->rateQuote->whereNotNull('scrittura_contabile_id')->isNotEmpty()) {
                continue;
            }
            $quote = $rata->rateQuote->reject(fn (RataQuote $q) => $q->fermaSenzaDettaglio())->values();
            foreach (self::anticipazioniPerUnita(self::scritturaDiEmissione($quote, $contoPassateId), $quote) as $immobileId => $importo) {
                $chiusura[$immobileId] = ($chiusura[$immobileId] ?? 0) + $importo;
                $rateDi[$immobileId][(int) $rata->numero_rata] = (int) $rata->numero_rata;
            }
        }
        if ($chiusura === []) {
            return null;
        }
        $rateDelle = function (array $unita) use ($rateDi): array {
            $numeri = [];
            foreach ($unita as $u) {
                $numeri += $rateDi[$u] ?? [];
            }
            ksort($numeri);

            return array_values($numeri);
        };
        $chiedono = fn (array $numeri) => self::elencoRate($numeri) . (count($numeri) === 1 ? ' chiederebbe' : ' chiederebbero');
        $via = fn () => $piano->stradaDelPianoConguagliato() ?? ($piano->haIncassiRegistrati()
            ? 'storna prima gli incassi registrati sulle sue rate, annulla le emissioni, riporta il piano in bozza ed eliminalo'
            : ($piano->haRateEmesse() ? 'annulla le emissioni, riporta il piano in bozza ed eliminalo' : 'riporta il piano in bozza ed eliminalo'));

        $fatture = FatturaPassiva::whereIn('id', \Illuminate\Support\Facades\DB::table('piano_rate_fatture')->where('piano_rate_id', $piano->id)->select('fattura_passiva_id'))
            ->with('righe')->orderBy('id')->get();

        // (a) La fattura stornata.
        foreach ($fatture as $f) {
            $stato = is_object($f->stato_pagamento) ? $f->stato_pagamento->value : $f->stato_pagamento;
            if (! (($f->dati_extra['is_stornata'] ?? false) || $stato === 'stornata')) {
                continue;
            }
            $sue = array_values(array_intersect(array_keys($chiusura), $f->righe->whereNotNull('immobile_id')->pluck('immobile_id')->map(fn ($id) => (int) $id)->all()));
            if ($sue === []) {
                continue;
            }

            return "Il piano «{$piano->nome}» contiene la fattura n. {$f->numero_documento}, che è stata stornata: " . $chiedono($rateDelle($sue))
                . ' ancora ai condòmini la sua spesa ad personam su ' . self::elencoUnita($sue) . ', che lo storno ha già tolto dall\'unità. Un piano con '
                . 'una fattura stornata non si ricalcola: ' . $via() . ', poi crealo di nuovo; nel carrello le fatture stornate non compaiono più.' . $fine;
        }

        // (b) La fattura rettificata da una nota collegata dopo la generazione.
        foreach ($fatture as $f) {
            $netto = \App\Services\Gestionale\NettoNoteCollegate::perFattura((int) $f->id);
            if ($netto === null) {
                continue;
            }
            $ridotto = [];
            foreach ($netto['componenti'] as $c) {
                if ($c['immobile_id'] !== null) {
                    $ridotto[(int) $c['immobile_id']] = ($ridotto[(int) $c['immobile_id']] ?? 0) + (int) $c['importo'];
                }
            }
            foreach (array_keys($chiusura) as $u) {
                if (($ridotto[$u] ?? 0) >= 0) {
                    continue;
                }
                $righe = $f->righe->where('immobile_id', $u);
                $lordo = (int) $righe->sum(fn ($r) => (int) $r->importo_imponibile + (int) $r->importo_iva);
                $alNetto = $lordo + $ridotto[$u];
                $chiesto = self::chiestoAdPersonamDaiPiani((int) $f->id, $u, $righe->pluck('id')->map(fn ($id) => (int) $id)->all(), $lordo);
                if ($chiesto <= $alNetto) {
                    continue;
                }

                return "Il piano «{$piano->nome}» chiede per la fattura n. {$f->numero_documento} " . MoneyHelper::format($chiesto) . ' di spesa ad personam su '
                    . self::elencoUnita([$u]) . ', ma al netto ' . $f->elencoNoteCollegate('di', true) . ' la fattura ne ha ' . MoneyHelper::format(max(0, $alNetto))
                    . ' su quell\'unità: ' . $chiedono($rateDelle([$u])) . ' ai condòmini una parte che la nota ha già tolto dall\'unità. ' . ucfirst($via())
                    . ', poi crealo di nuovo: nel carrello la fattura compare al netto della nota.' . $fine;
            }
        }

        // (c) Il pavimento dell'unità.
        $crediti = ContoContabile::where('condominio_id', $piano->condominio_id)->where('ruolo', 'crediti_condomini')->value('id');
        foreach ($chiusura as $u => $importo) {
            $aperta = (int) \Illuminate\Support\Facades\DB::table('righe_scritture')
                ->join('scritture_contabili as s', 's.id', '=', 'righe_scritture.scrittura_id')->whereNull('s.deleted_at')
                ->where('righe_scritture.conto_contabile_id', $crediti)->where('righe_scritture.immobile_id', $u)->whereNull('righe_scritture.anagrafica_id')
                ->sum(\Illuminate\Support\Facades\DB::raw("CASE WHEN righe_scritture.tipo_riga = 'dare' THEN righe_scritture.importo ELSE -righe_scritture.importo END"));
            if ($importo <= max(0, $aperta)) {
                continue;
            }
            $note = FatturaPassiva::where('condominio_id', $piano->condominio_id)->where('tipo_documento', 'nota_credito')->whereNull('fattura_rettificata_id')
                ->whereHas('righe', fn ($q) => $q->where('immobile_id', $u))->orderBy('id')->get()
                ->reject(fn (FatturaPassiva $n) => $n->eNataDaStorno())->map(fn (FatturaPassiva $n) => 'n. ' . $n->numero_documento)->values()->all();
            $frase = 'L\'emissione chiuderebbe ' . MoneyHelper::format($importo) . ' della spesa ad personam di ' . self::elencoUnita([$u])
                . ', ma sull\'unità ne restano aperti ' . MoneyHelper::format(max(0, $aperta)) . ': ';
            if ($note === []) {
                return $frase . 'le scritture dell\'unità senza persona su «Crediti verso condomini» dicono dove è andata la differenza.' . $fine;
            }
            $una = count($note) === 1;

            return $frase . ($una ? 'la nota di credito ' . $note[0] . ', non collegata a una fattura, l\'ha già tolta' : 'le note di credito ' . self::elenco($note)
                . ', non collegate a una fattura, l\'hanno già tolta') . ' dall\'unità. Se ' . ($una ? 'rettifica' : 'rettificano') . ' una fattura di questo piano, '
                . $via() . ', collega ' . ($una ? 'la nota' : 'le note') . ' a quella fattura («Collega a una fattura») e crea di nuovo il piano: nel carrello la '
                . 'fattura compare al netto ' . ($una ? 'della nota' : 'delle note') . '.' . $fine;
        }

        return null;
    }

    /**
     * Le quote rimaste fuori da una rata già a giornale che i due scrittori della quota rimasta fuori — il riallineamento dei crediti
     * (`RiallineaCreditiService`, la card e il comando) e lo storno di un incasso (`StornoIncassoRateAction`) — non portano a giornale (Coda
     * 313, decisione 160, limite L1 della forma semplice): la loro chiusura toglierebbe dall'unità, una seconda volta, una spesa ad personam
     * che lo storno della fattura o una nota di credito, passati con le versioni di prima, hanno già tolto, e l'unità resterebbe a credito
     * senza persona. Gli stessi controlli dell'emissione ({@see motivoBloccoEmissioneAdPersonam()}), unità per unità: una quota senza parte
     * ad personam, o su un'altra unità, va a giornale come sempre. La quota resta fuori, con il suo credito fra le «Passate gestioni» dove la
     * cerca, come una quota senza il dettaglio del calcolo; la card la elenca fra le voci che restano, con la strada dell'emissione.
     *
     * @param iterable<RataQuote> $quote le quote ancora senza scrittura che lo scrittore porterebbe a giornale
     * @return array<int, string> il motivo per la card, per id della quota, senza il punto finale
     */
    public static function quoteFuoriConSpesaTolta(Rata $rata, iterable $quote, ?int $contoPassateId): array
    {
        $quote = collect($quote)->values();
        $parti = self::partiAdPersonam($quote);
        $motivi = [];
        foreach ($quote->filter(fn (RataQuote $q) => $q->immobile_id !== null && ($parti[(int) $q->id] ?? 0) > 0)->groupBy(fn (RataQuote $q) => (int) $q->immobile_id) as $diUnita) {
            $motivo = self::motivoBloccoEmissioneAdPersonam($rata->pianoRate, [(clone $rata)->setRelation('rateQuote', $diUnita->values())], $contoPassateId, '');
            if ($motivo === null) {
                continue;
            }
            foreach ($diUnita as $q) {
                $motivi[(int) $q->id] = 'la quota di ' . ($q->anagrafica?->nome ?? 'una persona') . " sulla rata {$rata->numero_rata} ({$rata->pianoRate->nome}) non si porta "
                    . 'a giornale: ' . lcfirst(rtrim($motivo, '.'));
            }
        }

        return $motivi;
    }

    /**
     * Quanto i piani che contengono la fattura chiedono della sua spesa ad personam su un'unità (Coda 313, decisione 160): le righe `ad_personam`
     * del registro del riparto con le righe della fattura su quell'unità. Il motore toglie la nota collegata dal componente prima di ripartire,
     * quindi un piano generato dopo la nota la chiede già al netto. Un piano senza registro (generato prima della 1.11.0-beta.29, quando le
     * note collegate non c'erano) conta tutta la riga, `$lordo`.
     *
     * @param list<int> $righeFatturaIds
     */
    private static function chiestoAdPersonamDaiPiani(int $fatturaId, int $immobileId, array $righeFatturaIds, int $lordo): int
    {
        $piani = \Illuminate\Support\Facades\DB::table('piano_rate_fatture as prf')->join('piani_rate as p', 'p.id', '=', 'prf.piano_rate_id')
            ->where('prf.fattura_passiva_id', $fatturaId)->pluck('p.id')->map(fn ($id) => (int) $id)->all();
        $chiesto = 0;
        foreach ($piani as $pianoId) {
            if (! RigaRiparto::where('piano_rate_id', $pianoId)->exists()) {
                $chiesto += $lordo;
                continue;
            }
            $chiesto += (int) RigaRiparto::where('piano_rate_id', $pianoId)->where('tipo', RigaRiparto::TIPO_AD_PERSONAM)
                ->where('immobile_id', $immobileId)->whereIn('riga_fattura_id', $righeFatturaIds)->sum('importo');
        }

        return $chiesto;
    }

    /**
     * Le righe che chiudono l'anticipazione della spesa ad personam in una scrittura d'emissione (Coda 313), pronte da scrivere: per unità,
     * AVERE «Crediti verso condomini» con l'unità e la rata, senza persona, per la somma della parte `anticipazione` delle quote
     * ({@see anticipazioniPerUnita()}). La scrivono i tre scrittori dell'emissione; l'emissione, prima, rifiuta le rate la cui chiusura
     * toglierebbe dall'unità una spesa già tolta ({@see motivoBloccoEmissioneAdPersonam()}).
     *
     * @param array<int, array{anticipazione: int}> $perQuota l'esito di {@see scritturaDiEmissione()}, per id della quota
     * @param iterable<RataQuote> $quote le quote che la scrittura porta a giornale
     * @return list<array<string, mixed>> gli attributi delle righe
     */
    public static function righeDiChiusura(array $perQuota, iterable $quote, int $contoCreditiId, int $rataId, string $descrizione): array
    {
        $righe = [];
        foreach (self::anticipazioniPerUnita($perQuota, $quote) as $immobileId => $importo) {
            $righe[] = ['conto_contabile_id' => $contoCreditiId, 'immobile_id' => (int) $immobileId, 'rata_id' => $rataId, 'tipo_riga' => 'avere',
                'importo' => $importo, 'note' => self::NOTA_CHIUSURA_ANTICIPAZIONE . $descrizione];
        }

        return $righe;
    }

    /**
     * Che cosa l'emissione scrive per ciascuna delle quote date (le quote di una rata ancora senza scrittura): le righe a nome della
     * persona ({@see righeDiEmissione()}) e le contropartite: la spesa su «Gestione rate», meno la parte ad personam
     * (`anticipazione`), che chiude l'anticipazione della fattura su «Crediti verso condomini» con l'unità e la rata, senza persona
     * (Coda 313, decisione 153.1: la fattura l'ha già scritta lì, e contarla anche fra le entrate della gestione la addebitava due
     * volte); il resto su «Passate gestioni», per differenza dalle righe, così dare e avere quadrano per costruzione qualunque sia il
     * segno. La usano i tre scrittori dell'emissione: l'emissione, il comando di riallineamento e lo storno di un incasso con la
     * quota rimasta fuori.
     *
     * Il credito già uscito da «Passate gestioni» si legge per persona, rata e unità: con due quote gemelle (decisione 25) il
     * giornale non dice da quale delle due è uscito, e si consuma una quota dopo l'altra, così non si sottrae due volte.
     *
     * @param iterable<RataQuote> $quote
     * @return array<int, array{righe: list<array{0: 'dare'|'avere', 1: int}>, gestione: int, anticipazione: int, pregresso: int}> per id della quota
     */
    public static function scritturaDiEmissione(iterable $quote, ?int $contoPassateId): array
    {
        $quote = collect($quote);
        $partiAdPersonam = self::partiAdPersonam($quote);
        $giaUscito = [];
        $esito = [];
        foreach ($quote as $quota) {
            $chiave = $quota->rata_id . '|' . $quota->anagrafica_id . '|' . ($quota->immobile_id ?? '');
            // Il credito uscito si legge dalla prima quota a credito della chiave: una quota a debito della stessa persona sulla
            // stessa rata e unità (decisione 25: una vendita seguita da una ricompra) non lo vede, e letto da lei varrebbe zero
            // anche per la gemella a credito che viene dopo (trovato dalle mutazioni della Fase 1).
            if ($contoPassateId !== null && (int) $quota->importo <= 0 && ! array_key_exists($chiave, $giaUscito)) {
                $giaUscito[$chiave] = $quota->creditoUscitoDaPassate($contoPassateId);
            }
            $creditoDellaQuota = max(0, -$quota->componenti()['riporto']);
            $usato = (int) $quota->importo > 0 ? 0 : min($giaUscito[$chiave] ?? 0, $creditoDellaQuota);
            if ($usato > 0) {
                $giaUscito[$chiave] -= $usato;
            }

            $righe = $quota->righeDiEmissione($usato);
            $netto = array_sum(array_map(fn (array $r) => $r[0] === 'dare' ? $r[1] : -$r[1], $righe));
            $spesa = $righe === [] ? 0 : $quota->componenti()['spesa'];
            $anticipazione = $righe === [] ? 0 : $partiAdPersonam[(int) $quota->id];
            $esito[(int) $quota->id] = ['righe' => $righe, 'gestione' => $spesa - $anticipazione, 'anticipazione' => $anticipazione, 'pregresso' => $netto - $spesa];
        }

        return $esito;
    }

    // === METODI UTILI ===
    public function isPagata(): bool
    {
        return $this->stato === 'pagata';
    }

    public function isParzialmentePagata(): bool
    {
        return $this->stato === 'parzialmente_pagata';
    }

    // === LOGICA CORE (Sostituisce il vecchio registraPagamento) ===

    /**
     * Ricalcola lo stato della quota basandosi ESCLUSIVAMENTE 
     * sulla somma dei record presenti nella tabella pivot 'quota_scrittura'.
     * Da chiamare dopo ogni attach() o detach().
     */
    public function ricalcolaStato(): void
    {
        // 1. Fonte di verità: pivot
        $pagatoReale = $this->pagamenti()->sum('quota_scrittura.importo_pagato');

        $this->importo_pagato = $pagatoReale;

        // 2. Stato
        if ($this->importo_pagato >= $this->importo) {
            $this->stato = 'pagata';
        } elseif ($this->importo_pagato > 0) {
            $this->stato = 'parzialmente_pagata';
        } else {
            $this->stato = 'da_pagare';
            $this->data_pagamento = null;
            $this->save();
            return;
        }

        // 3. Data ultimo pagamento (CORRETTA)
        $ultimoMovimento = $this->pagamenti()
            ->orderByDesc('data_competenza')
            ->first();

        $this->data_pagamento = $ultimoMovimento?->data_competenza;

        $this->save();
    }

    protected static function newFactory()
    {
        return RataQuoteFactory::new();
    }
}