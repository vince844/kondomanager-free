<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Accessori;

use App\Models\Gestionale\RegolaRecupero;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Le regole del recupero dei crediti di un condominio lette a un giorno: il tasso degli interessi di mora di ogni giorno, i giorni da cui decorrono, la
 * tolleranza prima di sollecitare, il termine di serie delle lettere. Una regola vale dal suo giorno al giorno prima della regola dopo; senza nessuna
 * regola vale la legge: il tasso legale dell'anno (artt. 1224, 1282 e 1284 c.c.), dal giorno dopo la scadenza.
 *
 * Il tasso legale è quello della tabella di `config/fiscale.php`: una riga vale dal 1° gennaio del suo anno fino alla riga dopo, e l'ultima fino alla
 * fine del suo anno. Per un anno dopo l'ultimo della tabella vale il tasso che lo studio ha scritto a mano ({@see TassoLegaleAMano}); per un anno della
 * tabella vale la tabella, anche se lo studio aveva scritto un'altra cifra. Un anno senza nessuno dei due (prima del 2019, o non ancora scritto) non ha un
 * tasso: chi calcola si ferma e lo dice.
 */
final class RegoleDelRecupero
{
    /** Un tasso scritto che viene da una delibera: un avviso accanto, mai un blocco. */
    public const AVVISO_DELIBERA = 'Un tasso diverso dal legale vale se lo prevede il regolamento contrattuale o un voto unanime; una delibera a maggioranza può '
        . 'non bastare: controlla la fonte.';

    /** Un tasso scritto che viene dal regolamento: vale se il regolamento è contrattuale. Un avviso accanto, mai un blocco. */
    public const AVVISO_REGOLAMENTO = 'Un tasso diverso dal legale vale se lo prevede il regolamento contrattuale o un voto unanime: controlla che il regolamento '
        . 'sia contrattuale.';

    /** In testa alla proposta degli interessi. */
    public const FRASE_DOMANDA_GIUDIZIALE = 'Dopo la domanda giudiziale il tasso è un altro (art. 1284 c. 4 c.c.): gli interessi di una causa li quantifica il legale.';

    /** La fonte di un tratto con il tasso legale scritto a mano dallo studio, per un anno che il programma non ha ancora. */
    public const FONTE_LEGALE_A_MANO = 'tasso legale scritto a mano';

    /** Che cosa vale senza nessuna regola. */
    public const FRASE_SENZA_REGOLE = 'Senza una regola vale la legge: il tasso legale dell\'anno (artt. 1224, 1282 e 1284 c.c.), dal giorno dopo la scadenza.';

    /** @return Collection<int, RegolaRecupero> le regole del condominio, dalla più vecchia */
    public function delCondominio(int $condominioId): Collection
    {
        return RegolaRecupero::where('condominio_id', $condominioId)->orderBy('dal')->orderBy('id')->get();
    }

    /** L'avviso di un tasso scritto per la sua fonte: la delibera e il regolamento ne hanno uno; null per le altre. */
    public static function avvisoDellaFonte(?string $fonte): ?string
    {
        return match ($fonte) {
            'delibera' => self::AVVISO_DELIBERA,
            'regolamento' => self::AVVISO_REGOLAMENTO,
            default => null,
        };
    }

    /** La regola che vale il giorno dato; null se nessuna vale ancora (vale la legge). */
    public static function alGiorno(Collection $regole, string $giorno): ?RegolaRecupero
    {
        return $regole->filter(fn (RegolaRecupero $r) => $r->dal->format('Y-m-d') <= $giorno)->last();
    }

