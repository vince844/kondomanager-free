<?php

namespace App\Models\Gestionale;

use App\Models\Tabella;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ContoTabellaMillesimale extends Model
{
    use HasFactory;

    protected $table = 'conto_tabella_millesimale';

    protected $fillable = [
        'conto_id',
        'tabella_id',
        'coefficiente',
    ];

    /** RELAZIONI */
    public function conto()
    {
        return $this->belongsTo(Conto::class);
    }

    public function tabella()
    {
        return $this->belongsTo(Tabella::class, 'tabella_id');
    }

    public function ripartizioni()
    {
        return $this->hasMany(ContoTabellaRipartizione::class, 'conto_tabella_millesimale_id');
    }
}
