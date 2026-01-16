<?php

namespace App\Actions\Cassa;

use App\Models\Gestionale\Cassa;
use App\Models\Condominio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateCassaAction
{
    public function __construct(
        private CreateCassaAccountAction $accountAction,
        private CreateCassaModelAction $modelAction,
        private CreateCassaBankAccountAction $bankAction
    ) {}

    public function execute(Condominio $condominio, array $data): Cassa
    {
        return DB::transaction(function () use ($condominio, $data) {
            
            // 1. Crea Conto Contabile
            $conto = $this->accountAction->execute($condominio, $data);

            // 2. Crea Entità Cassa
            $cassa = $this->modelAction->execute($condominio, $conto, $data);

            // 3. Gestisci Conto Corrente (se banca)
            $this->bankAction->execute($cassa, $data);

            Log::info("Nuova cassa creata", [
                'condominio_id' => $condominio->id,
                'cassa_id' => $cassa->id
            ]);

            return $cassa;
        });
    }
}