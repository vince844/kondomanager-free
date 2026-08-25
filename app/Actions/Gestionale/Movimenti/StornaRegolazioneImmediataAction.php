<?php

namespace App\Actions\Gestionale\Movimenti;

use App\Enums\TipoMovimentoContabile;
use App\Exceptions\Gestionale\RegolazioneImmediataNonAmmessaException;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\DoubleEntryValidator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Storno di una registrazione a regolazione immediata.
 *
 * Il giornale è append-only: la scrittura originale non si cancella e non si
 * riscrive, si neutralizza con la sua inversa (DARE ↔ AVERE). È la regola cardine
 * del sigillo contabile — lo storno è SEMPRE ammesso, anche su una scrittura
 * sigillata, perché aggiunge un fatto nuovo invece di riscriverne uno passato.
 *
 * Cross-esercizio: se l'esercizio dell'originale è nel frattempo stato chiuso, lo
 * storno viene registrato nell'esercizio corrente aperto, con la provenienza
 * indicata in causale. Stesso paradigma della Variante B1 già adottata per lo
 * storno dei pagamenti fornitore.
 */
class StornaRegolazioneImmediataAction
{
    public function execute(ScritturaContabile $scrittura, Condominio $condominio, string $motivo): ScritturaContabile
    {
        if ($scrittura->condominio_id !== $condominio->id) {
            throw new RegolazioneImmediataNonAmmessaException(
                'La scrittura non appartiene a questo condominio.'
            );
        }

        if ($scrittura->tipo_movimento !== TipoMovimentoContabile::REGOLAZIONE_IMMEDIATA) {
            throw new RegolazioneImmediataNonAmmessaException(
                'Questa operazione storna solo le registrazioni a regolazione immediata. '
                .'Per gli altri movimenti usa lo storno del documento che li ha generati.'
            );
        }

        // Anti storno-dello-storno e anti doppio click.
        $giaStornata = ScritturaContabile::where('scrittura_padre_id', $scrittura->id)
            ->where('tipo_movimento', TipoMovimentoContabile::STORNO_REGOLAZIONE_IMMEDIATA)
            ->exists();

        if ($giaStornata) {
            throw new RegolazioneImmediataNonAmmessaException(
                "La registrazione {$scrittura->numero_protocollo} è già stata stornata."
            );
        }

        return DB::transaction(function () use ($scrittura, $condominio, $motivo) {

            $esercizioOriginale = $scrittura->esercizio;
            $crossEsercizio = false;
            $esercizioTarget = $esercizioOriginale;

            if (! $esercizioOriginale || $esercizioOriginale->stato === 'chiuso') {
                $esercizioTarget = Esercizio::where('condominio_id', $condominio->id)
                    ->where('stato', 'aperto')
                    ->first();

                if (! $esercizioTarget) {
                    throw new RegolazioneImmediataNonAmmessaException(
                        "L'esercizio della registrazione è chiuso e non esiste un esercizio "
                        .'aperto in cui appoggiare lo storno.'
                    );
                }

                $crossEsercizio = true;
            }

            $causale = $crossEsercizio
                ? sprintf(
                    'Storno %s: %s. Originale in esercizio «%s».',
                    $scrittura->numero_protocollo, $motivo, $esercizioOriginale?->nome ?? '—'
                )
                : sprintf('Storno %s: %s', $scrittura->numero_protocollo, $motivo);

            $storno = ScritturaContabile::create([
                'condominio_id' => $condominio->id,
                'esercizio_id' => $esercizioTarget->id,
                'gestione_id' => $scrittura->gestione_id,
                'scrittura_padre_id' => $scrittura->id,
                'data_registrazione' => now()->toDateString(),
                'data_competenza' => now()->toDateString(),
                'causale' => mb_substr($causale, 0, 255),
                'tipo_movimento' => TipoMovimentoContabile::STORNO_REGOLAZIONE_IMMEDIATA,
                'stato' => 'registrata',
                'created_by' => Auth::id(),
                'note' => 'Annullamento prot. '.$scrittura->numero_protocollo,
            ]);

            // Righe speculari: il capitolo di spesa si scarica, la cassa rientra.
            // voce_spesa_id viene propagato così il budget del capitolo torna libero.
            foreach ($scrittura->righe as $riga) {
                $storno->righe()->create([
                    'conto_contabile_id' => $riga->conto_contabile_id,
                    'cassa_id' => $riga->cassa_id,
                    'voce_spesa_id' => $riga->voce_spesa_id,
                    'anagrafica_id' => $riga->anagrafica_id,
                    'immobile_id' => $riga->immobile_id,
                    'tipo_riga' => $riga->tipo_riga === 'dare' ? 'avere' : 'dare',
                    'importo' => $riga->importo,
                    'note' => 'Storno — '.$riga->note,
                ]);
            }

            DoubleEntryValidator::validateOrFail($storno->id);

            return $storno;
        });
    }
}
