<?php

namespace App\Models\Gestionale;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una rata del calendario di un accordo di rientro: il numero, il giorno e l'importo in centesimi. */
class AccordoRientroScadenza extends Model
{
    protected $table = 'accordo_rientro_scadenze';

    protected $fillable = ['accordo_rientro_id', 'numero', 'data', 'importo'];

    protected $casts = [
        'accordo_rientro_id' => 'integer',
        'numero' => 'integer',
        'data' => 'date',
        'importo' => 'integer',
    ];

    public function accordo(): BelongsTo
    {
        return $this->belongsTo(AccordoRientro::class, 'accordo_rientro_id');
    }
}
