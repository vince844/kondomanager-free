<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;

trait HasProtocolNumber
{
    // Questo metodo viene chiamato automaticamente da Laravel all'avvio del Model
    protected static function bootHasProtocolNumber()
    {
        static::creating(function ($model) {
            // Se non c'è già un numero manuale, ne generiamo uno automatico
            if (empty($model->numero_protocollo)) {
                $model->numero_protocollo = static::generateProtocolNumber($model);
            }
        });
    }

    protected static function generateProtocolNumber($model): string
    {
        // 1. Decidiamo il prefisso
        $prefix = match($model->tipo_movimento ?? '') {
            'incasso_rata'        => 'INC',
            'pagamento_fornitore' => 'PAG',
            'giroconto'           => 'GIR',
            'rettifica'           => 'RET', // Usato per lo Storno
            default               => 'SCR'
        };

        $year = now()->format('Y');

        // 2. Transazione Atomica per trovare il prossimo numero
        return DB::transaction(function () use ($model, $prefix, $year) {
            
            // BLOCCO LEGGENDO L'ULTIMO RECORD (Concorrenza sicura)
            $lastRecord = static::where('condominio_id', $model->condominio_id)
                ->where('numero_protocollo', 'like', "{$prefix}-{$year}-%")
                ->lockForUpdate() // <--- MAGIA QUI
                ->orderByRaw('LENGTH(numero_protocollo) DESC') // Ordina numeri (9, 10...)
                ->orderBy('numero_protocollo', 'DESC')
                ->first();

            $lastNumber = 0;
            if ($lastRecord) {
                // Estrae la parte numerica finale (es. da INC-2025-00042 estrae 42)
                $parts = explode('-', $lastRecord->numero_protocollo);
                $lastNumber = (int) end($parts);
            }

            // 3. Formatta il nuovo codice: Es. INC-2025-00043
            return sprintf('%s-%s-%05d', $prefix, $year, $lastNumber + 1);
        });
    }
}