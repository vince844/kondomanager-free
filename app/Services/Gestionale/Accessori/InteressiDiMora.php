<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Accessori;

/**
 * Il calcolo degli interessi di mora di una persona: puro, senza database. Riceve le chiavi del suo giornale (una rata su un'unità, gli anticipi), i
 * movimenti di ognuna con il giorno, il tasso di ogni giorno e il giorno della proposta; restituisce, per ogni rata della base, i tratti e gli
 * interessi arrotondati al centesimo.
 *
 * - **I giorni.** Dal primo giorno degli interessi della rata (il giorno dopo la scadenza, più i giorni che il regolamento concede) al giorno della
 *   proposta compreso. Un movimento di un giorno vale dal giorno dopo: il versamento del 30/04 conta il 30/04 sul capitale di prima. Una rata può avere
 *   anche l'ultimo giorno (`fine`, il giorno prima di un accordo di rientro che la copre, decisione 438): dal giorno dopo è fuori dalla base, e non
 *   prende più né interessi né crediti. Di una rata coperta da un accordo solo in parte, con gli interessi che continuano sulla parte fuori (decisione
 *   450), dal giorno dell'accordo resta nella base la sola parte fuori (`protetto`, la parte nell'accordo giorno per giorno).
 * - **Il capitale di ogni giorno** è quello che resta della rata alla fine del giorno prima, meno i crediti della persona che la coprono. I crediti
 *   (quello che una chiave ha in più del suo debito: un anticipo, una rata versata due volte) coprono le rate della base più vecchie prima, nell'ordine
 *   delle scadenze; mai gli accessori, che non sono nella base (art. 1283 c.c.).
 * - **I tratti** si spezzano a ogni movimento che cambia il capitale, a ogni anno (il tasso e il divisore dell'anno: 365, 366 nel bisestile) e a ogni
 *   regola del recupero nuova. Ogni tratto dice perché comincia.
 * - **L'arrotondamento**: gli interessi di una rata sono la somma esatta dei suoi tratti, arrotondata al centesimo più vicino (il mezzo va su), rata per
 *   rata: ogni riga si controlla da sola. In centesimi: capitale × tasso × giorni / (1.000.000 × divisore), con il tasso in decimillesimi di punto
 *   (1,60 % = 16.000). La somma si fa con i resti, senza numeri con la virgola.
 * - **Il tasso che manca** (prima della tabella del tasso legale, o un anno non ancora scritto): la rata si ferma tutta, con la frase del perché; mai
 *   interessi a metà.
 */
final class InteressiDiMora
{
    /** Il denominatore comune dei resti: 1.000.000 × 365 × 366. */
    private const COMUNE = 1_000_000 * 365 * 366;

