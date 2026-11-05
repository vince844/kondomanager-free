<?php

namespace App\Models\Gestionale;

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\MailLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Un documento mandato a una persona, a ogni emissione: la stampa o l'invio per email di una ricevuta (dopo, anche dei solleciti).
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
}
