<?php

namespace App\Models\Gestionale;

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * L'accordo di rientro di una persona, come registro: la data, «fino al» (la data dell'ultima rata dell'accordo), le rate coperte con
 * l'importo riconosciuto di ognuna, «autorizzato dall'assemblea» (sì o no, con la data e il verbale se sì), una nota, la scansione firmata (un
 * `Documento` dell'accordo, che non entra nell'archivio né nel portale); poi la chiusura. Non si cancella: si chiude.
 *
 * Non sposta denaro e non ha un calendario: le rate coperte restano scadute (il debito originario resta a giornale), e le lettere proposte
 * lasciano fuori la parte riconosciuta. Non è la dispensa dei sei mesi: sono due delibere diverse.
 */
class AccordoRientro extends Model
{
    protected $table = 'accordi_rientro';

    protected $fillable = [
        'condominio_id', 'anagrafica_id', 'data_accordo', 'fino_al', 'autorizzato_assemblea', 'data_autorizzazione', 'verbale_autorizzazione', 'nota',
        'chiuso_il', 'nota_chiusura', 'registrato_da', 'chiuso_da',
    ];

    protected $casts = [
        'condominio_id' => 'integer',
        'anagrafica_id' => 'integer',
        'data_accordo' => 'date',
        'fino_al' => 'date',
        'autorizzato_assemblea' => 'boolean',
        'data_autorizzazione' => 'date',
        'chiuso_il' => 'date',
    ];

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Anagrafica::class, 'anagrafica_id');
    }

    /** Le rate coperte, ognuna con l'importo riconosciuto. */
    public function rate(): HasMany
    {
        return $this->hasMany(AccordoRientroRata::class, 'accordo_rientro_id');
    }

    /** La scansione dell'accordo firmato. */
    public function documenti(): MorphMany
    {
        return $this->morphMany(Documento::class, 'documentable');
    }

    public function registratoDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrato_da');
    }

    public function chiusoDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chiuso_da');
    }

    /** In corso a una data: registrato con una data non dopo quella, e non chiuso prima o quel giorno. */
    public function inCorsoIl(string $giorno): bool
    {
        return $this->data_accordo->format('Y-m-d') <= $giorno && ($this->chiuso_il === null || $this->chiuso_il->format('Y-m-d') > $giorno);
    }
}
