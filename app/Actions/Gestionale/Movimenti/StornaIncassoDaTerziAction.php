<?php

namespace App\Actions\Gestionale\Movimenti;

use App\Enums\NaturaStorno;
use App\Enums\TipoMovimentoContabile;
use App\Exceptions\Gestionale\IncassoDaTerziNonAmmessoException;
use App\Helpers\DateHelper;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\DoubleEntryValidator;
use App\Services\Gestionale\VoceNelloStorno;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Lo storno di un incasso da terzi: la scrittura inversa (DARE ↔ AVERE, stessi conti, la voce e chi ha versato ricopiati), come lo storno della
 * regolazione immediata (`StornaRegolazioneImmediataAction`). L'originale resta «registrata» e il registro la segna stornata (è uno degli
 * storni di scrittura, `TipoMovimentoContabile::isStornoDiScrittura()`).
 *
 * **La natura la dice chi storna**, come per lo storno di un incasso delle rate, e decide la data dello storno con la stessa regola
 * (`StornoIncassoRateAction::valeDal`): una registrazione sbagliata (il denaro non è mai entrato, o va registrato di nuovo in un altro modo)
 * vale dal giorno dell'incasso, se l'esercizio che contiene quella data è aperto; un incasso tornato indietro (bonifico richiamato, accredito
 * respinto) vale da oggi, il giorno dell'amministratore. La natura si scrive sull'incasso stornato.
 *
 * L'esercizio dello storno è quello dell'originale se è aperto; se è chiuso, l'esercizio aperto del condominio, con la provenienza in causale. Lì una
 * voce di una gestione che l'esercizio dello storno non ha (o ha con il legame spento) non si ricopia: la riga va senza voce, sullo stesso conto, con la
 * voce di allora nella nota ({@see VoceNelloStorno}).
 */
class StornaIncassoDaTerziAction
{
    public function execute(ScritturaContabile $scrittura, Condominio $condominio, NaturaStorno $natura, string $motivo): ScritturaContabile
    {
        if ((int) $scrittura->condominio_id !== (int) $condominio->id) {
            throw new IncassoDaTerziNonAmmessoException('La scrittura non appartiene a questo condominio.');
        }

        if ($scrittura->tipo_movimento !== TipoMovimentoContabile::INCASSO_DIVERSO) {
            throw new IncassoDaTerziNonAmmessoException(
                'Questa operazione storna solo gli incassi da terzi. Per gli altri movimenti usa lo storno del documento che li ha generati.'
            );
        }

        return DB::transaction(function () use ($scrittura, $condominio, $natura, $motivo) {
            // Il secondo storno, anche da un doppio clic: l'originale bloccato, le figlie rilette dentro la transazione.
            ScritturaContabile::whereKey($scrittura->id)->lockForUpdate()->first();
            $giaStornata = ScritturaContabile::where('scrittura_padre_id', $scrittura->id)
                ->where('tipo_movimento', TipoMovimentoContabile::STORNO_INCASSO_DIVERSO->value)
                ->exists();

            if ($giaStornata) {
                throw new IncassoDaTerziNonAmmessoException("L'incasso {$scrittura->numero_protocollo} è già stato stornato.");
            }

            $oggi = DateHelper::oggiUtente();
            $valeDal = StornoIncassoRateAction::valeDal(
                (int) $condominio->id,
                $natura,
                $scrittura->data_competenza?->format('Y-m-d') ?? $oggi,
                $oggi
            )['vale_dal'];

            $esercizioOriginale = $scrittura->esercizio;
            $esercizioTarget = $esercizioOriginale;
            $altroEsercizio = false;

            if (! $esercizioOriginale || $esercizioOriginale->stato === 'chiuso') {
                $esercizioTarget = Esercizio::where('condominio_id', $condominio->id)->where('stato', 'aperto')->first();

                if (! $esercizioTarget) {
                    throw new IncassoDaTerziNonAmmessoException(
                        "L'esercizio dell'incasso è chiuso e non c'è un esercizio aperto in cui registrare lo storno."
                    );
                }

                $altroEsercizio = true;
            }

            $causale = $altroEsercizio
                ? sprintf('Storno %s: %s. Originale nell\'esercizio «%s».', $scrittura->numero_protocollo, $motivo, $esercizioOriginale?->nome ?? '—')
                : sprintf('Storno %s: %s', $scrittura->numero_protocollo, $motivo);

            $storno = ScritturaContabile::create([
                'condominio_id' => $condominio->id,
                'esercizio_id' => $esercizioTarget->id,
                'gestione_id' => $scrittura->gestione_id,
                'scrittura_padre_id' => $scrittura->id,
                'data_registrazione' => $oggi,
                'data_competenza' => $valeDal,
                'causale' => mb_substr($causale, 0, 255),
                'tipo_movimento' => TipoMovimentoContabile::STORNO_INCASSO_DIVERSO,
                'stato' => 'registrata',
                'created_by' => Auth::id(),
                'note' => 'Annullamento prot. '.$scrittura->numero_protocollo,
            ]);

            foreach ($scrittura->righe as $riga) {
                [$voce, $perche] = VoceNelloStorno::per($riga->voce_spesa_id, $esercizioOriginale, $esercizioTarget);
                $storno->righe()->create([
                    'conto_contabile_id' => $riga->conto_contabile_id,
                    'cassa_id' => $riga->cassa_id,
                    'voce_spesa_id' => $voce,
                    'anagrafica_id' => $riga->anagrafica_id,
                    'tipo_riga' => $riga->tipo_riga === 'dare' ? 'avere' : 'dare',
                    'importo' => $riga->importo,
                    'note' => $perche === null ? 'Storno — '.$riga->note : rtrim('Storno — '.$riga->note).' '.$perche,
                ]);
            }

            DoubleEntryValidator::validateOrFail($storno->id);

            $scrittura->update(['natura_storno' => $natura->value]);

            return $storno;
        });
    }
}
