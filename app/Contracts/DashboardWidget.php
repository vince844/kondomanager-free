<?php

namespace App\Contracts;

interface DashboardWidget
{
    /**
     * Chiave univoca per identificare il widget nel frontend (es. 'treasury_guardian')
     */
    public function key(): string;

    /**
     * Determina se il widget deve essere renderizzato per il condominio corrente.
     * (es. il Radar Salute Contabile potrebbe essere nascosto se va tutto bene).
     */
    public function isVisible(int $condominioId): bool;

    /**
     * Restituisce i dati serializzati pronti per essere passati a Inertia/Vue.
     */
    public function payload(int $condominioId): array;
}
