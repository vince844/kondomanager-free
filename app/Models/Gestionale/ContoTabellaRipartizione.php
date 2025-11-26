<?php

namespace App\Models\Gestionale;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ContoTabellaRipartizione extends Model
{
    use HasFactory;

    protected $table = 'conto_tabella_ripartizioni';

    protected $fillable = [
        'conto_tabella_millesimale_id',
        'soggetto',
        'percentuale',
    ];

    /** RELAZIONI */
    public function contoTabellaMillesimale()
    {
        return $this->belongsTo(ContoTabellaMillesimale::class, 'conto_tabella_millesimale_id');
    }
}
