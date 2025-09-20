<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tabella extends Model
{
    use HasFactory;

    protected $table = 'tabelle';

    protected $fillable = [
        'condominio_id',
        'palazzina_id',
        'scala_id',
        'nome',
        'tipo',
        'quota',
        'numero_decimali',
        'regole_calcolo',
        'descrizione',
        'note',
        'attiva',
        'data_inizio',
        'data_fine',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'regole_calcolo' => 'array',
        'attiva' => 'boolean',
        'data_inizio' => 'date',
        'data_fine' => 'date'
    ];

    /*
    |--------------------------------------------------------------------------
    | Relazioni
    |--------------------------------------------------------------------------
    */

    public function condominio()
    {
        return $this->belongsTo(Condominio::class);
    }

    public function quote()
    {
        return $this->hasMany(QuotaTabella::class);
    }

    // Relazione con la palazzina
    public function palazzina()
    {
        return $this->belongsTo(Palazzina::class);
    }

    // Relazione con la scala
    public function scala()
    {
        return $this->belongsTo(Scala::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Accessor: nome leggibile della quota
    public function getQuotaLabelAttribute(): string
    {
        return match ($this->quota) {
            'millesimi' => 'Millesimi',
            'persone'  => 'Persone',
            'kwatt'    => 'kW',
            'mtcubi'   => 'Metri Cubi',
            'quote'    => 'Quote',
            default    => ucfirst($this->quota),
        };
    }
}
