<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuotaTabella extends Model
{
    protected $table = 'quote_tabella';

    protected $fillable = [
        'tabella_id', 
        'immobile_id',
        'valore', 
        'coefficienti', 
        'escluso',
        'created_by', 
        'updated_by'
    ];

    protected $casts = [
        'coefficienti' => 'array',
        'escluso'      => 'boolean',
    ];

    public function tabella()
    {
        return $this->belongsTo(Tabella::class);
    }

    public function immobile()
    {
        return $this->belongsTo(Immobile::class);
    }

    /**
     * Accessor: interpreta valore in base alla quota della tabella
     */
    public function getValoreFormattatoAttribute(): string
    {
        return match ($this->tabella->quota) {
            'millesimi' => number_format($this->valore ?? 0, 5) . ' ‰',
            'persone'  => (int) $this->valore . ' pers.',
            'kwatt'    => $this->valore . ' kW',
            'mtcubi'   => $this->valore . ' m³',
            'quote'    => $this->valore . ' q',
            default    => (string) $this->valore,
        };
    }
}
