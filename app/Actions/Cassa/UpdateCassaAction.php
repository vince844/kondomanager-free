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
            $hasMovimenti = $cassa->movimenti()->exists();

            if ($cassa->tipo !== $data['tipo'] && $hasMovimenti) {
                throw ValidationException::withMessages([
                    'tipo' => 'Impossibile modificare il tipo: questa risorsa ha già movimenti contabili registrati.'
                ]);
            }

            // saldo_iniziale non ha un cast 'integer' sul model: lo normalizziamo
            // esplicitamente per evitare confronti stretti falsati da un valore
            // restituito come stringa dal driver DB (vedi CassaResource).
            $saldoInizialeAttuale = (int) $cassa->saldo_iniziale;

            // Se il campo non è presente nel payload, manteniamo il valore attuale
            // (non deve mai azzerarsi solo perché omesso dalla richiesta).
            $nuovoSaldoIniziale = isset($data['saldo_iniziale'])
                ? MoneyHelper::toCents($data['saldo_iniziale'])
                : $saldoInizialeAttuale;

            if ($hasMovimenti && $nuovoSaldoIniziale !== $saldoInizialeAttuale) {
                throw ValidationException::withMessages([
                    'saldo_iniziale' => 'Impossibile modificare il saldo di apertura: questa risorsa ha già movimenti contabili registrati.'
                ]);
            }

            // --- 2. AGGIORNAMENTO CASSA ---
            $cassa->update([
                'nome'        => $data['nome'],
                'tipo'        => $data['tipo'],
                'descrizione' => $data['descrizione'] ?? null,
                'saldo_iniziale' => $nuovoSaldoIniziale,
                'note'        => $data['note'] ?? null,
                // --- CAMPI GOVERNANCE FONDI ---
                'sottotipo_fondo'         => $data['sottotipo_fondo'] ?? null,
                'vincolo_descrizione'     => $data['vincolo_descrizione'] ?? null,
                'is_override_assemblea'   => filter_var($data['is_override_assemblea'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'motivazione_override'    => $data['motivazione_override'] ?? null,
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