<?php

namespace App\Models\Gestionale;

use App\Models\Tabella;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Conto extends Model
{
    use HasFactory;

    protected $table = 'conti';

    protected $fillable = [
        'piano_conto_id',
        'conto_contabile_id',
        'parent_id',
        'nome',
        'descrizione',
        'tipo',
        'importo',
        'destinazione_id',
        'destinazione_type',
        'note',
    ];
    
    /** RELAZIONI */
    public function pianoConto()
    {
        return $this->belongsTo(PianoConto::class, 'piano_conto_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function sottoconti()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    // Collega la voce di budget (es. "Cancelleria") al conto patrimoniale (es. "Debiti v/Fornitori" o cassa specifica)
    public function contoContabile()
    {
        return $this->belongsTo(ContoContabile::class, 'conto_contabile_id');
    }

    public function destinazione()
    {
        return $this->morphTo();
    }

    public function tabelleMillesimali()
    {
        return $this->hasMany(ContoTabellaMillesimale::class);
    }

    // Nel modello Conto
    public function tabelle()
    {
        return $this->belongsToMany(Tabella::class, 'conto_tabella_millesimale')
            ->withPivot('coefficiente')
            ->withTimestamps();
    }

    public function ripartizioni()
    {
        return $this->hasManyThrough(
            ContoTabellaRipartizione::class,
            ContoTabellaMillesimale::class,
            'conto_id',
            'conto_tabella_millesimale_id'
        );
    }
}
