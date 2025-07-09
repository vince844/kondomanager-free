<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

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
        return $this->belongsToMany(Evento::class, 'anagrafica_evento', 'anagrafica_id', 'evento_id');
    }

    

}
