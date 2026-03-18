<?php

namespace App\Models\Gestionale;

use App\Models\Saldo;
use Illuminate\Database\Eloquent\Model;

class FatturaCopertura extends Model
{
    protected $table = 'fattura_coperture';
    protected $guarded = ['id'];

    protected $casts = [
        'importo' => 'integer', // Sempre in centesimi!
    ];

    /**
     * La fattura pregressa a cui appartiene questa copertura
     */
    public function fattura()
    {
        return $this->belongsTo(FatturaPassiva::class, 'fattura_passiva_id');
    }

    // ── RELAZIONI CONTESTUALI (Solo una sarà non-null in base al tipo) ───────

    /**
     * FK valorizzata se tipo_copertura = 'rata_0'
     * Punta al credito verso il condòmino
     */
    public function saldoCondomino()
    {
        return $this->belongsTo(Saldo::class, 'saldo_id');
    }

    /**
     * FK valorizzata se tipo_copertura = 'sopravvenienza'
     * Punta al conto economico 2026 (preventivo corrente)
     */
    public function vocePreventivoCorrente()
    {
        return $this->belongsTo(Conto::class, 'conto_id');
    }

    /**
     * FK valorizzata se tipo_copertura = 'fondo_riserva'
     * Punta al conto contabile del fondo
     */
    public function fondoRiserva()
    {
        return $this->belongsTo(ContoContabile::class, 'fondo_id');
    }

    // ── SCOPES PER IL SEMAFORO FINANZIARIO E RENDICONTO ──────────────────────

    public function scopeConfermate($query)
    {
        return $query->where('stato', 'confermata');
    }

    public function scopePianificate($query)
    {
        return $query->where('stato', 'pianificata');
    }
}