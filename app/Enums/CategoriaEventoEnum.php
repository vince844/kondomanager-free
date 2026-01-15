<?php

namespace App\Enums;

enum CategoriaEventoEnum: string
{
    case MANUTENZIONE = 'Manutenzione';
    case ASSEMBLEA = 'Assemblea';
    case PULIZIA = 'Pulizia';
    case GENERICHE = 'Generiche';
    case RICHIESTE_INTERVENTO = 'Richieste di intervento';
    
    // Categorie Gestionali
    case SCADENZE_AMMINISTRATIVE = 'Scadenze amministrative';
    case SCADENZE_RATE_CONDOMINIALI = 'Scadenze rate';

    public function description(): string
    {
        return match($this) {
            self::MANUTENZIONE => 'Attività tecniche programmate o urgenti.',
            self::ASSEMBLEA => 'Incontri ufficiali tra i condomini.',
            self::PULIZIA => 'Interventi di pulizia ordinaria o straordinaria.',
            self::GENERICHE => 'Eventi generici.',
            self::RICHIESTE_INTERVENTO => 'Segnalazioni dai condomini.',
            self::SCADENZE_AMMINISTRATIVE => 'Promemoria tecnici per l\'amministratore (es. emissione rate).',
            self::SCADENZE_RATE_CONDOMINIALI => 'Scadenze di pagamento rate visibili ai condòmini.',
        };
    }
}