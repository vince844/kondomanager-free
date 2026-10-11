<?php

namespace App\Actions\PianoRate;

use App\Models\Gestionale\PianoRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DC4 (decisione 75.3, strada b; 1.11.0-beta.49, voce P7): il ricalcolo del piano e «togli una voce» cancellano le rate e le
 * rigenerano. Un incasso, una compensazione o un rimborso stornati restano legati alle quote che toccavano — la pivot
 * `quota_scrittura` somma a zero, ed è per questo che il ricalcolo è ammesso (decisioni 38–47) — e con le quote la pivot andava
 * via a cascata, le righe del giornale perdevano la rata (`righe_scritture.rata_id`, nullOnDelete) e la pagina dell'incasso
 * stornato non diceva più che cosa pagava. Qui i legami si annotano prima e si rimettono dopo, sulla quota equivalente: la rata
 * con lo stesso numero, la stessa persona, la stessa unità. Nessun importo cambia.
 *
 * ⚠️ **Eccezione dichiarata a «il giornale è in sola aggiunta» (decisione 76.2).** Le righe del giornale che avevano una rata del
 * piano riprendono la rata nuova con lo stesso numero: si riscrive solo `righe_scritture.rata_id`, mai un importo, un conto, una
 * data o una persona. È la stessa colonna che la chiave esterna azzerava nella stessa transazione: il legame si rimette, non si
 * inventa. Vale anche per le righe senza pivot (la rettifica del riallineamento dei crediti e il suo storno).
 *
 * Le quote rimesse non si ricalcolano (`ricalcolaStato()`): la pivot di ognuna somma a zero, e la quota resta com'è nata —
 * `importo_pagato` 0 e lo stato del generatore. Ricalcolata, una quota a credito diventerebbe «pagata» (0 ≥ importo), e il
 * conguaglio di un passaggio dopo la leggerebbe come una bozza pagata (`ConguaglioPassaggio`, motivo «pagata»).
 *
 * Limite: se la persona non è più nella rata (un passaggio registrato fra la generazione e il ricalcolo), il legame si perde
 * come prima; il log lo conta. La terza sede di DC4, l'annullamento di un passaggio che riunisce la gemella del pregresso, sta
 * in `AnnullaPassaggioAction`, dove la quota equivalente è certa.
 */
final class RigeneraRateTenendoILegami
{
    /**
     * @template T
     * @param callable(): T $genera la generazione delle rate nuove, dopo che le vecchie sono state cancellate
     * @return T
     */
    public function esegui(PianoRate $piano, callable $genera): mixed
    {
        $numeroDi = DB::table('rate')->where('piano_rate_id', $piano->id)->pluck('numero_rata', 'id')->map(fn ($n) => (int) $n);
        $legami = DB::table('quota_scrittura')->join('rate_quote', 'rate_quote.id', '=', 'quota_scrittura.rate_quota_id')
            ->whereIn('rate_quote.rata_id', $numeroDi->keys())
            ->orderBy('quota_scrittura.id')
            ->get(['quota_scrittura.scrittura_contabile_id', 'quota_scrittura.importo_pagato', 'quota_scrittura.data_pagamento',
                'quota_scrittura.created_at', 'quota_scrittura.updated_at', 'rate_quote.rata_id', 'rate_quote.anagrafica_id',
                'rate_quote.immobile_id', 'rate_quote.tipo', 'rate_quote.importo']);
        $righe = DB::table('righe_scritture')->whereIn('rata_id', $numeroDi->keys())->get(['id', 'rata_id']);

        $piano->rate()->delete();
        $esito = $genera();

        $rataNuova = DB::table('rate')->where('piano_rate_id', $piano->id)->pluck('id', 'numero_rata')->map(fn ($id) => (int) $id);
        foreach ($righe->groupBy('rata_id') as $vecchia => $gruppo) {
            if (($nuova = $rataNuova[$numeroDi[$vecchia]] ?? null) !== null) {
                DB::table('righe_scritture')->whereIn('id', $gruppo->pluck('id'))->update(['rata_id' => $nuova]);
            }
        }

        $quoteNuove = DB::table('rate_quote')->whereIn('rata_id', $rataNuova->values())->get(['id', 'rata_id', 'anagrafica_id', 'immobile_id', 'tipo', 'importo'])
            ->groupBy(fn ($q) => $q->rata_id . '|' . $q->anagrafica_id . '|' . (int) $q->immobile_id);
        $persi = 0;
        foreach ($legami as $legame) {
            $rata = $rataNuova[$numeroDi[$legame->rata_id]] ?? null;
            $candidate = $rata === null ? collect() : ($quoteNuove[$rata . '|' . $legame->anagrafica_id . '|' . (int) $legame->immobile_id] ?? collect());
            // Il generatore scrive una quota sola per persona e unità in ogni rata; se un giorno fossero di più, si sceglie quella
            // dello stesso tipo e dello stesso segno, e altrimenti non si tira a indovinare.
            if ($candidate->count() > 1) {
                $candidate = $candidate->filter(fn ($q) => $q->tipo === $legame->tipo && ((int) $q->importo < 0) === ((int) $legame->importo < 0))->values();
            }
            if ($candidate->count() !== 1) {
                $persi++;
                continue;
            }
            DB::table('quota_scrittura')->insert([
                'rate_quota_id'          => $candidate->first()->id,
                'scrittura_contabile_id' => $legame->scrittura_contabile_id,
                'importo_pagato'         => $legame->importo_pagato,
                'data_pagamento'         => $legame->data_pagamento,
                'created_at'             => $legame->created_at,
                'updated_at'             => $legame->updated_at,
            ]);
        }
        if ($persi > 0) {
            Log::info("Ricalcolo del piano {$piano->id}: {$persi} legami fra quote e scritture senza una quota equivalente (la persona non è più nella rata).");
        }

        return $esito;
    }
}
