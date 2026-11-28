<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use App\Models\Gestionale\RigaScrittura;
use App\Traits\RegistraLeModifiche;

class Anagrafica extends Model
{
    use RegistraLeModifiche;

    use HasFactory, Notifiable;

    protected $table = 'anagrafiche';

    protected $fillable = [
        'user_id',
        'nome',
        'indirizzo',
        'email',
        'email_secondaria',
        'pec',
        'codice_fiscale',
        'tipologia_documento',
        'numero_documento',
        'scadenza_documento',
        'luogo_nascita',
        'data_nascita',
        'telefono',
        'cellulare',
        'note',
        // Come si convoca e il giorno in cui ha comunicato la sua PEC (§1.2 del progetto della tappa 2): vuoto, il programma propone il mezzo dai dati.
        'mezzo_convocazione',
        'pec_comunicata_il',
    ];

    /**
     * Le persone di un condominio per l'incasso e l'estratto conto: chi è fra le persone del condominio (il pivot `anagrafica_condominio`), chi ha o ha
     * avuto una riga di titolarità su una sua unità (anche cessata: si paga una rata di quando c'era) e chi ha quote nelle rate di un suo piano. Il terzo
     * criterio tiene sceglibile chi è stato tolto dal condominio dopo una vendita, o dissociato da un'unità, con un debito rimasto: le sue rate si
     * incassano lo stesso. Una sola fonte per la regola del pagante (`StoreIncassoRateRequest`), la scelta del pagante nella pagina dell'incasso e
     * l'estratto conto.
     *
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public static function delCondominio(int $condominioId): \Illuminate\Database\Eloquent\Builder
    {
        return self::query()->where(fn ($q) => $q
            ->whereIn('anagrafiche.id', fn ($sub) => $sub->select('anagrafica_id')->from('anagrafica_condominio')->where('condominio_id', $condominioId))
            ->orWhereIn('anagrafiche.id', fn ($sub) => $sub->select('anagrafica_immobile.anagrafica_id')->from('anagrafica_immobile')
                ->join('immobili', 'immobili.id', '=', 'anagrafica_immobile.immobile_id')->where('immobili.condominio_id', $condominioId))
            ->orWhereIn('anagrafiche.id', fn ($sub) => $sub->select('rate_quote.anagrafica_id')->from('rate_quote')
                ->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->join('piani_rate', 'piani_rate.id', '=', 'rate.piano_rate_id')
                ->where('piani_rate.condominio_id', $condominioId)->whereNotNull('rate_quote.anagrafica_id')));
    }

    /**
     * Get the buildings associated with the anagrafica.
     */
    public function condomini()
    {
        return $this->belongsToMany(Condominio::class); 
    }

    /**
     * Get the user that owns the anagrafica.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Define the many-to-many relationship with Segnalazione (ticket)
    public function segnalazioni()
    {
        return $this->belongsToMany(Segnalazione::class, 'anagrafica_segnalazione')->withTimestamps();

    }

    /**
     * Get the comunicazioni associated with the anagrafica.
     */
    public function comunicazioni()
    {
        return $this->belongsToMany(Comunicazione::class, 'anagrafica_comunicazione')->withTimestamps();
    }

    /**
     * I documenti dell'archivio di cui questa persona è **destinataria**.
     *
     * ⚠️ Da non confondere con `documentiPropri()`: qui la persona è *fra i destinatari* di un
     * documento che vive nell'archivio (il verbale mandato a tutti), là il documento **è suo** —
     * la copia del documento d'identità, una delega, un contratto d'affitto. Sono due domande
     * diverse e due tabelle diverse, e tenerle separate è il motivo per cui hanno due nomi.
     */
    public function documenti()
    {
        return $this->belongsToMany(Documento::class, 'anagrafica_documento');
    }

    /**
     * I documenti **della persona**, quelli che l'amministratore archivia sulla sua scheda.
     *
     * Stessa forma che il fornitore ha da sempre (`Fornitore::documenti()`, morphMany su
     * `documentable`): il documento appartiene al soggetto, e cancellando il soggetto se ne va con
     * lui. Aggiunta nella 1.11.0-beta.9 insieme alla scheda dell'anagrafica.
     */
    public function documentiPropri(): MorphMany
    {
        return $this->morphMany(Documento::class, 'documentable');
    }

    public function eventi()
    {
        return $this->belongsToMany(Evento::class, 'anagrafica_evento');
    }

    public function immobili()
    {
        // `id` nel pivot e modello dedicato dalla 1.11.0-beta.30 (B1 del progetto sul subentro):
        // la riga di titolarità ha un'identità propria, perché B2 registrerà più periodi per la stessa
        // persona e le rotte dovranno lavorare per periodo, non per persona. Nessun cast: vedi il
        // docblock di `TitolaritaImmobile`.
        return $this->belongsToMany(Immobile::class, 'anagrafica_immobile')
            ->using(TitolaritaImmobile::class)
            ->withPivot([
                'id',
                'tipologia',
                'quota',
                // `tipologie_spese` è caduta con la 1.11.0-beta.31 (decisione 8 del progetto sul subentro).
                'data_inizio',
                'data_fine',
                'attivo',
                'note',
            ])
            ->withTimestamps();
    }

    public function saldi(): HasMany
    {
        return $this->hasMany(Saldo::class, 'anagrafica_id');
    }

    public function fornitori()
    {
        return $this->belongsToMany(
                Fornitore::class,
                'anagrafica_fornitore',
                'anagrafica_id',
                'fornitore_id'
            )
            ->using(AnagraficaFornitore::class)
            ->withPivot(['ruolo', 'referente_principale'])
            ->withTimestamps();
    }

    /**
     * Relazione con le righe contabili (Estratto Conto).
     * Recupera tutti i movimenti (rate emesse, incassi, ecc.) associati a questa anagrafica.
     */
    public function movimenti(): HasMany
    {
        // Punta alla tabella 'righe_scritture' dove anagrafica_id è uguale all'ID di questa anagrafica
        return $this->hasMany(RigaScrittura::class, 'anagrafica_id');
    }

    /**
     * Route notifications for the mail channel.
     *
     * @param  \Illuminate\Notifications\Notification  $notification
     * @return array|string
     */
    public function routeNotificationForMail($notification)
    {
        $emails = [];
        
        if (!empty($this->email)) {
            $emails[] = $this->email;
        }
        
        if (!empty($this->email_secondaria)) {
            $emails[] = $this->email_secondaria;
        }
        
        return $emails;
    }


}
