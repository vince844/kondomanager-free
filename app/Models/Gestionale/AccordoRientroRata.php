<?php

namespace App\Models\Gestionale;

use App\Models\Immobile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una rata coperta da un accordo di rientro, per unità, con l'importo riconosciuto e il residuo della rata il giorno dell'accordo, in centesimi. */
class AccordoRientroRata extends Model
{
    protected $table = 'accordo_rientro_rate';

    protected $fillable = ['accordo_rientro_id', 'rata_id', 'immobile_id', 'importo_riconosciuto', 'residuo_iniziale'];

    protected $casts = [
        'accordo_rientro_id' => 'integer',
        'rata_id' => 'integer',
        'immobile_id' => 'integer',
        'importo_riconosciuto' => 'integer',
        'residuo_iniziale' => 'integer',
    ];

    public function accordo(): BelongsTo
    {
        return $this->belongsTo(AccordoRientro::class, 'accordo_rientro_id');
    }

    public function rata(): BelongsTo
    {
        return $this->belongsTo(Rata::class, 'rata_id');
    }

    public function immobile(): BelongsTo
    {
        return $this->belongsTo(Immobile::class);
    }
}
