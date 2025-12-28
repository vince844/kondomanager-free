<?php

namespace App\Actions\Cassa;

use App\Models\Gestionale\Cassa;
use App\Models\ContoCorrente;
use App\Enums\TipoCassa;
use App\Helpers\MoneyHelper;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateCassaAction
{
    public function execute(Cassa $cassa, array $data): Cassa
    {
        return DB::transaction(function () use ($cassa, $data) {
            
            // --- 1. CONTROLLO DI SICUREZZA ---
            // Questo blocco viene eseguito PRIMA di qualsiasi modifica.
            // Se scatta l'eccezione, la transazione si ferma e nulla cambia.
            if ($cassa->tipo !== $data['tipo']) {
                
                // TODO: Ricordati di rimuovere il false fisso quando avrai i movimenti!
                // $hasMovimenti = $cassa->contoContabile?->movimenti()->exists();
                $hasMovimenti = false; 

                if ($hasMovimenti) {
                    throw ValidationException::withMessages([
                        'tipo' => 'Impossibile modificare il tipo: questa risorsa ha già movimenti contabili registrati.'
                    ]);
                }
            }

            // --- 2. AGGIORNAMENTO CASSA ---
            $cassa->update([
                'nome'        => $data['nome'],
                'tipo'        => $data['tipo'], 
                'descrizione' => $data['descrizione'] ?? null,
                'saldo_iniziale' => isset($data['saldo_iniziale']) 
                                ? MoneyHelper::toCents($data['saldo_iniziale']) 
                                : 0,
                'note'        => $data['note'] ?? null,
            ]);

            // --- 3. AGGIORNAMENTO CONTO CONTABILE (Nome + Ruolo) ---
            if ($cassa->contoContabile) {
                
                // Utilizziamo l'Enum per garantire coerenza con la creazione
                $nuovoRuolo = TipoCassa::getRuoloFromValue($data['tipo']);

                $cassa->contoContabile->update([
                    'nome'        => $data['nome'],
                    'descrizione' => $data['descrizione'] ?? null,
                    'ruolo'       => $nuovoRuolo, 
                ]);
            }

            // --- 4. GESTIONE DATI BANCARI ---
            // Usiamo l'Enum anche per il controllo, è più elegante
            if ($data['tipo'] === TipoCassa::BANCA->value) {
                $this->handleBancaUpdate($cassa, $data);
            } else {
                // Se non è più banca (ed è sicuro farlo perché non ci sono movimenti), puliamo.
                $cassa->contoCorrente()->delete();
            }

            Log::info("Cassa aggiornata", ['id' => $cassa->id, 'tipo' => $cassa->tipo]);

            return $cassa;
        });
    }

    private function handleBancaUpdate(Cassa $cassa, array $data): void
    {
        $isPredefinito = filter_var($data['predefinito'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($isPredefinito) {
            ContoCorrente::whereHasMorph('contable', [Cassa::class], function ($query) use ($cassa) {
                    $query->where('condominio_id', $cassa->condominio_id);
                })
                ->where('id', '!=', $cassa->contoCorrente?->id)
                ->update(['predefinito' => 0]);
        }

        $cassa->contoCorrente()->updateOrCreate(
            ['id' => $cassa->contoCorrente?->id],
            [
                'contable_id'   => $cassa->id,
                'contable_type' => Cassa::class,
                'iban'          => $data['iban'] ?? null,
                'istituto'      => $data['istituto'] ?? null,
                'swift'         => $data['bic'] ?? null,
                'intestatario'  => $data['intestatario'] ?? $cassa->condominio->nome,
                'tipo'          => $data['tipo_conto'] ?? 'ordinario',
                'indirizzo'     => $data['indirizzo'] ?? null,
                'comune'        => $data['comune'] ?? null,
                'cap'           => $data['cap'] ?? null,
                'provincia'     => $data['provincia'] ?? null,
                'nazione'       => 'Italia',
                'predefinito'   => $isPredefinito,
            ]
        );
    }
}