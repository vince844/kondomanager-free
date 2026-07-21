<?php

namespace App\Actions\Gestionale\Movimenti;

use App\Enums\TipoMovimentoContabile;
use App\Exceptions\Gestionale\GirocontoNonAmmessoException;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\FatturaCopertura;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\DoubleEntryValidator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Storno di un giroconto.
 *
 * Il giornale è append-only: la scrittura originale non si cancella e non si
 * riscrive, si neutralizza con la sua inversa (DARE ↔ AVERE). Lo storno è SEMPRE
 * ammesso e NON verifica la capienza: bloccarlo impedirebbe di correggere un
 * errore. Se lo storno lascia un fondo negativo (perché a valle c'è un altro
 * movimento), il rosso è visibile nell'elenco casse e si risana stornando anche
 * il movimento a valle.
 *
 * Se il giroconto aveva confermato una copertura fattura, la copertura torna
 * 'pianificata' nella stessa transazione: la fattura risulta di nuovo in attesa
 * di conferma, come prima del giroconto.
 *
 * Cross-esercizio: Variante B1 — se l'esercizio dell'originale è chiuso, lo
 * storno si appoggia all'esercizio corrente aperto con la provenienza in causale.
 */
class StornaGirocontoAction
{
    public function execute(ScritturaContabile $scrittura, Condominio $condominio, string $motivo): ScritturaContabile
    {
        if ($scrittura->condominio_id !== $condominio->id) {
            throw new GirocontoNonAmmessoException(
                'La scrittura non appartiene a questo condominio.'
            );
        }

        if ($scrittura->tipo_movimento !== TipoMovimentoContabile::GIROCONTO) {
            throw new GirocontoNonAmmessoException(
                'Questa operazione storna solo i giroconti. '
                .'Per gli altri movimenti usa lo storno del documento che li ha generati.'
            );
        }

        // Anti storno-dello-storno e anti doppio click.
        return DB::transaction(function () use ($scrittura, $condominio, $motivo) {

            // Anti storno-dello-storno e anti doppio click. Il lock sulla scrittura
            // padre serializza due richieste simultanee (doppio tab, retry di rete):
            // senza, entrambe passerebbero il controllo e il giroconto verrebbe
            // invertito due volte.
            ScritturaContabile::whereKey($scrittura->id)->lockForUpdate()->first();

            $giaStornato = ScritturaContabile::where('scrittura_padre_id', $scrittura->id)
                ->where('tipo_movimento', TipoMovimentoContabile::STORNO_GIROCONTO)
                ->exists();

            if ($giaStornato) {
                throw new GirocontoNonAmmessoException(
                    "Il giroconto {$scrittura->numero_protocollo} è già stato stornato."
                );
            }

            $esercizioOriginale = $scrittura->esercizio;
            $crossEsercizio = false;
            $esercizioTarget = $esercizioOriginale;

            if (! $esercizioOriginale || $esercizioOriginale->stato === 'chiuso') {
                $esercizioTarget = Esercizio::where('condominio_id', $condominio->id)
                    ->where('stato', 'aperto')
                    ->first();

                if (! $esercizioTarget) {
                    throw new GirocontoNonAmmessoException(
                        "L'esercizio del giroconto è chiuso e non esiste un esercizio "
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
                'tipo_movimento' => TipoMovimentoContabile::STORNO_GIROCONTO,
                'stato' => 'registrata',
                'created_by' => Auth::id(),
                'note' => 'Annullamento prot. '.$scrittura->numero_protocollo,
            ]);

            // Righe speculari: la liquidità torna esattamente da dove era partita.
            // cassa_id propagato, così ogni vista per-cassa resta coerente.
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

            // Se il giroconto aveva confermato una copertura, la conferma decade:
            // la fattura torna in attesa, come prima del giroconto.
            FatturaCopertura::where('scrittura_giroconto_id', $scrittura->id)
                ->update([
                    'stato' => 'pianificata',
                    'scrittura_giroconto_id' => null,
                    'confermata_at' => null,
                ]);

            return $storno;
        });
    }
}
