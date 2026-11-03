<?php

namespace App\Support;

use App\Helpers\MoneyHelper;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Il conguaglio di una quota già emessa fra chi esce e chi entra (D9 del progetto
 * `docs/subentro_e_competenza_temporale.md`): una funzione pura, senza modelli, chiamata **una volta
 * sola** — dal pannello «Cosa cambierà» e da `RegistraSubentroAction`, che scrive esattamente ciò che
 * il pannello ha mostrato (S5, «anteprima = scrittura»).
 *
 * La regola è quella della legge verso il condominio, non degli accordi fra le parti (che valgono in
 * regresso, «salvo diverso accordo», Cass. 11199/2021 — e per quelli c'è la rinuncia motivata):
 *
 * - **ordinario**: la quota si divide in proporzione ai giorni di competenza prima e dopo la
 *   decorrenza; con più tratti (riscaldamento zona E) contano i giorni dei tratti, non le date estreme;
 * - **straordinario**: la competenza è il giorno della delibera (`PeriodoCompetenza::puntuale`), quindi
 *   la funzione dà tutto all'uscente se la delibera è prima della decorrenza e tutto all'entrante se è
 *   il giorno stesso o dopo (Cass. 24654/2010) — senza un ramo a parte: è la stessa aritmetica su un
 *   periodo di un giorno.
 *
 * Gli estremi non passano dall'arrotondamento (D8, stessa disciplina del motore): decorrenza dopo la
 * fine della competenza → tutto all'uscente; decorrenza il primo giorno o prima → tutto all'entrante.
 * In mezzo, `MoneyHelper::ripartisciPerQuote()` con i giorni come pesi: resti maggiori, e a pari resto
 * il centesimo va all'**uscente**, prima chiave inserita — la stessa convenzione dell'invariante 6.
 * Il segno si conserva: una quota a credito dà un conguaglio a segno rovesciato, e i testi lo dicono.
 */
final class ProRataTemporis
{
    /**
     * La competenza passata è quella che la quota copre davvero: per una riga del riparto congelata con il suo
     * tratto (migrazione 11) il chiamante passa `competenza ∩ tratto` (`InsiemePeriodi::intersezione`), e qui non
     * c'è nessuna ipotesi su «quali» giorni la quota copra.
     *
     * @return array{uscente: int, entrante: int, giorni_uscente: int, giorni_entrante: int, giorni_periodo: int}
     */
    public static function dividi(int $importoCents, InsiemePeriodi|PeriodoCompetenza $competenza, CarbonInterface|string $decorrenza): array
    {
        $insieme = $competenza instanceof PeriodoCompetenza ? InsiemePeriodi::uno($competenza) : $competenza;
        $giorno = CarbonImmutable::parse($decorrenza instanceof CarbonInterface ? $decorrenza->format('Y-m-d') : substr((string) $decorrenza, 0, 10), 'UTC')->startOfDay();

        $giorniPeriodo = $insieme->giorni();
        $giorniEntrante = $giorno->gt($insieme->al())
            ? 0
            : $insieme->giorniDiSovrapposizione(new PeriodoCompetenza($giorno->lte($insieme->dal()) ? $insieme->dal() : $giorno, $insieme->al()));
        $giorniUscente = $giorniPeriodo - $giorniEntrante;

        $esito = match (true) {
            $giorniEntrante === 0 => ['uscente' => $importoCents, 'entrante' => 0],
            $giorniUscente === 0  => ['uscente' => 0, 'entrante' => $importoCents],
            default               => MoneyHelper::ripartisciPerQuote($importoCents, ['uscente' => $giorniUscente, 'entrante' => $giorniEntrante]),
        };

        return [
            'uscente'         => (int) $esito['uscente'],
            'entrante'        => (int) $esito['entrante'],
            'giorni_uscente'  => $giorniUscente,
            'giorni_entrante' => $giorniEntrante,
            'giorni_periodo'  => $giorniPeriodo,
        ];
    }
}
