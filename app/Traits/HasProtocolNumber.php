<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;
use App\Models\Gestionale\FatturaPassiva; 

trait HasProtocolNumber
{
    protected static function bootHasProtocolNumber()
    {
        static::creating(function ($model) {
            if (empty($model->numero_protocollo)) {
                $model->numero_protocollo = static::generateProtocolNumber($model);
            }
        });
    }

    protected static function generateProtocolNumber($model): string
    {
        // 1. Decidiamo il prefisso
        if ($model instanceof FatturaPassiva) {
            $prefix = 'FTP';
        } else {
            $prefix = match($model->tipo_movimento ?? '') {
                'incasso_rata'        => 'INC',
                'pagamento_fornitore' => 'PAG',
                'giroconto'           => 'GIR',
                'rettifica'           => 'RET',
                'fattura_acquisto'    => 'FTP', 
                default               => 'SCR'
            };
        }

        $year = now()->format('Y');

        // 2. Transazione Atomica (Concorrenza sicura)
        return DB::transaction(function () use ($model, $prefix, $year) {
            
            $lastRecord = static::where('condominio_id', $model->condominio_id)
                ->where('numero_protocollo', 'like', "{$prefix}-{$year}-%")
                ->lockForUpdate()
                ->orderByRaw('LENGTH(numero_protocollo) DESC')
                ->orderBy('numero_protocollo', 'DESC')
                ->first();

            $lastNumber = 0;
            if ($lastRecord) {
                $parts = explode('-', $lastRecord->numero_protocollo);
                $lastNumber = (int) end($parts);
            }

            // 3. Formatta: Es. FTP-2026-00001 (usiamo 5 cifre per standard)
            return sprintf('%s-%s-%05d', $prefix, $year, $lastNumber + 1);
        });
    }
}