    /**
     * @param array<string, array{inizio: ?string, base: bool, ordine: array, fine?: ?string, protetto?: list<array{dal: string, importo: int}>, inizio_pieno?: ?string}> $chiavi ogni
     *     chiave del giornale della persona: `inizio` il primo giorno degli interessi (le chiavi fuori dalla base non ne hanno), `base` se la chiave prende
     *     interessi, `ordine` la chiave dell'ordine delle scadenze, `fine` (se c'è) l'ultimo giorno in cui la chiave è nella base, `protetto` (se c'è) la
     *     parte che un accordo di rientro copre, a passi in ordine di giorno: dal giorno di un passo il capitale della chiave è il debito meno quella parte
     *     (decisione 450), e il primo passo comincia il tratto «accordo»; `inizio_pieno` (se c'è) il primo giorno senza gli interessi già registrati: una
     *     chiave registrata fino alla data o oltre resta nella base per i suoi crediti (E5 della seconda prova della beta.12)
     * @param list<array{giorno: string, chiave: string, importo: int, causa: string}> $movimenti il debito della chiave sale (più) o scende (meno) alla
     *     fine del giorno; la causa è una di `emissione`, `versamento`, `storno`, `credito`
     * @param \Closure(string): (array{tasso_e4: int, etichetta: string, fonte: string, regola_id?: ?int}|string) $tassoAl il tasso di un giorno, o la frase
     *     del perché manca; con la regola che lo dà, che il tratto conserva
     * @param list<string> $cambi i giorni in cui comincia una regola del recupero
     * @return array<string, array{tratti: list<array<string, mixed>>, interessi: int, fermo: ?string}> per le sole chiavi della base
     */
    public static function calcola(array $chiavi, array $movimenti, \Closure $tassoAl, array $cambi, string $alla): array
    {
        $fine = self::numero($alla);
        // L'ultimo giorno di ogni chiave nella base: il giorno della proposta, o prima se la chiave ha il suo (decisione 438).
        $ultimo = array_map(fn (array $c) => min($fine, ($c['fine'] ?? null) === null ? $fine : self::numero((string) $c['fine'])), $chiavi);
        // Nella base anche la chiave che comincia dopo il suo ultimo giorno (i giorni prima di un accordo già registrati): niente interessi, ma fino al suo
        // ultimo giorno tiene i crediti che la coprivano, come una rata registrata senza accordo. Fuori dalla base quei crediti coprirebbero un'altra rata
        // negli stessi giorni, e la riga registrata li ha già contati (revisione della 1.12.0-beta.11, R7). I giorni dopo l'ultimo li salta il
        // ciclo.
        // E5 della seconda prova della beta.12: lo stesso per la chiave registrata fino alla data o oltre (`inizio` dopo la data, `inizio_pieno` entro la
        // data): niente interessi, ma tiene i crediti che la registrazione le ha contato. Prima restava fuori, e registrando in due volte lo stesso credito
        // copriva due volte gli stessi giorni.
        $registrataOltre = fn (array $c) => ($c['inizio_pieno'] ?? null) !== null && self::numero((string) $c['inizio_pieno']) <= $fine;
        $base = array_filter($chiavi, fn (array $c) => $c['base'] && $c['inizio'] !== null && (self::numero($c['inizio']) <= $fine || $registrataOltre($c)));
        // Le chiavi che contano interessi in questo calcolo: le altre della base tengono solo i crediti, e non spostano né il primo giorno né i punti.
        $conGiorni = array_filter($base, fn (array $c) => self::numero($c['inizio']) <= $fine);
        $esito = [];
        foreach (array_keys(array_filter($chiavi, fn (array $c) => $c['base'])) as $k) {
            $esito[$k] = ['tratti' => [], 'interessi' => 0, 'fermo' => null];
        }
        if ($conGiorni === []) {
            return $esito;
        }
        // L'ordine di copertura dei crediti: le scadenze più vecchie prima.
        uksort($base, fn ($a, $b) => [$chiavi[$a]['ordine'], $a] <=> [$chiavi[$b]['ordine'], $b]);

        // I movimenti per giorno, e i giorni in cui qualcosa può cambiare: il giorno dopo ogni movimento, l'inizio di ogni rata, il primo dell'anno,
        // l'inizio di ogni regola.
        $perGiorno = [];
        foreach ($movimenti as $m) {
            $perGiorno[self::numero($m['giorno'])][] = $m;
        }
        ksort($perGiorno);
        $inizio = min(array_map(fn (array $c) => self::numero($c['inizio']), $conGiorni));
        $punti = [$inizio => true];
        foreach (array_keys($perGiorno) as $g) {
            if ($g + 1 > $inizio && $g + 1 <= $fine) {
                $punti[$g + 1] = true;
            }
        }
        foreach ($base as $k => $c) {
            if (self::numero($c['inizio']) <= $fine) {
                $punti[self::numero($c['inizio'])] = true;
            }
            // Il giorno dopo l'ultimo di una chiave: da lì il credito che teneva passa alle altre.
            if ($ultimo[$k] < $fine) {
                $punti[$ultimo[$k] + 1] = true;
            }
            // Ogni giorno in cui cambia la parte protetta da un accordo (decisione 450).
            foreach ($c['protetto'] ?? [] as $p) {
                $n = self::numero((string) $p['dal']);
                if ($n > $inizio && $n <= $fine) {
                    $punti[$n] = true;
                }
            }
        }
        for ($anno = (int) substr(self::data($inizio), 0, 4) + 1; $anno <= (int) substr($alla, 0, 4); $anno++) {
            $punti[self::numero($anno . '-01-01')] = true;
        }
        foreach ($cambi as $g) {
            $n = self::numero($g);
            if ($n > $inizio && $n <= $fine) {
                $punti[$n] = true;
            }
        }
        $punti = array_keys($punti);
        sort($punti);

        $saldo = array_fill_keys(array_keys($chiavi), 0);
        $giorni = array_keys($perGiorno);
        $prossimo = 0;
        $pezzi = array_fill_keys(array_keys($base), []);
        $causaDelGiorno = [];
        foreach ($punti as $i => $dal) {
            $al = ($punti[$i + 1] ?? $fine + 1) - 1;
            // I movimenti fino alla fine del giorno prima del tratto.
            $cause = [];
            while ($prossimo < count($giorni) && $giorni[$prossimo] <= $dal - 1) {
                foreach ($perGiorno[$giorni[$prossimo]] as $m) {
                    $saldo[$m['chiave']] = ($saldo[$m['chiave']] ?? 0) + (int) $m['importo'];
                    if ($giorni[$prossimo] === $dal - 1) {
                        $cause[$m['chiave']] = $m['causa'];
                    }
                }
                $prossimo++;
            }
            // I crediti della persona coprono le rate della base più vecchie.
            $credito = 0;
            foreach ($saldo as $s) {
                $credito += max(0, -$s);
            }
            $tasso = $tassoAl(self::data($dal));
            $divisore = self::giorniDellAnno((int) substr(self::data($dal), 0, 4));
            foreach (array_keys($base) as $k) {
                // Dopo il suo ultimo giorno la chiave è fuori dalla base: niente interessi, e il credito resta alle altre.
                if ($dal > $ultimo[$k]) {
                    continue;
                }
                // Di una rata coperta in parte da un accordo, con gli interessi che continuano sulla parte fuori (decisione 450), la parte nell'accordo
                // non è nella base: né interessi né crediti su quella.
                $debito = max(0, $saldo[$k] - self::protetto($chiavi[$k]['protetto'] ?? [], $dal));
                $coperto = min($credito, $debito);
                $credito -= $coperto;
                $capitale = $debito - $coperto;
                $primo = self::numero($chiavi[$k]['inizio']);
                if ($capitale <= 0 || $al < $primo) {
                    continue;
                }
                $daQui = max($dal, $primo);
                if (is_string($tasso)) {
                    $esito[$k]['fermo'] ??= $tasso;

                    continue;
                }
                // N3 della terza prova della beta.12: un movimento di un'altra chiave che non cambia il capitale di questa (l'addebito degli interessi registrati
                // fino al 31/12, per esempio) non fa «credito» del suo tratto: al 01/01 resta «anno», a una regola nuova «regola».
                $prima = $pezzi[$k] === [] ? null : $pezzi[$k][count($pezzi[$k]) - 1];
                $stessoCapitale = $prima !== null && $prima['al'] === $daQui - 1 && $prima['capitale'] === $capitale;
                $causa = match (true) {
                    $daQui === $primo => 'scadenza',
                    isset($chiavi[$k]['protetto'][0]) && $daQui === self::numero((string) $chiavi[$k]['protetto'][0]['dal']) => 'accordo',
                    isset($cause[$k]) => $cause[$k] === 'storno' ? 'storno' : ($cause[$k] === 'emissione' ? 'emissione' : 'versamento'),
                    $cause !== [] && ! $stessoCapitale => 'credito',
                    in_array(self::data($daQui), $cambi, true) => 'regola',
                    substr(self::data($daQui), 5) === '01-01' => 'anno',
                    default => 'credito',
                };
                $pezzi[$k][] = ['dal' => $daQui, 'al' => $al, 'capitale' => $capitale, 'tasso_e4' => (int) $tasso['tasso_e4'], 'tasso' => (string) $tasso['etichetta'],
                    'fonte' => (string) ($tasso['fonte'] ?? ''), 'regola_id' => $tasso['regola_id'] ?? null, 'divisore' => $divisore, 'causa' => $causa];
            }
        }

        foreach ($pezzi as $k => $lista) {
            if ($esito[$k]['fermo'] !== null) {
                continue;
            }
            try {
                $tratti = self::uniti($lista);
                $esito[$k]['tratti'] = $tratti;
                $esito[$k]['interessi'] = self::arrotonda($tratti);
            } catch (\DomainException $e) {
                // Una rata fuori misura (un importo scritto per errore) si ferma da sola, con la frase: le altre rate e le altre persone restano.
                $esito[$k] = ['tratti' => [], 'interessi' => 0, 'fermo' => $e->getMessage()];
            }
        }

        return $esito;
    }

