<?php

namespace App\Actions\Gestionale\Movimenti;

use App\Enums\TipoMovimentoContabile;
use App\Exceptions\Gestionale\RegolazioneImmediataNonAmmessaException;
use App\Helpers\MoneyHelper;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Fornitore;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\DoubleEntryValidator;
use Illuminate\Support\Facades\DB;

/**
 * Registrazione a regolazione immediata — prima nota diretta costo → banca/cassa.
 *
 * Per i fatti amministrativi che nascono e si estinguono nello stesso momento
 * (imposte di bollo, commissioni bancarie, addebiti automatici, piccole spese):
 * una sola scrittura, nessuna partita fornitore, nessuno stato di pagamento da
 * tracciare.
 *
 *   DARE   conto contabile del capitolo di spesa   importo
 *   AVERE  conto contabile della cassa/banca       importo
 *
 * Non sostituisce il flusso fattura → debito fornitore → pagamento: lo affianca.
 * Il fornitore, se indicato, è solo un TAG ANALITICO per la reportistica — non
 * movimenta il mastro Debiti v/Fornitori, non genera scadenziario né esposizione.
 *
 * Architettura: non è una nuova entità. È la ScritturaContabile che esiste già,
 * senza alcuna FatturaPassiva a monte e quindi senza righe nel pivot
 * fattura_scrittura (cfr. docs/registrazione_e_regolazione_immediata.md §3).
 */
class RegistraRegolazioneImmediataAction
{
    public function execute(array $validated, Condominio $condominio, Esercizio $esercizio): ScritturaContabile
    {
        $importoCents = MoneyHelper::toCents($validated['importo']);

        if ($importoCents <= 0) {
            throw new RegolazioneImmediataNonAmmessaException(
                'L\'importo di una regolazione immediata deve essere positivo.'
            );
        }

        // ── Guard rail (spec §6): vietata dove serve la struttura del debito ──
        $fornitore = null;

        if (! empty($validated['fornitore_id'])) {
            $fornitore = Fornitore::findOrFail($validated['fornitore_id']);

            // 1. Ritenuta d'acconto: il corrispettivo va spezzato netto/Erario.
            if ($fornitore->soggetto_ritenuta) {
                throw new RegolazioneImmediataNonAmmessaException(
                    "Il fornitore «{$fornitore->ragione_sociale}» è soggetto a ritenuta d'acconto: "
                    .'registra una fattura, così il sistema può separare il netto dovuto al fornitore '
                    .'dalla ritenuta da versare all\'Erario.'
                );
            }
        }

        // 2. Scadenziario / esposizione: se il debito va tracciato nel tempo non è
        //    un fatto che nasce e si estingue nello stesso momento.
        if (! empty($validated['genera_scadenza'])) {
            throw new RegolazioneImmediataNonAmmessaException(
                'La regolazione immediata non alimenta lo scadenziario: se il debito va tracciato '
                .'nel tempo, registra una fattura.'
            );
        }

        return DB::transaction(function () use ($validated, $condominio, $esercizio, $importoCents, $fornitore) {

            // ── Capitolo di spesa (DARE) ──────────────────────────────────────
            $capitolo = Conto::findOrFail($validated['conto_id']);

            $pianoConto = $capitolo->pianoConto;
            if (! $pianoConto || $pianoConto->condominio_id !== $condominio->id) {
                throw new RegolazioneImmediataNonAmmessaException(
                    'Il capitolo di spesa selezionato non appartiene a questo condominio.'
                );
            }

            if (! $capitolo->conto_contabile_id) {
                throw new RegolazioneImmediataNonAmmessaException(
                    "Il capitolo «{$capitolo->nome}» non ha un ancoraggio in partita doppia: "
                    .'associa un conto contabile prima di registrare movimenti su questo capitolo.'
                );
            }

            // ── Cassa / banca (AVERE) ─────────────────────────────────────────
            $cassa = Cassa::with('contoContabile')->findOrFail($validated['cassa_id']);

            if ($cassa->condominio_id !== $condominio->id) {
                throw new RegolazioneImmediataNonAmmessaException(
                    'La cassa selezionata non appartiene a questo condominio.'
                );
            }

            if (! $cassa->contoContabile) {
                throw new RegolazioneImmediataNonAmmessaException(
                    "La cassa «{$cassa->nome}» non ha un conto contabile associato."
                );
            }

            $dataOperazione = $validated['data_operazione'];

            $scrittura = ScritturaContabile::create([
                'condominio_id' => $condominio->id,
                'esercizio_id' => $esercizio->id,
                'gestione_id' => $validated['gestione_id'],
                'data_registrazione' => now()->toDateString(),
                'data_competenza' => $dataOperazione,
                'causale' => mb_substr($validated['causale'], 0, 255),
                'tipo_movimento' => TipoMovimentoContabile::REGOLAZIONE_IMMEDIATA,
                'stato' => 'registrata',
                'created_by' => auth()->id(),
            ]);

            // Il fornitore resta un tag analitico: lo agganciamo all'anagrafica
            // referente (stesso pattern di FatturaPassivaService), mai al mastro
            // Debiti v/Fornitori.
            $anagraficaTag = $fornitore?->referenti()->first()?->id;

            $scrittura->righe()->create([
                'conto_contabile_id' => $capitolo->conto_contabile_id,
                'voce_spesa_id' => $capitolo->id,
                'tipo_riga' => 'dare',
                'importo' => $importoCents,
                'anagrafica_id' => $anagraficaTag,
                'note' => $validated['causale'],
            ]);

            $scrittura->righe()->create([
                'conto_contabile_id' => $cassa->contoContabile->id,
                'cassa_id' => $cassa->id,
                'tipo_riga' => 'avere',
                'importo' => $importoCents,
                'note' => 'Regolazione immediata — '.$cassa->nome,
            ]);

            // Quadratura verificata fin dal primo giorno: tre dei cinque produttori
            // di scritture oggi non chiamano il validator, non aggiungiamo il quarto.
            DoubleEntryValidator::validateOrFail($scrittura->id);

            return $scrittura;
        });
    }
}
