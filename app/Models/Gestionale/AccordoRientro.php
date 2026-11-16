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
 * L'accordo di rientro di una persona: la data, le rate coperte con l'importo riconosciuto di ognuna, il calendario (le rate dell'accordo, con il
 * giorno e l'importo; la somma è il debito riconosciuto), «fino al» (il giorno dell'ultima rata del calendario), la regola di decadenza scelta
 * dall'amministratore, «autorizzato dall'assemblea» (sì o no, con la data e il verbale se sì), una nota, la scansione firmata (un `Documento`
 * dell'accordo, che non entra nell'archivio né nel portale); poi la chiusura, che può essere la decadenza registrata. Non si cancella: si chiude.
 *
 * Non sposta denaro: le rate coperte restano scadute (il debito originario resta a giornale), e le lettere proposte lasciano fuori la parte
 * riconosciuta. Il programma segnala quando la regola di decadenza si verifica e conserva il giorno; la decadenza la registra l'amministratore.
 * Non è la dispensa dei sei mesi: sono due delibere diverse. Gli accordi registrati senza calendario restano senza.
 */
class AccordoRientro extends Model
{
    protected $table = 'accordi_rientro';

    protected $fillable = [
        'condominio_id', 'anagrafica_id', 'data_accordo', 'fino_al', 'autorizzato_assemblea', 'data_autorizzazione', 'verbale_autorizzazione', 'nota',
        'decadenza_rate', 'decadenza_giorni', 'chiuso_il', 'decadenza_registrata_il', 'nota_chiusura', 'registrato_da', 'chiuso_da',
    ];

    protected $casts = [
        'condominio_id' => 'integer',
        'anagrafica_id' => 'integer',
        'data_accordo' => 'date',
        'fino_al' => 'date',
        'autorizzato_assemblea' => 'boolean',
        'data_autorizzazione' => 'date',
        'chiuso_il' => 'date',
        'decadenza_rate' => 'integer',
        'decadenza_giorni' => 'integer',
        'segnali' => 'array',
        'decadenza_registrata_il' => 'date',
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

    /** Il calendario: le rate dell'accordo, in ordine. */
    public function scadenze(): HasMany
    {
        return $this->hasMany(AccordoRientroScadenza::class, 'accordo_rientro_id')->orderBy('numero');
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