    /**
     * Il tasso di ogni giorno, per il calcolo: quello della regola del giorno, o il legale dell'anno (della tabella, o scritto a mano dallo studio per un
     * anno dopo la tabella); una frase quando manca. Con la regola che vale quel giorno (`regola_id`, null senza regole): il tratto la conserva, e la
     * pagina delle regole sa quali regole gli interessi registrati hanno usato.
     *
     * @param array<int, array{tasso_e4: int}>|null $aMano i tassi scritti a mano, per anno; senza, quelli delle impostazioni, letti la prima volta che
     *     servono
     * @return \Closure(string): (array{tasso_e4: int, etichetta: string, fonte: string, regola_id: ?int}|string)
     */
    public static function tassoAl(Collection $regole, ?array $aMano = null): \Closure
    {
        $ultimo = null;

        return function (string $giorno) use ($regole, &$aMano, &$ultimo) {
            $r = self::alGiorno($regole, $giorno);
            $id = $r?->id === null ? null : (int) $r->id;
            if ($r !== null && $r->tasso_tipo === RegolaRecupero::TASSO_SCRITTO && $r->tassoE4() !== null) {
                return ['tasso_e4' => $r->tassoE4(), 'etichetta' => self::percentuale($r->tassoE4()), 'fonte' => self::fonteScritta($r), 'regola_id' => $id];
            }
            $legale = self::tassoLegale($giorno);
            if ($legale !== null) {
                return ['tasso_e4' => $legale, 'etichetta' => self::percentuale($legale), 'fonte' => 'tasso legale', 'regola_id' => $id];
            }
            $anno = (int) substr($giorno, 0, 4);
            $ultimo ??= TassoLegaleAMano::ultimoAnnoDelProgramma();
            if ($anno > $ultimo) {
                $aMano ??= TassoLegaleAMano::perAnno();
                if (isset($aMano[$anno]['tasso_e4'])) {
                    $e4 = (int) $aMano[$anno]['tasso_e4'];

                    return ['tasso_e4' => $e4, 'etichetta' => self::percentuale($e4), 'fonte' => self::FONTE_LEGALE_A_MANO, 'regola_id' => $id];
                }
            }

            return self::fraseTassoMancante($anno);
        };
    }

    /** I giorni in cui comincia una regola: il tasso può cambiare. */
    public static function cambi(Collection $regole): array
    {
        return $regole->map(fn (RegolaRecupero $r) => $r->dal->format('Y-m-d'))->values()->all();
    }

    /** Il primo giorno degli interessi di una rata: il giorno dopo la scadenza, più i giorni della regola che vale quel giorno. */
    public static function primoGiorno(Collection $regole, string $scadenza): string
    {
        $dopo = CarbonImmutable::parse($scadenza, 'UTC')->addDay();
        $giorni = (int) (self::alGiorno($regole, $dopo->format('Y-m-d'))?->giorni_decorrenza ?? 0);

        return $dopo->addDays($giorni)->format('Y-m-d');
    }

    /** I giorni di tolleranza prima di sollecitare il giorno dato; null se la regola non li dice. */
    public static function tolleranza(Collection $regole, string $giorno): ?int
    {
        $g = self::alGiorno($regole, $giorno)?->giorni_tolleranza;

        return $g === null ? null : (int) $g;
    }

    /** Il termine di serie delle lettere il giorno dato; null se la regola non lo dice. */
    public static function termineDelleLettere(Collection $regole, string $giorno): ?int
    {
        $g = self::alGiorno($regole, $giorno)?->termine_lettere_giorni;

        return $g === null ? null : (int) $g;
    }

    /** Il tasso legale di ogni anno della tabella di `config/fiscale.php`, in decimillesimi di punto, dal più vecchio. @return array<int, int> */
    public static function anniDelProgramma(): array
    {
        $anni = [];
        foreach (collect(config('fiscale.interessi_legali', []))->sortBy('dal') as $riga) {
            $anni[(int) substr((string) $riga['dal'], 0, 4)] = (int) round(((float) $riga['tasso']) * 10000);
        }

        return $anni;
    }

    /** Il tasso legale del giorno nella tabella di `config/fiscale.php`, in decimillesimi di punto (1,60 % è 16.000); null se l'anno non è nella tabella. */
    public static function tassoLegale(string $giorno): ?int
    {
        $righe = collect(config('fiscale.interessi_legali', []))->sortBy('dal')->values();
        foreach ($righe as $i => $riga) {
            $fino = isset($righe[$i + 1]) ? CarbonImmutable::parse($righe[$i + 1]['dal'])->subDay()->format('Y-m-d') : substr((string) $riga['dal'], 0, 4) . '-12-31';
            if ($riga['dal'] <= $giorno && $giorno <= $fino) {
                return (int) round(((float) $riga['tasso']) * 10000);
            }
        }

        return null;
    }

    /** Perché un anno non ha il tasso legale: prima della tabella, o non ancora scritto. */
    public static function fraseTassoMancante(int $anno): string
    {
        $primo = (int) substr((string) collect(config('fiscale.interessi_legali', []))->min('dal'), 0, 4);

        return $anno < $primo
            ? 'Manca il tasso legale del ' . $anno . ': scrivi un tasso con la sua fonte nelle regole del recupero.'
            : 'Il tasso legale del ' . $anno . ' non è ancora nel programma: puoi proporre gli interessi fino al 31/12/' . ($anno - 1) . '. Il tasso di un '
                . 'anno nuovo entra nel programma con un aggiornamento; intanto chi gestisce le impostazioni generali lo può scrivere a mano, con il decreto e la '
                . 'Gazzetta Ufficiale, nella tabella del tasso legale della pagina «Recupero dei crediti».';
    }

