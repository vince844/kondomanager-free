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
     * Che cosa l'emissione scrive per ciascuna delle quote date (le quote di una rata ancora senza scrittura): le righe a nome della
     * persona ({@see righeDiEmissione()}) e le due contropartite, la spesa su «Gestione rate» e il resto su «Passate gestioni»,
     * per differenza dalle righe, così dare e avere quadrano per costruzione qualunque sia il segno. La usano l'emissione e il
     * comando di riallineamento.
     *
     * Il credito già uscito da «Passate gestioni» si legge per persona, rata e unità: con due quote gemelle (decisione 25) il
     * giornale non dice da quale delle due è uscito, e si consuma una quota dopo l'altra, così non si sottrae due volte.
     *
     * @param iterable<RataQuote> $quote
     * @return array<int, array{righe: list<array{0: 'dare'|'avere', 1: int}>, gestione: int, pregresso: int}> per id della quota
     */
    public static function scritturaDiEmissione(iterable $quote, ?int $contoPassateId): array
    {
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
            $esito[(int) $quota->id] = ['righe' => $righe, 'gestione' => $spesa, 'pregresso' => $netto - $spesa];
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