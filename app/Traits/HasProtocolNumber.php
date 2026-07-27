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
        // FIX v1.9.1: tipo_movimento su ScritturaContabile ora ha cast Enum.
        // $model->tipo_movimento restituisce un BackedEnum, non una stringa.
        // Estraiamo il valore stringa esplicitamente per il match.
        $tipoMov = $model->tipo_movimento;
        if ($tipoMov instanceof \BackedEnum) {
            $tipoMov = $tipoMov->value;
        }
        $tipoMov = (string) ($tipoMov ?? '');

        $prefix = ($model instanceof FatturaPassiva) ? 'FTP' : match($tipoMov) {
            'incasso_rata'                => 'INC',
            'pagamento_fornitore'         => 'PAG',
            'storno_pagamento_fornitore'  => 'STO',
            'giroconto'                   => 'GIR',
            'storno_giroconto'            => 'STO',
            'rettifica'                   => 'RET',
            'fattura_acquisto'            => 'FTP',
            'regolazione_immediata'       => 'RIM',
            'storno_regolazione_immediata'=> 'STO',
            'pagamento_f24'               => 'F24',
            'storno_pagamento_f24'        => 'STO',
            default                       => 'SCR',
        };

        return DB::transaction(fn () => static::nextNumberFor($model->condominio_id, $prefix));
    }

    /**
     * Anteprima non vincolante del prossimo numero, per mostrarlo in form PRIMA
     * del salvataggio (es. Regolazione immediata). Nessuna transazione/lock: è
     * solo indicativo e può scostarsi se nel frattempo viene registrato un altro
     * movimento con lo stesso prefisso — il numero vero resta quello assegnato
     * da generateProtocolNumber() al salvataggio effettivo.
     */
    public static function previewNextProtocolNumber(int $condominioId, string $prefix): string
    {
        return static::nextNumberFor($condominioId, $prefix);
    }

    private static function nextNumberFor(int $condominioId, string $prefix): string
    {
        $year = now()->format('Y');

        $maxInFatture = DB::table('fatture_passive')
            ->where('condominio_id', $condominioId)
            ->where('numero_protocollo', 'like', "{$prefix}-{$year}-%")
            ->max('numero_protocollo');

        $maxInScritture = DB::table('scritture_contabili')
            ->where('condominio_id', $condominioId)
            ->where('numero_protocollo', 'like', "{$prefix}-{$year}-%")
            ->max('numero_protocollo');

        $lastProtocol = max($maxInFatture, $maxInScritture);

        $lastNumber = 0;
        if ($lastProtocol) {
            $parts = explode('-', $lastProtocol);
            $lastNumber = (int) end($parts);
        }

        return sprintf('%s-%s-%05d', $prefix, $year, $lastNumber + 1);
    }
}