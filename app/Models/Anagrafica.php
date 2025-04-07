<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Anagrafica extends Model
{
    use HasFactory;

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
      /*   return $this->belongsToMany(Segnalazione::class, 'anagrafica_segnalazione', 'anagrafica_id', 'segnalazione_id'); */
        return $this->belongsToMany(Segnalazione::class);
    }

}
