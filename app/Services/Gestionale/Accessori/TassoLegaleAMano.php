<?php

namespace App\Services\Gestionale\Accessori;

use App\Models\User;
use App\Settings\TassoLegaleSettings;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

/**
 * Il tasso legale di un anno che `config/fiscale.php` non ha ancora, scritto a mano dallo studio. Il tasso di ogni anno lo fissa un decreto del Ministero
 * dell'economia pubblicato nella Gazzetta Ufficiale entro il 15 dicembre dell'anno prima (art. 1284 c.c.), ed entra nel programma con un aggiornamento;
 * intanto lo studio lo può scrivere da sé, con la fonte. Uno per anno, per tutti i condomini: è lo stesso tasso dappertutto, non una regola del
 * condominio. Si conserva nelle impostazioni del programma ({@see TassoLegaleSettings}), con chi l'ha scritto e il giorno.
 *
 * Vale solo per un anno dopo l'ultimo del config: per un anno che il config ha vale il config, anche con una cifra diversa
 * ({@see RegoleDelRecupero::tassoAl}). Si scrive per gli anni dal primo dopo il config all'anno prossimo; scritto di nuovo, un anno si corregge.
 */
final class TassoLegaleAMano
{
    /**
     * I tassi scritti a mano, per anno, dal più vecchio.
     *
     * @return array<int, array{anno: int, tasso_e4: int, fonte: string, scritto_da: ?int, scritto_da_nome: ?string, scritto_il: string}>
     */
    public static function perAnno(): array
    {
        try {
            $righe = app(TassoLegaleSettings::class)->scritti_a_mano;
        } catch (MissingSettings) {
            // Le impostazioni del tasso non ci sono ancora (l'aggiornamento del database non è finito): nessun tasso scritto a mano.
            return [];
        }
        $esito = [];
        foreach ($righe as $r) {
            $anno = (int) ($r['anno'] ?? 0);
            if ($anno > 0 && isset($r['tasso_e4'])) {
                $esito[$anno] = ['anno' => $anno, 'tasso_e4' => (int) $r['tasso_e4'], 'fonte' => (string) ($r['fonte'] ?? ''),
                    'scritto_da' => isset($r['scritto_da']) ? (int) $r['scritto_da'] : null, 'scritto_da_nome' => $r['scritto_da_nome'] ?? null,
                    'scritto_il' => (string) ($r['scritto_il'] ?? '')];
            }
        }
        ksort($esito);

        return $esito;
    }

    /** Scrive il tasso di un anno, al posto di quello di prima se c'era: true se l'ha corretto. Chi chiama ha già controllato l'anno. */
    public static function scrivi(int $anno, int $tassoE4, string $fonte, ?User $chi, string $oggi): bool
    {
        $tutti = self::perAnno();
        $corretto = isset($tutti[$anno]);
        $tutti[$anno] = ['anno' => $anno, 'tasso_e4' => $tassoE4, 'fonte' => trim($fonte), 'scritto_da' => $chi?->id === null ? null : (int) $chi->id,
            'scritto_da_nome' => $chi?->name, 'scritto_il' => $oggi];
        self::salva($tutti);

        return $corretto;
    }

    /** Toglie il tasso di un anno: false se non c'era. */
    public static function togli(int $anno): bool
    {
        $tutti = self::perAnno();
        if (! isset($tutti[$anno])) {
            return false;
        }
        unset($tutti[$anno]);
        self::salva($tutti);

        return true;
    }

    /** Gli anni che si possono scrivere a mano il giorno dato: dal primo dopo l'ultimo del config all'anno prossimo. @return list<int> */
    public static function anniDaScrivere(string $oggi): array
    {
        $prossimo = (int) substr($oggi, 0, 4) + 1;
        $primo = self::ultimoAnnoDelProgramma() + 1;

        return $primo > $prossimo ? [] : range($primo, $prossimo);
    }

    /** Perché un anno non si scrive a mano il giorno dato; null se si scrive. */
    public static function percheNo(int $anno, string $oggi): ?string
    {
        $delProgramma = RegoleDelRecupero::anniDelProgramma();
        if (isset($delProgramma[$anno])) {
            return 'Il tasso legale del ' . $anno . ' è già nel programma: ' . RegoleDelRecupero::percentuale($delProgramma[$anno]) . '.';
        }
        if ($anno <= self::ultimoAnnoDelProgramma()) {
            return 'Per il ' . $anno . ' il programma non ha il tasso legale: scrivi un tasso con la sua fonte nelle regole del condominio.';
        }
        if ($anno > (int) substr($oggi, 0, 4) + 1) {
            return 'Il tasso legale del ' . $anno . ' non si conosce ancora: il decreto esce entro il 15 dicembre ' . ($anno - 1) . '.';
        }

        return null;
    }

    /** Come la tabella del tasso legale dice un anno che manca: fino al 15 dicembre dell'anno prima il decreto può non esserci ancora; dopo, manca al programma. */
    public static function fraseAnnoMancante(int $anno, string $oggi): string
    {
        return $oggi <= ($anno - 1) . '-12-15' ? 'non ancora: il decreto esce entro il 15 dicembre ' . ($anno - 1) : 'non ancora nel programma';
    }

    /** «Il tasso legale del 2027, 2,25 %, è scritto a mano: da Mario Rossi il 16/12/2026, con la fonte «D.M. …».» Per la proposta degli interessi. */
    public static function frase(array $riga): string
    {
        $chi = filled($riga['scritto_da_nome'] ?? null) ? 'da ' . $riga['scritto_da_nome'] . ' ' : '';

        return 'Il tasso legale del ' . $riga['anno'] . ', ' . RegoleDelRecupero::percentuale((int) $riga['tasso_e4']) . ', è scritto a mano: ' . $chi
            . 'il ' . self::giorno((string) $riga['scritto_il']) . ', con la fonte «' . $riga['fonte'] . '».';
    }

    /**
     * «Per il 2027 lo studio aveva scritto a mano 2,25 % (Mario Rossi, il 16/12/2026): il programma ha 2,50 %, e vale quello del programma.» Null se le
     * due cifre sono uguali.
     */
    public static function fraseDiversa(array $riga, int $delProgrammaE4): ?string
    {
        if ((int) $riga['tasso_e4'] === $delProgrammaE4) {
            return null;
        }
        $chi = filled($riga['scritto_da_nome'] ?? null) ? $riga['scritto_da_nome'] . ', ' : '';

        return 'Per il ' . $riga['anno'] . ' lo studio aveva scritto a mano ' . RegoleDelRecupero::percentuale((int) $riga['tasso_e4']) . ' (' . $chi . 'il '
            . self::giorno((string) $riga['scritto_il']) . '): il programma ha ' . RegoleDelRecupero::percentuale($delProgrammaE4) . ', e vale quello del programma.';
    }

    /** L'ultimo anno del tasso legale nel config; 0 se il config non ne ha. */
    public static function ultimoAnnoDelProgramma(): int
    {
        return (int) (array_key_last(RegoleDelRecupero::anniDelProgramma()) ?? 0);
    }

    private static function salva(array $perAnno): void
    {
        ksort($perAnno);
        $impostazioni = app(TassoLegaleSettings::class);
        $impostazioni->scritti_a_mano = array_values($perAnno);
        $impostazioni->save();
    }

    private static function giorno(string $data): string
    {
        return substr($data, 8, 2) . '/' . substr($data, 5, 2) . '/' . substr($data, 0, 4);
    }
}
