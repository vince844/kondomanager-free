<?php

namespace App\Models\Gestionale;

use App\Models\Fornitore;
use App\Models\Tabella;
use Database\Factories\Gestionale\ContoFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Conto extends Model
{
    use HasFactory;

    protected $table = 'conti';

    protected $appends = ['has_rate_emesse'];

    protected $fillable = [
        'piano_conto_id',
        'conto_contabile_id',
        'default_fornitore_id',
        'parent_id',
        'nome',
        'descrizione',
        'tipo',                 // 'spesa', 'incasso'
        'tipo_spesa',           // NUOVO ('standard', 'professionista', etc.)
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

    public function pianiRate(): BelongsToMany
    {
        return $this->belongsToMany(PianoRate::class, 'piano_rate_capitoli', 'conto_id', 'piano_rate_id');
    }

    /**
     * Recupera tutti gli ID dei sottoconti (figli, nipoti, ecc.)
     */
    public function getAllChildrenIds(): array
    {
        $ids = [];
        foreach ($this->sottoconti as $sottoconto) {
            $ids[] = $sottoconto->id;
            $ids = array_merge($ids, $sottoconto->getAllChildrenIds());
        }
        return $ids;
    }

    /**
     * Recupera tutti gli ID dei padri (parent, grandparent, ecc.)
     */
    public function getAllAncestorsIds(): array
    {
        $ids = [];
        $parent = $this->parent;
        while ($parent) {
            $ids[] = $parent->id;
            $parent = $parent->parent;
        }
        return $ids;
    }

    protected static function newFactory()
    {
        return ContoFactory::new();
    }

    /**
     * Accessor per sapere se il conto è bloccato da rate emesse.
     * Controlla sia il collegamento diretto che quello ereditato dal padre.
     */
    public function getHasRateEmesseAttribute(): bool
    {
        // 1. Controllo DIRETTO: Il conto specifico è nel piano rate?
        $vincoloDiretto = $this->pianiRate()
            ->whereIn('stato', ['approvato', 'emesso', 'chiuso'])
            ->exists();

        if ($vincoloDiretto) {
            return true;
        }

        // 2. Controllo EREDITATO: Il PADRE è nel piano rate?
        // Se io sono una voce "Cancelleria" dentro il capitolo "Spese Generali",
        // e "Spese Generali" è rateizzato, allora anch'io sono bloccato.
        if ($this->parent_id && $this->parent) {
             return $this->parent->pianiRate()
                ->whereIn('stato', ['approvato', 'emesso', 'chiuso'])
                ->exists();
        }

        return false;
    }

    /**
     * Relazione con il fornitore predefinito (Suggerito).
     */
    public function fornitore()
    {
        return $this->belongsTo(Fornitore::class, 'default_fornitore_id');
    }
}
