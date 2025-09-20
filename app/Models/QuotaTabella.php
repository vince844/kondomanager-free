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

    // app/Models/QuotaTabella.php
    public function getValoreFormattatoAttribute(): string
    {
        $decimali = $this->tabella->numero_decimali ?? 2;

        return match ($this->tabella->quota) {
            'millesimi' => number_format($this->valore ?? 0, $decimali, ',', '.') . ' ‰',
            'persone'  => (int) $this->valore . ' pers.',
            'kwatt'    => number_format($this->valore ?? 0, $decimali, ',', '.') . ' kW',
            'mtcubi'   => number_format($this->valore ?? 0, $decimali, ',', '.') . ' m³',
            'quote'    => number_format($this->valore ?? 0, $decimali, ',', '.') . ' q',
            default    => (string) $this->valore,
        };
    }
}
