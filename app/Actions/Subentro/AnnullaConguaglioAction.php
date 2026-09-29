<?php

namespace App\Actions\Subentro;

use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Toglie **insieme** le righe di conguaglio di un passaggio (1.11.0-beta.31, B2 S6, voce 8).
 *
 * La coppia in `saldi` (credito a chi esce, debito a chi entra, invariante 19: somma zero) nasce dal
 * criterio di legge; se le parti hanno regolato il conguaglio in un altro modo l'amministratore la
 * toglie da qui — mai una gamba sola dal Wallet (`SaldoInizialeController::destroy` la rifiuta) — con
 * una nota che resta sul passaggio (`nota_annullamento_conguaglio`, `conguaglio_annullato_il`).
 *
 * Solo finché le righe sono **libere** (`is_applicato` falso): assorbite da un piano, il numero sta già
 * nelle quote e toglierlo qui lascerebbe il piano a raccontare un conguaglio che non esiste più. In
 * quel caso si dice quale piano e quale strada, col criterio di `PianoRate::eImmutabile()`: se non ha
 * emesso nulla a giornale né incassato, lo si riporta in bozza e lo si elimina (il lucchetto si riapre) e
 * si torna qui; se ha emesso o incassato, resta il saldo manuale di segno opposto. Le righe delle
 * pertinenze (subentri figli) vanno con il padre.
 */
final class AnnullaConguaglioAction
{
    public function execute(Subentro $subentro, string $nota): int
    {
        $nota = trim($nota);
        if (mb_strlen($nota) < 10) {
            throw ValidationException::withMessages(['nota_annullamento_conguaglio' => 'Scrivi in almeno dieci caratteri come le parti hanno regolato il conguaglio: resta sul passaggio.']);
        }

        return DB::transaction(function () use ($subentro, $nota) {
            $padre = Subentro::conAnnullati()->whereKey($subentro->subentro_padre_id ?? $subentro->id)->lockForUpdate()->firstOrFail();
            // Il passaggio annullato mentre si annullava il conguaglio (beta.37): un rifiuto leggibile, non un 404.
            if ($padre->annullato()) {
                throw ValidationException::withMessages(['conguaglio' => 'Questo passaggio è stato annullato, e il suo conguaglio con lui: non c\'è più niente da annullare.']);
            }
            if ($padre->conguaglioAnnullato()) {
                throw ValidationException::withMessages(['conguaglio' => 'Il conguaglio di questo passaggio è già stato annullato.']);
            }

            $ids = $padre->pertinenze()->pluck('id')->push($padre->id)->all();
            $righe = Saldo::whereIn('subentro_id', $ids)->with('pianoRate')->lockForUpdate()->get();
            if ($righe->isEmpty()) {
                throw ValidationException::withMessages(['conguaglio' => 'Questo passaggio non ha righe di conguaglio da annullare.']);
            }

            $assorbite = $righe->filter(fn (Saldo $s) => (bool) $s->is_applicato);
            if ($assorbite->isNotEmpty()) {
                $frase = self::fraseAssorbite($assorbite, 'Il conguaglio è già stato assorbito da un piano rate e non si annulla da qui.', 'torna qui');
                throw ValidationException::withMessages(['conguaglio' => $frase]);
            }

            if ((int) $righe->sum('saldo_iniziale') !== 0) {
                throw ValidationException::withMessages(['conguaglio' => 'Le righe del conguaglio non sommano zero: qualcosa è stato modificato a mano. Controlla i saldi della gestione prima di annullare.']);
            }

            $tolte = Saldo::whereIn('id', $righe->pluck('id'))->delete();
            $padre->update(['conguaglio_annullato_il' => now(), 'nota_annullamento_conguaglio' => $nota]);

            return $tolte;
        });
    }

    /**
     * Che cosa fare quando il conguaglio è già assorbito da un piano, col criterio di `PianoRate::eImmutabile()` — rate
     * emesse a giornale o movimenti di denaro, non lo stato del piano (verifica S6, R9): `is_applicato` si accende alla
     * GENERAZIONE, e un piano approvato ma non ancora emesso è lo stato normale fra la delibera e l'emissione. Finché non
     * ha emesso nulla la strada pulita esiste: torna in bozza, elimina il piano (il lucchetto si riapre), torna qui.
     * La usa anche l'annullamento del passaggio (1.11.0-beta.37): una regola, due porte.
     *
     * @param \Illuminate\Support\Collection<int, Saldo> $assorbite righe con `is_applicato`, con `pianoRate` caricato
     */
    public static function fraseAssorbite(\Illuminate\Support\Collection $assorbite, string $apertura, string $ritorno): string
    {
        $piani = $assorbite->map(fn (Saldo $s) => $s->pianoRate)->filter()->unique('id');
        $correggibili = $piani->reject(fn ($p) => $p->eImmutabile())->pluck('nome')->all();
        $immutabili = $piani->filter(fn ($p) => $p->eImmutabile())->pluck('nome')->all();
        $frase = $apertura;
        if ($correggibili !== []) {
            $frase .= sprintf(' %s «%s» non %s ancora emesso nulla: se %s approvat%s riportal%s in bozza, elimina il piano (il lucchetto si riapre) e %s; poi rifai il piano.',
                count($correggibili) === 1 ? 'Il piano' : 'I piani', implode('», «', $correggibili), count($correggibili) === 1 ? 'ha' : 'hanno',
                count($correggibili) === 1 ? 'è' : 'sono', count($correggibili) === 1 ? 'o' : 'i', count($correggibili) === 1 ? 'o' : 'i', $ritorno);
        }
        if ($immutabili !== []) {
            $frase .= sprintf(' %s «%s» %s già emesso in contabilità o con incassi registrati: le quote sono in mano ai condòmini, e la correzione passa da un saldo manuale di segno opposto sulla stessa gestione.',
                count($immutabili) === 1 ? 'Il piano' : 'I piani', implode('», «', $immutabili), count($immutabili) === 1 ? 'è' : 'sono');
        }

        return $frase;
    }
}
