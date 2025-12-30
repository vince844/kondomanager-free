<?php

namespace App\Models\Gestionale;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany; // <--- AGGIUNTO
use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Immobile;

class RataQuote extends Model // Se il file si chiama RataQuota, cambia qui il nome
{
    protected $table = 'rate_quote';

    protected $fillable = [
        'rata_id',
        'anagrafica_id',
        'immobile_id',
        'importo',
        'importo_pagato',
        'stato',
        'data_scadenza',
        'data_pagamento',
        'riferimento_pagamento',
        'scrittura_contabile_id', // Usato solo in fase di emissione
        'note'
    ];

    protected $casts = [
        'importo'        => 'integer',
        'importo_pagato' => 'integer',
        'data_scadenza'  => 'date',
        'data_pagamento' => 'date',
    ];

    // === RELAZIONI ===
    public function rata(): BelongsTo
    {
        return $this->belongsTo(Rata::class, 'rata_id');
    }

    public function anagrafica(): BelongsTo
    {
        return $this->belongsTo(Anagrafica::class);
    }

    public function immobile(): BelongsTo
    {
        return $this->belongsTo(Immobile::class);
    }

    /**
     * NUOVA RELAZIONE FONDAMENTALE (Pivot)
     * Collega la quota ai movimenti bancari (scritture) che l'hanno pagata.
     */
    public function pagamenti(): BelongsToMany
    {
        return $this->belongsToMany(
            ScritturaContabile::class, 
            'quota_scrittura',      // Tabella pivot corretta
            'rate_quota_id',        // FK di questo modello sulla pivot
            'scrittura_contabile_id'// FK dell'altro modello sulla pivot
        )
        ->withPivot(['importo_pagato', 'data_pagamento'])
        ->withTimestamps();
    }

    // === ACCESSORS (Mantieni pure i tuoi helper UI, sono ottimi) ===
    public function getImportoFormattatoAttribute(): string
    {
        return MoneyHelper::format($this->importo);
    }

    public function getImportoPagatoFormattatoAttribute(): string
    {
        return MoneyHelper::format($this->importo_pagato);
    }

    public function getImportoResiduoAttribute(): int
    {
        return max(0, $this->importo - $this->importo_pagato);
    }

    public function getImportoResiduoFormattatoAttribute(): string
    {
        return MoneyHelper::format($this->importo_residuo);
    }

    // === METODI UTILI ===
    public function isPagata(): bool
    {
        return $this->stato === 'pagata';
    }

    public function isParzialmentePagata(): bool
    {
        return $this->stato === 'parzialmente_pagata';
    }

    // === LOGICA CORE (Sostituisce il vecchio registraPagamento) ===

    /**
     * Ricalcola lo stato della quota basandosi ESCLUSIVAMENTE 
     * sulla somma dei record presenti nella tabella pivot 'quota_scrittura'.
     * Da chiamare dopo ogni attach() o detach().
     */
    public function ricalcolaStato(): void
    {
        // 1. Fonte di verità: pivot
        $pagatoReale = $this->pagamenti()->sum('quota_scrittura.importo_pagato');

        $this->importo_pagato = $pagatoReale;

        // 2. Stato
        if ($this->importo_pagato >= $this->importo) {
            $this->stato = 'pagata';
        } elseif ($this->importo_pagato > 0) {
            $this->stato = 'parzialmente_pagata';
        } else {
            $this->stato = 'da_pagare';
            $this->data_pagamento = null;
            $this->save();
            return;
        }

        // 3. Data ultimo pagamento (CORRETTA)
        $ultimoMovimento = $this->pagamenti()
            ->orderByDesc('data_competenza')
            ->first();

        $this->data_pagamento = $ultimoMovimento?->data_competenza;

        $this->save();
    }
}