<?php

namespace App\Models;

use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Traits\RisolveIFigliDelleRotte;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Esercizio extends Model
{
    use RisolveIFigliDelleRotte;

    use HasFactory;

    protected $table = 'esercizi';

    protected $fillable = [
        'condominio_id',
        'nome',
        'descrizione',
        'data_inizio',
        'data_fine',
        'stato',
        'note', 
    ];

    protected $casts = [
        'data_inizio' => 'date',
        'data_fine'   => 'date',
    ];

    public function condominio()
    {
        return $this->belongsTo(Condominio::class);
    }

    public function gestioni()
    {
        return $this->belongsToMany(Gestione::class, 'esercizio_gestione')
            ->withPivot(['attiva', 'data_inizio', 'data_fine'])
            ->withTimestamps();
    }

    /**
     * I piani dei conti di questo esercizio.
     *
     * ⚠️ **Non è un `hasMany`, e non per scelta stilistica:** `piani_conti` non ha `esercizio_id`.
     * Il legame passa dalle gestioni — `piani_conti.gestione_id`, e `esercizi` ↔ `gestioni` è a sua
     * volta molti-a-molti attraverso il pivot `esercizio_gestione`. Sono due salti, e nessuna delle
     * relazioni di serie li fa entrambi.
     *
     * Si usa quindi un `belongsToMany` sul pivot in cui la **chiave del figlio non è `id`** ma
     * `gestione_id`: la giunzione diventa `esercizio_gestione.gestione_id = piani_conti.gestione_id`,
     * che è esattamente il legame vero. Verificato il 22/08/2026 sui dati reali: l'esercizio 29
     * restituisce 1 piano dei conti su 4 in archivio.
     *
     * Serve allo scoped binding di `/esercizi/{esercizio}/piani-conti/{pianoConto}`: senza, quella
     * rotta accetterebbe il piano dei conti di un altro condominio.
     */
    public function pianiConti(): BelongsToMany
    {
        return $this->belongsToMany(
            PianoConto::class, 'esercizio_gestione', 'esercizio_id', 'gestione_id', 'id', 'gestione_id'
        );
    }

    /** I piani rate di questo esercizio. Stesso doppio salto di `pianiConti()`, stesso perché. */
    public function pianiRate(): BelongsToMany
    {
        return $this->belongsToMany(
            PianoRate::class, 'esercizio_gestione', 'esercizio_id', 'gestione_id', 'id', 'gestione_id'
        );
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
            'gestione'   => 'gestioni',
            'pianoConto' => 'pianiConti',
            'pianoRate'  => 'pianiRate',
        ];
    }

}
