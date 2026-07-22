<?php

namespace App\Models\Gestionale;

use App\Models\Fornitore;
use App\Models\Tabella;
use Database\Factories\Gestionale\ContoFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Conto extends Model
{
    use HasFactory;

    protected $table = 'conti';

    protected $fillable = [
        'piano_conto_id',
        'conto_contabile_id',
        'default_fornitore_id',
        'parent_id',
        'is_capitolo', // fatto esplicito, mai indovinato da importo/parent_id — vedi migrazione add_is_capitolo
        'codice',
        'nome',
        'descrizione',
        'tipo',                 
        'tipo_spesa',           
        'tipo_ripartizione',   // NUOVO: millesimale, custom, ad_personam
        'origine_decisionale', // NUOVO: gestione_corrente, delibera_assembleare
        'is_tecnico', // true per conti generati on-the-fly (sopravvenienze + padre). Invisibili al preventivo ordinario.
        'importo',
        'destinazione_id',
        'destinazione_type',
        'note',
        'is_visibile',
    ];

    protected $casts = [
        'is_tecnico' => 'boolean',
        'is_capitolo' => 'boolean',
    ];
    
    /** * =========================================================================
     * I BINARI DI ROUTING (La Matrice Decisionale Ordinario vs Straordinario)
     * =========================================================================
     */

    /**
     * SCOPE: Filtra le voci che DEVONO finire nel Piano Rate Ordinario
     * Regola: Deve essere una spesa collettiva (millesimale) di gestione corrente.
     */
    public function scopeForPianoOrdinario(Builder $query): Builder
    {
        return $query->where('tipo_ripartizione', 'millesimale')
                     ->where('origine_decisionale', 'gestione_corrente');
    }

    /**
     * SCOPE: Filtra le voci che DEVONO finire nel Piano Rate Straordinario (Wallet)
     * Regola: Se c'è una delibera specifica OPPURE se la ripartizione è privata/non standard.
     */
    public function scopeForPianoStraordinario(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where('origine_decisionale', 'delibera_assembleare')
              ->orWhereIn('tipo_ripartizione', ['custom', 'ad_personam']);
        });
    }

    /**
     * SCOPE: Esclude i conti tecnici (sopravvenienze generate on-the-fly).
     * Usato dal wizard Piano Ordinario e dal PDF preventivo deliberato.
     */
    public function scopeVisibili(Builder $query): Builder
    {
        return $query->where('is_tecnico', false);
    }

    /** * RELAZIONI 
     */
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

    public function fornitore()
    {
        return $this->belongsTo(Fornitore::class, 'default_fornitore_id');
    }

    /**
     * METODI HELPER 
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

    public function getHasRateEmesseAttribute(): bool
    {
        // Check 1: piano rate direttamente collegato a questo conto
        $vincoloDiretto = $this->pianiRate()
            ->whereIn('stato', ['approvato', 'emesso', 'chiuso'])
            ->exists();

        if ($vincoloDiretto) return true;

        // Check 2: padre ha un piano approvato E questo conto
        // è esplicitamente incluso in quel piano via piano_rate_capitoli
        if ($this->parent_id && $this->parent) {
            return $this->parent->pianiRate()
                ->whereIn('stato', ['approvato', 'emesso', 'chiuso'])
                ->where('piani_rate.created_at', '>=', $this->created_at)
                ->exists();
        }

        return false;
    }

    protected static function newFactory()
    {
        return ContoFactory::new();
    }
}