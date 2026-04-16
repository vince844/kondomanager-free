<?php

namespace App\Models\Gestionale;

use App\Models\ContoCorrente;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Cassa extends Model
{
    protected $table = 'casse';
    
    protected $fillable = [
        'condominio_id', 
        'nome', 
        'descrizione',
        'tipo', 
        'conto_contabile_id', 
        'saldo_iniziale',
        'attiva', 
        'note',
        // --- Nuovi campi Governance Fondi ---
        'sottotipo_fondo',
        'vincolo_descrizione',
        'is_override_assemblea',
        'motivazione_override'
    ];

    protected $casts = [
        'attiva' => 'boolean',
        'is_override_assemblea' => 'boolean',
    ];

    // Espone automaticamente gli attributi calcolati a Vue (Inertia)
    protected $appends = ['is_utilizzabile_per_imprevisti', 'saldo_reale'];

    /**
     * IL GUARDIANO DEL DOMINIO
     * Si assicura che il database resti sempre coerente, 
     * bloccando le forzature e pulendo i dati "sporchi".
     */
    protected static function booted()
    {
        static::saving(function (Cassa $cassa) {

            // 1. Se non è un fondo, puliamo tutta la logica di governance
            if ($cassa->tipo !== 'fondo') {
                $cassa->sottotipo_fondo = null;
                $cassa->vincolo_descrizione = null;
                $cassa->is_override_assemblea = false;
                $cassa->motivazione_override = null;
                return;
            }

            // 2. Default di sicurezza
            $cassa->sottotipo_fondo = $cassa->sottotipo_fondo ?? 'generico';

            // --- FIX QUI: Se NON è vincolato_lavori, svuotiamo la descrizione ---
            if ($cassa->sottotipo_fondo !== 'vincolato_lavori') {
                $cassa->vincolo_descrizione = null;
            }

            // 3. Regole per il Fondo Generico (Sempre libero)
            if ($cassa->sottotipo_fondo === 'generico') {
                $cassa->is_override_assemblea = false;
                $cassa->motivazione_override = null;
                $cassa->vincolo_descrizione = null;
                return;
            }

            // 4. Regola d'oro per i Fondi Vincolati (Enforcement)
            if ($cassa->is_override_assemblea) {
                if (empty(trim($cassa->motivazione_override))) {
                    throw ValidationException::withMessages([
                        'motivazione_override' => 'Motivazione obbligatoria per sbloccare l\'uso di un fondo vincolato.'
                    ]);
                }
            } else {
                // Se l'override è spento, la motivazione deve essere vuota
                $cassa->motivazione_override = null;
            }
        });
    }

    /**
     * RELAZIONI
     */
    public function movimenti(): HasMany
    {
        // Correttamente agganciato in Partita Doppia
        return $this->hasMany(RigaScrittura::class, 'conto_contabile_id', 'conto_contabile_id');
    }

    public function contoCorrente(): MorphOne
    {
        return $this->morphOne(ContoCorrente::class, 'contable');
    }

    public function contoContabile(): BelongsTo
    {
        return $this->belongsTo(ContoContabile::class);
    }

    /**
     * ACCESSOR: Flag dinamico del CFO Engine (Mai salvato su DB)
     */
    public function getIsUtilizzabilePerImprevistiAttribute(): bool
    {
        if ($this->tipo !== 'fondo') {
            return false;
        }

        return $this->sottotipo_fondo === 'generico' || $this->is_override_assemblea;
    }

    /**
     * ACCESSOR: Calcolo del Saldo Reale in tempo reale (in centesimi)
     */
    public function getSaldoRealeAttribute(): int
    {
        $aggregato = $this->movimenti()
            ->selectRaw("
                SUM(CASE WHEN tipo_riga = 'dare' THEN importo ELSE 0 END) as entrate,
                SUM(CASE WHEN tipo_riga = 'avere' THEN importo ELSE 0 END) as uscite
            ")
            ->first();

        // In contabilità, per i conti finanziari/patrimoniali:
        // DARE = Aumenti (+), AVERE = Diminuzioni (-)
        return $this->saldo_iniziale 
             + ($aggregato->entrate ?? 0) 
             - ($aggregato->uscite ?? 0);
    }
}