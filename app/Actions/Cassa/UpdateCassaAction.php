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
    public function __construct(
        private RegistraAperturaCassaAction $aperturaAction
    ) {}

    public function execute(Cassa $cassa, array $data): Cassa
    {
        return DB::transaction(function () use ($cassa, $data) {
            
            // --- 1. CONTROLLO DI SICUREZZA ---
            // Questo blocco viene eseguito PRIMA di qualsiasi modifica.
            // Se scatta l'eccezione, la transazione si ferma e nulla cambia.
            // Movimenti OPERATIVI: la scrittura di apertura non conta, la genera il
            // sistema. Senza questa distinzione, dalla beta.25 ogni cassa risulterebbe
            // "con movimenti" fin dalla creazione e non sarebbe più modificabile.
            $hasMovimenti = $cassa->hasMovimentiOperativi();

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

            // Guardia SEPARATA da $hasMovimenti: l'apertura sposta il saldo dalla
            // colonna alla scrittura anche su una cassa senza nessun altro
            // movimento (RegistraAperturaCassaAction). Da quel momento la colonna
            // vale 0 e riscriverla la riporterebbe in vita accanto alla scrittura
            // già a giornale — SaldoCassaService la conterebbe due volte.
            if ($cassa->hasAperturaRegistrata()) {
                // Il campo è diventato puramente informativo (il frontend vi mostra
                // il saldo reale corrente, disabilitato): qualunque cosa arrivi nel
                // payload — anche un salvataggio che non tocca affatto questo campo,
                // che comunque lo re-invia — la colonna resta congelata a zero.
                $nuovoSaldoIniziale = $saldoInizialeAttuale;
            } elseif ($hasMovimenti && $nuovoSaldoIniziale !== $saldoInizialeAttuale) {
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

            // --- 2bis. IL SALDO DI APERTURA VA A GIORNALE ---
            //
            // Senza questa chiamata la colonna restava piena e il giornale vuoto, e lo Stato
            // Patrimoniale si sbilanciava esattamente di quell'importo. Il percorso era
            // riproducibile col mouse: cassa creata con il saldo vuoto — dove
            // `CreateCassaAction` chiama l'azione, che esce subito su importo zero — e importo
            // aggiunto **dopo**, in modifica, dove non lo chiamava nessuno.
            //
            // La beta.26 aveva messo mano a questa stessa guardia guardando la direzione
            // opposta: là si riscriveva la colonna con l'apertura già a giornale (doppio
            // conteggio), qui si scrive la colonna con l'apertura mai registrata. Stesso
            // campo, stesso effetto sullo Stato Patrimoniale, verso contrario.
            //
            // L'azione è già idempotente e sa uscire da sola su importo zero, apertura
            // presente o dati insufficienti: qui non si ripete nessuno dei suoi controlli.
            // Siamo dentro la transazione della modifica, quindi o la cassa cambia e
            // l'apertura è a giornale, o non è successo niente.
            $this->aperturaAction->execute($cassa);

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