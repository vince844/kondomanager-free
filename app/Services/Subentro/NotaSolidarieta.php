<?php

namespace App\Services\Subentro;

use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * La nota **calcolata** dell'art. 63 co. 4 disp. att. c.c. (1.11.0-beta.31, B2 S7, §7 del progetto).
 *
 * Chi subentra risponde in solido con il cedente per i contributi dell'anno in corso e di quello precedente.
 * Il programma **non intesta** nulla a chi entra e **non sollecita**: le quote emesse a chi è uscito restano
 * sue (invariante 20, la morosità resta all'uscente). Ma chi legge la posizione di chi è entrato — o l'elenco
 * delle rate aperte di un'unità — deve sapere che quel debito esiste e che la legge glielo fa rispondere: la
 * nota lo dice, con il residuo ancora aperto a nome di chi è uscito su quell'unità, finché il passaggio è
 * nella finestra della solidarietà (decorrenza dall'inizio dell'esercizio precedente a quello aperto).
 *
 * Solo le vendite: nella locazione e nell'usufrutto la solidarietà dell'art. 63 co. 4 non c'è.
 */
final class NotaSolidarieta
{
    public function __construct(private readonly FrasiObbligati $frasi = new FrasiObbligati())
    {
    }

    /**
     * Le note per una persona (come entrante) e/o per un'unità, nel condominio.
     *
     * @return list<array{subentro_id:int, immobile:string, uscente:string, entrante:string, decorrenza:string, esercizi:string, residuo_uscente_cents:int, residuo_uscente_formattato:string, testo:string}>
     */
    public function per(Condominio $condominio, ?Anagrafica $entrante = null, ?Immobile $immobile = null): array
    {
        if ($entrante === null && $immobile === null) {
            return [];
        }

        $finestraDal = $this->inizioFinestra($condominio);

        $query = Subentro::with(['uscente', 'entrante', 'immobile'])
            ->where('condominio_id', $condominio->id)
            ->where('tipo_passaggio', 'vendita')
            ->whereNotNull('anagrafica_uscente_id')
            ->whereNotNull('anagrafica_entrante_id');
        if ($finestraDal !== null) {
            $query->whereDate('decorrenza', '>=', $finestraDal->toDateString());
        }
        if ($entrante !== null) {
            $query->where('anagrafica_entrante_id', $entrante->id);
        }
        if ($immobile !== null) {
            $query->where('immobile_id', $immobile->id);
        }

        return $query->orderByDesc('decorrenza')->get()->map(function (Subentro $s) use ($condominio) {
            $decorrenza = CarbonImmutable::parse($s->decorrenza->toDateString());
            [$corrente, $precedente] = $this->frasi->esercizi($condominio, $decorrenza);
            $residuo = $this->residuoAperto((int) $s->anagrafica_uscente_id, (int) $s->immobile_id);
            $unita = $s->immobile?->nome . ($s->immobile?->interno ? " (Int. {$s->immobile->interno})" : '');

            $testo = sprintf(
                '%s risponde in solido con %s per i contributi di %s relativi agli esercizi %s e %s (art. 63 co. 4 disp. att. c.c.). Nessuna quota è intestata a %s per quel periodo: %s. Chi paga in forza della solidarietà ha regresso verso il venditore, salvo diverso accordo (Cass. 11199/2021).',
                $s->entrante?->nome ?? 'Chi è entrato', $s->uscente?->nome ?? 'chi è uscito', $unita, $corrente, $precedente,
                $s->entrante?->nome ?? 'chi è entrato',
                $residuo > 0
                    ? sprintf('a nome di %s risultano oggi %s non pagati su questa unità', $s->uscente?->nome ?? 'chi è uscito', MoneyHelper::format($residuo))
                    : sprintf('a nome di %s non risulta oggi nulla di non pagato su questa unità', $s->uscente?->nome ?? 'chi è uscito'),
            );

            return [
                'subentro_id'   => (int) $s->id,
                'immobile'      => $unita,
                'uscente'       => $s->uscente?->nome,
                'entrante'      => $s->entrante?->nome,
                'decorrenza'    => $decorrenza->toDateString(),
                'esercizi'      => "{$corrente} e {$precedente}",
                'residuo_uscente_cents' => $residuo,
                'residuo_uscente_formattato' => MoneyHelper::format($residuo),
                'testo'         => $testo,
            ];
        })->values()->all();
    }

    /**
     * L'inizio della finestra di solidarietà: il primo giorno dell'esercizio **precedente** a quello aperto.
     * Senza esercizi, o senza un precedente, la finestra non taglia (si guarda tutto).
     */
    private function inizioFinestra(Condominio $condominio): ?CarbonImmutable
    {
        $aperto = Esercizio::where('condominio_id', $condominio->id)->where('stato', 'aperto')->orderBy('data_inizio')->first();
        if ($aperto === null) {
            return null;
        }
        $precedente = Esercizio::where('condominio_id', $condominio->id)
            ->whereDate('data_fine', '<', $aperto->data_inizio->toDateString())
            ->orderByDesc('data_fine')->first();

        return CarbonImmutable::parse(($precedente ?? $aperto)->data_inizio->toDateString());
    }

    /** Il residuo non pagato a nome di chi è uscito sull'unità: quote emesse con importo > pagato. */
    private function residuoAperto(int $anagraficaId, int $immobileId): int
    {
        return (int) DB::table('rate_quote')
            ->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
            ->where('rate_quote.anagrafica_id', $anagraficaId)
            ->where('rate_quote.immobile_id', $immobileId)
            ->where('rate.stato', 'emessa')
            ->where('rate_quote.stato', '!=', 'annullata')
            ->whereRaw('rate_quote.importo > rate_quote.importo_pagato')
            ->where('rate_quote.importo', '>', 0)
            ->selectRaw('COALESCE(SUM(rate_quote.importo - rate_quote.importo_pagato), 0) as residuo')
            ->value('residuo');
    }
}
