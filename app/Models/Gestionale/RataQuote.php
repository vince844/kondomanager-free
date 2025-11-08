<?php

namespace App\Models\Gestionale;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Immobile;

class RataQuote extends Model
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
        'note'
    ];

    protected $casts = [
        'importo' => 'integer',
        'importo_pagato' => 'integer',
        'data_scadenza' => 'date',
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

    // === ACCESSORS ===
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

    public function registraPagamento(int $importoCentesimi, ?string $riferimento = null): void
    {
        $this->importo_pagato += $importoCentesimi;

        if ($this->importo_pagato >= $this->importo) {
            $this->stato = 'pagata';
            $this->importo_pagato = $this->importo;
            $this->data_pagamento = now();
        } else {
            $this->stato = 'parzialmente_pagata';
        }

        if ($riferimento) {
            $this->riferimento_pagamento = $riferimento;
        }

        $this->save();
    }
}
