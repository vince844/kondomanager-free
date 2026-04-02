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
        $prefix = ($model instanceof FatturaPassiva) ? 'FTP' : match($model->tipo_movimento ?? '') {
            'incasso_rata'        => 'INC',
            'pagamento_fornitore' => 'PAG',
            'giroconto'           => 'GIR',
            'rettifica'           => 'RET',
            'fattura_acquisto'    => 'FTP', 
            default               => 'SCR'
        };

        $year = now()->format('Y');

        return DB::transaction(function () use ($model, $prefix, $year) {
            // 1. Cerchiamo il numero massimo in ENTRAMBE le tabelle per quel prefisso
            
            $maxInFatture = DB::table('fatture_passive')
                ->where('condominio_id', $model->condominio_id)
                ->where('numero_protocollo', 'like', "{$prefix}-{$year}-%")
                ->max('numero_protocollo');

            $maxInScritture = DB::table('scritture_contabili')
                ->where('condominio_id', $model->condominio_id)
                ->where('numero_protocollo', 'like', "{$prefix}-{$year}-%")
                ->max('numero_protocollo');

            // 2. Prendiamo il più alto tra i due
            $lastProtocol = max($maxInFatture, $maxInScritture);
            
            $lastNumber = 0;
            if ($lastProtocol) {
                $parts = explode('-', $lastProtocol);
                $lastNumber = (int) end($parts);
            }

            return sprintf('%s-%s-%05d', $prefix, $year, $lastNumber + 1);
        });
    }
}