    /**
     * Il tasso di un giorno detto per una lettera (il segnaposto `[tasso]`): «1,60 % annuo, tasso legale», «3,00 % annuo, art. 12 del regolamento».
     * Il tasso legale scritto a mano dallo studio è il tasso legale anche lui: a chi legge la lettera non serve sapere chi l'ha scritto nel programma.
     * Null se il tasso legale dell'anno non c'è.
     */
    public static function tassoPerLaLettera(Collection $regole, string $giorno): ?string
    {
        $t = self::tassoAl($regole)($giorno);

        return is_string($t) ? null : $t['etichetta'] . ' annuo, ' . ($t['fonte'] === self::FONTE_LEGALE_A_MANO ? 'tasso legale' : $t['fonte']);
    }

    /** «1,60 %», «0,05 %», «3,125 %»: due decimali almeno, quattro al più; lo spazio prima del «%» non va a capo. */
    public static function percentuale(int $tassoE4): string
    {
        $intero = intdiv($tassoE4, 10000);
        $decimali = rtrim(str_pad((string) ($tassoE4 % 10000), 4, '0', STR_PAD_LEFT), '0');

        return number_format($intero, 0, ',', '.') . ',' . str_pad($decimali, 2, '0') . "\u{00A0}%";
    }

    /**
     * I tassi degli interessi registrati detti nella loro causale, in ordine di giorno: un tasso scritto con il suo riferimento, «3,00 % annuo, art. 12 del
     * regolamento contrattuale»; i giorni al tasso legale (di uno o più anni, della tabella o scritto a mano) «tasso legale». Più tassi uno dopo l'altro:
     * «tasso legale; poi 3,00 % annuo, …». Null se nessun giorno è a un tasso scritto: la causale resta senza.
     *
     * @param list<array{dal: string, tasso: string, fonte: string, regola_id: ?int}> $tratti i tratti delle righe, anche di più rate
     * @param Collection<int, RegolaRecupero> $regole le regole del condominio
     */
    public static function tassiNellaCausale(array $tratti, Collection $regole): ?string
    {
        $perId = $regole->keyBy('id');
        $pezzi = [];
        $scritto = false;
        foreach (collect($tratti)->sortBy('dal')->values() as $t) {
            $regola = $perId[$t['regola_id'] ?? 0] ?? null;
            $diQuesta = $regola !== null && $regola->tasso_tipo === RegolaRecupero::TASSO_SCRITTO;
            $scritto = $scritto || $diQuesta;
            $pezzo = $diQuesta ? $t['tasso'] . ' annuo, ' . $t['fonte'] : 'tasso legale';
            if (end($pezzi) !== $pezzo) {
                $pezzi[] = $pezzo;
            }
        }

        return $scritto ? implode('; poi ', $pezzi) : null;
    }

    /** Da dove viene un tasso scritto, per chi legge: il riferimento, o il nome della fonte. */
    public static function fonteScritta(RegolaRecupero $r): string
    {
        $riferimento = trim((string) $r->riferimento);

        return $riferimento !== '' ? $riferimento : (RegolaRecupero::FONTI[$r->fonte] ?? 'tasso scritto');
    }

    /**
     * Le regole per la pagina e per la proposta, dalla più recente: il tasso detto per esteso, la fonte, i giorni, l'avviso della delibera.
     *
     * @return list<array<string, mixed>>
     */
    public static function perLaPagina(Collection $regole): array
    {
        return $regole->sortByDesc(fn (RegolaRecupero $r) => [$r->dal->format('Y-m-d'), $r->id])->values()->map(fn (RegolaRecupero $r) => [
            'id' => (int) $r->id,
            'dal' => $r->dal->format('Y-m-d'),
            'tasso_tipo' => $r->tasso_tipo,
            'tasso_annuo' => $r->tasso_tipo === RegolaRecupero::TASSO_SCRITTO ? self::percentuale((int) $r->tassoE4()) : null,
            'tasso' => $r->tasso_tipo === RegolaRecupero::TASSO_SCRITTO ? self::percentuale((int) $r->tassoE4()) . ' annuo, ' . self::fonteScritta($r) : 'Tasso legale dell\'anno',
            'fonte' => $r->fonte,
            'riferimento' => $r->riferimento,
            'giorni_decorrenza' => (int) $r->giorni_decorrenza,
            'giorni_tolleranza' => $r->giorni_tolleranza,
            'termine_lettere_giorni' => $r->termine_lettere_giorni,
            'avviso' => $r->tasso_tipo === RegolaRecupero::TASSO_SCRITTO ? self::avvisoDellaFonte($r->fonte) : null,
            'creata_da' => $r->creataDa?->name,
        ])->all();
    }
}
