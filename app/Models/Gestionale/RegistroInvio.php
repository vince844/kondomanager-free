<?php

namespace App\Models\Gestionale;

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\MailLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Un documento mandato a una persona, a ogni emissione: la stampa o l'invio per email di una ricevuta, e dalla 1.12.0-beta.4 di una lettera
 * (promemoria, sollecito, diffida, messa in mora).
 *
 * Una riga per documento e per persona. Per la stampa nasce `stampata`; per l'email nasce `in_coda` e la porta avanti il registro delle email
 * (`LogSentMessage`): `inviata` quando il server di posta ha accettato il messaggio, che non vuol dire «consegnato», o `non_partita` con
 * l'errore. Senza server di posta (il trasporto `log`) l'invio non si accoda nemmeno; se un messaggio già in coda parte con il trasporto `log`,
 * la riga resta `non_partita` e lo dice. Una riga di canale `nessuno` e stato `non_da_comunicare` non è un'emissione: è la traccia di chi (`emesso_da`) e
 * quando ha detto che dell'annullamento di un incasso la cui ricevuta era uscita non serve avvisare la persona; resta fra le righe dell'incasso.
 *
 * `dati` porta le scelte dell'emissione (il bollo e come è stato assolto, la nota, «Stampa la causale», il tipo d'indirizzo), il gettone che
 * lega le righe di un messaggio al registro delle email, e il contenuto
 * della ricevuta emessa (`contenuto`): quello dell'originale si riproduce in ogni copia.
 */
class RegistroInvio extends Model
{
    protected $table = 'registro_invii';

    public const TIPO_RICEVUTA = 'ricevuta';

    public const CANALE_STAMPA = 'stampa';
    public const CANALE_EMAIL  = 'email';
    /** Nessun canale: l'amministratore ha segnato che alla persona non serve comunicare l'annullamento (decisione 205). */
    public const CANALE_NESSUNO = 'nessuno';

    public const STATO_STAMPATA   = 'stampata';
    public const STATO_IN_CODA    = 'in_coda';
    public const STATO_INVIATA    = 'inviata';
    public const STATO_NON_PARTITA = 'non_partita';
    /** «Non serve comunicarla»: la ricevuta di un incasso annullato era uscita, e l'amministratore dice che alla persona non serve dirlo. */
    public const STATO_NON_DA_COMUNICARE = 'non_da_comunicare';

    /** Gli stati di una ricevuta che conta come emessa: una che non è partita no, e torna fra quelle da fare. */
    public const STATI_EMESSI = [self::STATO_STAMPATA, self::STATO_IN_CODA, self::STATO_INVIATA];

    /** Le emissioni uscite davvero: la stampa e l'invio partito. La prima è l'originale; una in coda o non partita non lo è. */
    public const STATI_USCITI = [self::STATO_STAMPATA, self::STATO_INVIATA];

    /**
     * Le lettere ai condòmini (1.12.0-beta.4): il tipo lo sceglie l'amministratore a ogni emissione. La riga ha la persona come «documentabile»;
     * le righe della stessa lettera (una per indirizzo, se va per email) hanno lo stesso `dati.lettera`.
     */
    public const TIPI_LETTERE = ['promemoria', 'sollecito', 'diffida', 'messa_in_mora'];

    /**
     * L'avviso di compensazione (1.12.0-beta.4): alla persona le cui rate il credito ha coperto, senza un versamento nuovo, e che non riceve la
     * ricevuta di quell'incasso. Il «documentabile» è l'incasso, come la ricevuta. Non è una lettera: non conta per «mai scritto».
     */
    public const TIPO_AVVISO_COMPENSAZIONE = 'avviso_compensazione';

    /**
     * Il foglio del conguaglio di una persona per email (1.12.0-beta.9): la sua parte del consuntivo e la proposta di conguaglio di un esercizio,
     * che è il «documentabile». Una riga per indirizzo; le righe dello stesso invio hanno lo stesso `dati.foglio`, il PDF conservato, l'oggetto e il
     * testo usati e la data e l'ora del calcolo delle cifre ({@see \App\Services\Gestionale\InvioDeiFogli}).
     */
    public const TIPO_FOGLIO_CONGUAGLIO = 'foglio_conguaglio';

    /** Una lettera stampata o scaricata che l'amministratore ha spedito, con il canale, la data e gli estremi che ha registrato. */
    public const STATO_SPEDITA = 'spedita';

    /**
     * Le lettere uscite dal programma: partite, stampate o spedite. Una in coda o non partita no: per l'ultima lettera della colonna la persona
     * non ha ancora avuto niente. Per «mai scritto» e «nessuna lettera negli ultimi N giorni» contano solo {@see STATI_LETTERA_ARRIVATA}.
     */
    public const STATI_LETTERA_USCITA = [self::STATO_STAMPATA, self::STATO_INVIATA, self::STATO_SPEDITA];

    /**
     * Le lettere che contano per «mai scritto» e «nessuna lettera negli ultimi N giorni»: partite per email o spedite (la spedizione registrata,
     * anche a mano). Una lettera solo stampata o scaricata no: il programma non sa se il PDF è stato mandato. La colonna «Ultima lettera» la
     * mostra lo stesso, con la spedizione da registrare.
     */
    public const STATI_LETTERA_ARRIVATA = [self::STATO_INVIATA, self::STATO_SPEDITA];

    protected $fillable = [
        'condominio_id',
        'anagrafica_id',
        'tipo',
        'canale',
        'documentabile_type',
        'documentabile_id',
        'numero',
        'destinatario',
        'dati',
        'impronta',
        'stato',
        'errore',
        'mail_log_id',
        'emesso_da',
        'emesso_il',
    ];

    protected $casts = [
        'condominio_id'    => 'integer',
        'anagrafica_id'    => 'integer',
        'documentabile_id' => 'integer',
        'mail_log_id'      => 'integer',
        'emesso_da'        => 'integer',
        'dati'             => 'array',
        'emesso_il'        => 'datetime',
    ];

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function anagrafica(): BelongsTo
    {
        return $this->belongsTo(Anagrafica::class);
    }

    public function documentabile(): MorphTo
    {
        return $this->morphTo();
    }

    public function mailLog(): BelongsTo
    {
        return $this->belongsTo(MailLog::class);
    }

    public function emessoDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emesso_da');
    }

    /**
     * I documenti della riga di una lettera: il PDF conservato (sollecito, diffida, messa in mora) e le prove della spedizione. Con un
     * proprietario un documento non entra nell'archivio né nel portale.
     */
    public function documenti(): MorphMany
    {
        return $this->morphMany(\App\Models\Documento::class, 'documentable');
    }
}
