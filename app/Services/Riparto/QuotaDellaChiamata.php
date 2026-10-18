<?php

namespace App\Services\Riparto;

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\RigaRiparto;
use Illuminate\Support\Facades\DB;

/**
 * La quota di una chiamata del piano sul budget di una voce, e quanto del già versato il piano ne toglie.
 *
 * Un capitolo può essere finanziato da più chiamate indipendenti (un acconto e un saldo): la copertura storica del già versato è dell'intera voce, e
 * `CalcoloQuoteService::nettingGiaVersato` ne toglie a ogni chiamata solo la frazione che quella chiamata rappresenta. Questa classe rifà quel conto da fuori il
 * motore, sui dati che il piano ha già scritto, per chi deve dire che cosa il piano ha tolto o toglierebbe senza ricalcolarlo: la frase del conguaglio di un
 * passaggio (`ConguaglioPassaggio`) e l'atteso del badge «Disallineato» (`PianoRateQuoteService`). Il motore non cambia: è una seconda fonte dichiarata, e un
 * test confronta le due.
 */
final class QuotaDellaChiamata
{
    /**
     * La quota di questa chiamata sul budget della voce: 1 se il piano chiede l'intera voce, altrimenti la frazione che ne chiede. Il piano chiede meno del budget
     * quando forza l'importo del capitolo (`piano_rate_capitoli.importo`) sotto quello del conto: la quota è il distribuito sul conto diviso il nominale, che il
     * fattore di copertura (la parte che resta scoperta) lascia sul rapporto fra l'importo forzato e quello del conto. Non c'è un importo forzato sul conto: la quota
     * è 1. L'importo forzato sta sul capitolo e si divide fra i sottoconti: vale 1 solo se è la somma dei sottoconti, altrimenti non si può dire.
     *
     * @return float|null null se la frazione non si può dire
     */
    public static function perConto(int $pianoRateId, int $contoId): ?float
    {
        $conto = Conto::query()->whereKey($contoId)->first(['id', 'importo', 'parent_id']);
        if ($conto === null) {
            return null;
        }
        $nominale = abs((int) $conto->importo);
        $forzato = DB::table('piano_rate_capitoli')->where('piano_rate_id', $pianoRateId)->where('conto_id', $contoId)->value('importo');
        if ($forzato === null && $conto->parent_id !== null) {
            $dalCapitolo = DB::table('piano_rate_capitoli')->where('piano_rate_id', $pianoRateId)->where('conto_id', $conto->parent_id)->value('importo');
            if ($dalCapitolo !== null) {
                return abs((int) $dalCapitolo) === abs((int) Conto::query()->where('parent_id', $conto->parent_id)->sum('importo')) ? 1.0 : null;
            }
        }
        if ($forzato === null || $nominale === 0 || abs((int) $forzato) >= $nominale) {
            return 1.0;
        }
        $distribuito = abs((int) DB::table('righe_riparto')->where('piano_rate_id', $pianoRateId)->where('conto_id', $contoId)->where('tipo', RigaRiparto::TIPO_RIPARTO)->sum('importo'));
        $totaleNominale = abs((int) $forzato) > 0 ? (int) round($nominale * $distribuito / abs((int) $forzato)) : 0;

        return $distribuito > 0 && $totaleNominale > 0 ? min(1.0, $distribuito / $totaleNominale) : null;
    }

    /**
     * Quanto di un già versato (di una persona, o dell'unità) toglie il piano con questa quota: il pavimento, mai un centesimo in più di quello versato. Il
     * motore arrotonda per difetto perché un acconto e un saldo, indipendenti nel tempo, non possono ridistribuire un resto come fa una chiamata sola.
     */
    public static function applica(int $versato, float $quota): int
    {
        return $quota >= 1.0 ? $versato : (int) floor($versato * $quota);
    }

    /**
     * Il già versato che il piano toglie dalle quote di una voce: la somma, su ogni unità e per ogni parte (di ciascuna persona, e dell'unità), di quello che il
     * motore ne applica (`applica`). Un'unità la cui somma non è positiva non ha niente da togliere, come per il motore. Dove la quota non si può dire non si
     * inventa una frazione: il piano toglie tutto il già versato.
     */
    public static function giaVersatoApplicato(int $pianoRateId, int $contoId): int
    {
        $quota = self::perConto($pianoRateId, $contoId) ?? 1.0;
        $totale = 0;

        foreach (ContributoVersato::perImmobileESoggetto(Conto::class, $contoId) as $parti) {
            if (array_sum($parti) <= 0) {
                continue;
            }
            foreach ($parti as $versato) {
                if ($versato > 0) {
                    $totale += self::applica((int) $versato, $quota);
                }
            }
        }

        return $totale;
    }
}
