<?php

namespace App\Models\Gestionale;

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Esercizio;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * La dispensa dell'assemblea dall'agire entro sei mesi (art. 1129 c. 9 c.c.), come l'amministratore la registra: la data della delibera, il
 * numero del verbale e il suo file (un `Documento` della dispensa, che non entra nell'archivio né nel portale), gli esercizi coperti, le
 * persone (tutte, o alcune se la delibera lo dice), una nota; poi, se c'è, la revoca. Non si cancella: si revoca.
 *
 * Il programma la mostra accanto alle date dei sei mesi che copre e non ricalcola niente. Non è l'accordo di rientro: sono due delibere
 * diverse.
 */
class DispensaSeiMesi extends Model
{
    protected $table = 'dispense_sei_mesi';

    protected $fillable = [
        'condominio_id', 'data_delibera', 'numero_verbale', 'nota', 'tutte_le_persone', 'revocata_il', 'nota_revoca', 'registrata_da', 'revocata_da',
    ];

    protected $casts = [
        'data_delibera' => 'date',
        'revocata_il' => 'date',
        'tutte_le_persone' => 'boolean',
    ];

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function esercizi(): BelongsToMany
    {
        return $this->belongsToMany(Esercizio::class, 'dispensa_sei_mesi_esercizio', 'dispensa_id', 'esercizio_id')->withTimestamps();
    }

    public function persone(): BelongsToMany
    {
        return $this->belongsToMany(Anagrafica::class, 'dispensa_sei_mesi_anagrafica', 'dispensa_id', 'anagrafica_id')->withTimestamps();
    }

    /** Il verbale della delibera. */
    public function documenti(): MorphMany
    {
        return $this->morphMany(Documento::class, 'documentable');
    }

    public function registrataDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrata_da');
    }

    public function revocata(): bool
    {
        return $this->revocata_il !== null;
    }
}
