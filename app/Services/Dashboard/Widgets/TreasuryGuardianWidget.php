<?php

namespace App\Services\Dashboard\Widgets;

use App\Contracts\DashboardWidget;
use App\Services\Treasury\TreasuryGuardianService;

class TreasuryGuardianWidget implements DashboardWidget
{
    public function __construct(
        private readonly TreasuryGuardianService $treasuryService
    ) {}

    public function key(): string
    {
        return 'treasury_guardian';
    }

    public function isVisible(int $condominioId): bool
    {
        // Sempre visibile in MVP
        return true;
    }

    public function payload(int $condominioId): array
    {
        return $this->treasuryService->perCondominio($condominioId)->toArray();
    }
}
