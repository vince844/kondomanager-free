<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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

    /**
     * L'avviso di convocazione di un'assemblea (Coda 679, §4 del progetto della tappa 2): una riga per persona e per invio, con l'assemblea come
     * «documentabile». Le letture di oggi filtrano il tipo e non lo vedono; lo vede di proposito la «Corrispondenza» della persona. La prova di una
     * convocazione non si toglie mai.
     */
    public const TIPO_CONVOCAZIONE = 'convocazione';

    /**
     * Il verbale di un'assemblea trasmesso a una persona (il tipo del verbale): agli assenti, o a tutti in videoconferenza, con il mezzo e la prova della
     * convocazione. Il documento è l'assemblea, `dati.verbale_id` il verbale.
     */
    public const TIPO_VERBALE = 'verbale';

    /** I tipi dell'assemblea che usano la spedizione, la consegna e la ricevuta della convocazione. */
    public const TIPI_DELL_ASSEMBLEA = [self::TIPO_CONVOCAZIONE, self::TIPO_VERBALE];

    /**
     * Il promemoria per email a chi ha preparato l'assemblea: la convocazione è da spedire entro il giorno limite del compito. Una riga per promemoria, con
     * l'assemblea come «documentabile» e senza la persona (va all'utente dello studio, in `dati.utente`); `dati.promemoria` e `dati.limite` sono il segno
     * che lo fa partire una volta sola ({@see \App\Services\Assemblee\Convocazione\PromemoriaDellaConvocazione}).
     */
    public const TIPO_PROMEMORIA_CONVOCAZIONE = 'promemoria_convocazione';

    /** La PEC mandata dal programma dalla casella PEC dello studio (decisione 564): `inviata` vuol dire solo «accettata dal gestore». */
    public const CANALE_PEC = 'pec';

    /**
     * L'esito di un invio (§1.2 del progetto della tappa 2), oggi solo per la convocazione: le righe di prima restano con l'esito vuoto. `ricevuta_il` è il
     * giorno che conta per i cinque giorni: il primo fra la consegna e l'avviso di giacenza (Cass. 23396/2017); per «rifiutata» resta vuoto.
     */
    public const ESITO_CONSEGNATA = 'consegnata';
    public const ESITO_IN_GIACENZA = 'in_giacenza';
    public const ESITO_RIFIUTATA = 'rifiutata';
    public const ESITO_NON_CONSEGNATA = 'non_consegnata';

    public const ESITI = [self::ESITO_CONSEGNATA, self::ESITO_IN_GIACENZA, self::ESITO_RIFIUTATA, self::ESITO_NON_CONSEGNATA];

    /** Gli esiti che danno il giorno di ricezione: la consegna e l'avviso di giacenza. */
    public const ESITI_CHE_DANNO_LA_RICEZIONE = [self::ESITO_CONSEGNATA, self::ESITO_IN_GIACENZA];

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
        'esito',
        'esito_il',
        'ricevuta_il',
    ];

    protected $casts = [
        'condominio_id'    => 'integer',
        'anagrafica_id'    => 'integer',
        'documentabile_id' => 'integer',
        'mail_log_id'      => 'integer',
        'emesso_da'        => 'integer',
        'dati'             => 'array',
        'emesso_il'        => 'datetime',
        'esito_il'         => 'datetime',
        'ricevuta_il'      => 'date:Y-m-d',
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
