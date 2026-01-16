<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use App\Models\Gestionale\RigaScrittura;

class Anagrafica extends Model
{
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
        'note'
    ];

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

    public function documenti()
    {
        return $this->belongsToMany(Documento::class, 'anagrafica_documento');
    }

    public function eventi()
    {
        return $this->belongsToMany(Evento::class, 'anagrafica_evento');
    }

    public function immobili()
    {
        return $this->belongsToMany(Immobile::class, 'anagrafica_immobile')
            ->withPivot([
                'tipologia',
                'quota',
                'tipologie_spese',
                'data_inizio',
                'data_fine',
                'attivo',
                'note',
            ])
            ->withTimestamps();
    }

    public function saldi()
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


}