    /**
     * Il capitale di ogni chiave della base nel giorno dato, come lo conta {@see calcola()} quel giorno: il saldo alla fine del giorno prima, meno la parte
     * protetta da un accordo, meno i crediti della persona che la coprono (le scadenze più vecchie prima). Una chiave dopo il suo ultimo giorno (`fine`) non
     * è nella base e non prende crediti; con `$alla`, neanche una chiave che comincia dopo (come nel calcolo a quella data). Pura, senza database.
     *
     * Serve alla base del debito ereditato (decisione 489: il capitale del defunto alla fine del giorno prima del decesso) e a tenere dov'era il credito del
     * defunto che copriva le rate passate agli eredi (D1 della prova della Coda 663).
     *
     * @param array<string, array{inizio: ?string, base: bool, ordine: array, fine?: ?string, protetto?: list<array{dal: string, importo: int}>}> $chiavi
     * @param list<array{giorno: string, chiave: string, importo: int}> $movimenti
     * @return array{saldi: array<string, int>, capitali: array<string, int>, coperti: array<string, int>, protetti: array<string, int>}
     */
    public static function capitaliAl(array $chiavi, array $movimenti, string $giorno, ?string $alla = null): array
    {
        $g = self::numero($giorno);
        $saldi = array_fill_keys(array_keys($chiavi), 0);
        foreach ($movimenti as $m) {
            if (self::numero($m['giorno']) <= $g - 1) {
                $saldi[$m['chiave']] = ($saldi[$m['chiave']] ?? 0) + (int) $m['importo'];
            }
        }
        $credito = 0;
        foreach ($saldi as $s) {
            $credito += max(0, -$s);
        }
        // Come nel calcolo alla data: anche la chiave registrata fino alla data o oltre tiene i suoi crediti (E5 della seconda prova della beta.12).
        $base = array_filter($chiavi, fn (array $c) => $c['base'] && (($c['fine'] ?? null) === null || self::numero((string) $c['fine']) >= $g)
            && ($alla === null || ($c['inizio'] !== null && (self::numero((string) $c['inizio']) <= self::numero($alla)
                || (($c['inizio_pieno'] ?? null) !== null && self::numero((string) $c['inizio_pieno']) <= self::numero($alla))))));
        uksort($base, fn ($a, $b) => [$chiavi[$a]['ordine'], $a] <=> [$chiavi[$b]['ordine'], $b]);
        $capitali = [];
        $coperti = [];
        $protetti = [];
        foreach (array_keys($base) as $k) {
            $protetti[$k] = min(max(0, $saldi[$k]), self::protetto($chiavi[$k]['protetto'] ?? [], $g));
            $debito = max(0, $saldi[$k] - self::protetto($chiavi[$k]['protetto'] ?? [], $g));
            $coperti[$k] = min($credito, $debito);
            $credito -= $coperti[$k];
            $capitali[$k] = $debito - $coperti[$k];
        }

        return ['saldi' => $saldi, 'capitali' => $capitali, 'coperti' => $coperti, 'protetti' => $protetti];
    }

