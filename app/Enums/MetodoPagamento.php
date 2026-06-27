<?php

namespace App\Enums;

/**
 * Mezzo giuridico/tecnico con cui viene disposto il pagamento al fornitore.
 *
 * Persistito come VARCHAR(50) su pagamenti_fornitori.metodo_pagamento.
 *
 * In v1.16 (Treasury) si valuterà aggiungere `gateway`, `provider`, `channel`
 * per i pagamenti PSD2/PISP — in quel momento si aggiungono case qui.
 */
enum MetodoPagamento: string
{
    case BONIFICO = 'bonifico';
    case CONTANTI = 'contanti';
    case ASSEGNO  = 'assegno';
    case RID_SDD  = 'rid_sdd';
    case ALTRO    = 'altro';

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function label(): string
    {
        return match($this) {
            self::BONIFICO => 'Bonifico bancario',
            self::CONTANTI => 'Contanti',
            self::ASSEGNO  => 'Assegno',
            self::RID_SDD  => 'RID / SDD',
            self::ALTRO    => 'Altro',
        };
    }

    /** Richiede IBAN destinatario per poter essere registrato. */
    public function richiedeIban(): bool
    {
        return $this === self::BONIFICO;
    }

    /**
     * Soggetto al limite antiriciclaggio contanti (D.Lgs. 231/2007).
     * Soglia corrente: 5.000 € (dal 01/01/2023).
     */
    public function isContante(): bool
    {
        return $this === self::CONTANTI;
    }
}