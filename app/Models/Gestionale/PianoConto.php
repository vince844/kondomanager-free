<?php

namespace App\Models\Gestionale;

use App\Traits\RisolveIFigliDelleRotte;
use App\Models\Gestione;
use Database\Factories\Gestionale\PianoContoFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PianoConto extends Model
{
    use RisolveIFigliDelleRotte;

    use HasFactory;

    protected $table = 'piani_conti';

    protected $fillable = [
        'gestione_id',
        'condominio_id',
        'nome',
        'descrizione',
        'note',
    ];

    /** RELAZIONI */
    public function gestione()
    {
        return $this->belongsTo(Gestione::class);
    }

    public function conti()
    {
        return $this->hasMany(Conto::class, 'piano_conto_id');
    }

    // Aggiungi questo metodo
    protected static function newFactory()
    {
        return PianoContoFactory::new();
    }
    

    /**
     * Le rotte annidate sotto questo modello, e la relazione che porta a ciascun figlio.
     *
     * Vedi il blocco in testa a `App\Traits\RisolveIFigliDelleRotte` per il perché serve: Laravel
     * deriverebbe il nome con una pluralizzazione inglese, e su nomi italiani sbaglia sempre.
     *
     * @return array<string, string>
     */
    protected function relazioniDeiFigliNelleRotte(): array
    {
        return [
            'conto' => 'conti',
        ];
    }

}