    /** La parte protetta da un accordo il giorno dato: l'importo dell'ultimo passo cominciato quel giorno o prima; zero prima del primo. */
    private static function protetto(array $passi, int $giorno): int
    {
        $importo = 0;
        foreach ($passi as $p) {
            if (self::numero((string) $p['dal']) > $giorno) {
                break;
            }
            $importo = (int) $p['importo'];
        }

        return $importo;
    }

    /**
     * Gli interessi di una rata dai suoi tratti, arrotondati al centesimo più vicino, il mezzo in su: la parte intera di ogni tratto e i resti sommati
     * sul denominatore comune.
     *
     * @param list<array{capitale: int, tasso_e4: int, giorni: int, divisore: int}> $tratti
     */
    public static function arrotonda(array $tratti): int
    {
        $interi = 0;
        $resti = 0;
        foreach ($tratti as $t) {
            [$q, $r, $d] = self::frazione((int) $t['capitale'], (int) $t['tasso_e4'], (int) $t['giorni'], (int) $t['divisore']);
            $interi += $q;
            $resti += $r * intdiv(self::COMUNE, $d);
        }
        $interi += intdiv($resti, self::COMUNE);
        $resto = $resti % self::COMUNE;

        return $interi + (2 * $resto >= self::COMUNE ? 1 : 0);
    }

    /**
     * Gli interessi esatti di un tratto in centesimi di centesimo (€ 0,3945 è 3.945), arrotondati al più vicino: per la riga della proposta, che li dice
     * con quattro decimali; il totale della rata non li somma, somma le frazioni esatte.
     */
    public static function esatti(int $capitale, int $tassoE4, int $giorni, int $divisore): int
    {
        [$q, $r, $d] = self::frazione($capitale, $tassoE4, $giorni, $divisore);

        return $q * 100 + intdiv(200 * $r + $d, 2 * $d);
    }

    /**
     * Capitale × tasso × giorni / (1.000.000 × divisore), come quoziente, resto e divisore. Un tratto non passa l'anno (366 giorni al più): con un
     * capitale fino a € 10 milioni e un tasso fino al 100 % il prodotto sta in un intero.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function frazione(int $capitale, int $tassoE4, int $giorni, int $divisore): array
    {
        if ($capitale > 1_000_000_000 || $tassoE4 > 1_000_000 || $giorni > 366) {
            throw new \DomainException('Gli interessi di questa rata non si calcolano: il capitale, il tasso o i giorni sono fuori misura.');
        }
        $n = $capitale * $tassoE4 * $giorni;
        $d = 1_000_000 * $divisore;

        return [intdiv($n, $d), $n % $d, $d];
    }

    /**
     * I pezzi di una rata uniti in tratti: due pezzi di fila dello stesso anno, con lo stesso capitale, lo stesso tasso con la stessa fonte, la stessa regola
     * e lo stesso divisore sono un tratto solo, con la causa del primo. Un tratto non passa l'anno anche quando il tasso è lo stesso: il tasso è dell'anno,
     * e ogni tratto dice la sua fonte.
     *
     * @return list<array<string, mixed>>
     */
    private static function uniti(array $pezzi): array
    {
        $tratti = [];
        foreach ($pezzi as $p) {
            $ultimo = $tratti === [] ? null : $tratti[count($tratti) - 1];
            if ($ultimo !== null && $ultimo['al'] === $p['dal'] - 1 && $ultimo['capitale'] === $p['capitale'] && $ultimo['tasso_e4'] === $p['tasso_e4']
                && $ultimo['divisore'] === $p['divisore'] && $ultimo['tasso'] === $p['tasso'] && $ultimo['regola_id'] === $p['regola_id']
                && $ultimo['fonte'] === $p['fonte'] && substr(self::data($ultimo['dal']), 0, 4) === substr(self::data($p['dal']), 0, 4)) {
                $tratti[count($tratti) - 1]['al'] = $p['al'];

                continue;
            }
            $tratti[] = $p;
        }

        return array_map(function (array $t) {
            $giorni = $t['al'] - $t['dal'] + 1;

            return ['dal' => self::data($t['dal']), 'al' => self::data($t['al']), 'giorni' => $giorni, 'capitale' => $t['capitale'], 'tasso_e4' => $t['tasso_e4'],
                'tasso' => $t['tasso'], 'fonte' => $t['fonte'], 'regola_id' => $t['regola_id'], 'divisore' => $t['divisore'],
                'esatti' => self::esatti($t['capitale'], $t['tasso_e4'], $giorni, $t['divisore']), 'causa' => $t['causa']];
        }, $tratti);
    }

    public static function giorniDellAnno(int $anno): int
    {
        return ($anno % 4 === 0 && $anno % 100 !== 0) || $anno % 400 === 0 ? 366 : 365;
    }

    /** Il giorno come numero (i giorni dal 01/01/1970), per contare senza fusi orari. */
    public static function numero(string $giorno): int
    {
        return intdiv(gmmktime(0, 0, 0, (int) substr($giorno, 5, 2), (int) substr($giorno, 8, 2), (int) substr($giorno, 0, 4)), 86400);
    }

    public static function data(int $numero): string
    {
        return gmdate('Y-m-d', $numero * 86400);
    }
}
