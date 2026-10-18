<?php

namespace App\Services\Riparto;

use App\Helpers\MoneyHelper;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Le voci di spesa con un importo negativo che il motore di ripartizione addebita in positivo.
 *
 * Una voce di spesa si distribuisce con il suo valore assoluto (`CalcoloQuoteService::processaConti`): a −€ 240,00 il piano chiede € 240,00 ai
 * condòmini, mentre la distinta delle spese la somma con il suo segno. Questa classe rilegge, in sola lettura, la stessa selezione e lo stesso
 * importo del motore e dice dove succede: non cambia niente e non chiama il motore, quindi si può chiamare anche dalla pagina del piano.
 *
 * Le voci sono quelle che il motore legge: le righe di `piano_rate_capitoli` che stanno nel piano dei conti della gestione; se il piano non ne
 * ha, le radici di quel piano dei conti che nessun altro piano attivo della gestione ha già impegnato; con i loro sottoconti, come il motore li
 * visita. L'importo è la copia che il piano tiene per la voce se c'è, altrimenti l'importo della voce. Un capitolo con una copia si ripartisce
 * sulle sue tabelle (e allora i figli non contano), oppure sui figli nati fino alla creazione del piano, in proporzione al loro importo: un
 * figlio negativo fra pesi che sommano più di zero gonfia il totale oltre la copia del capitolo, e solo allora ferma il piano.
 *
 * Non si applica ai piani straordinari: le loro voci sono fatture. Una voce di entrata negativa non è un caso: il motore le toglie il segno
 * (`−abs`) come a una positiva.
 *
 * Un capitolo con la copia a zero o negativa è un caso a parte, perché il motore divide la copia fra i sottoconti in proporzione al loro importo (in parti
 * uguali se la somma non è maggiore di zero) e un sottoconto con un importo negativo, di spesa o di entrata, rende il totale diverso da quello del capitolo:
 * il fermo li nomina uno per uno, e dice che la copia del capitolo resta com'è anche dopo aver corretto i sottoconti: corretti, «Ricalcola» passa e non segnala più
 * niente, ma il capitolo resta sbagliato. Un capitolo a zero con i sottoconti tutti non negativi non è un caso per il fermo. Se nessun sottoconto è nato fino alla
 * creazione del piano il motore salta il capitolo, e il fermo guarda comunque quelli di adesso.
 *
 * Dove nessun sottoconto è più negativo il fermo non c'è, e la copia a zero o negativa resta: `capitoliConLaCopiaRimasta()` la dice alla pagina del piano, come
 * avviso e non come fermo, perché la stessa riga può essere voluta (un capitolo a zero da cui si è spostato tutto il budget, o che si è lasciato a zero alla creazione).
 *
 * Una voce che «Sincronizza» ha appena scritto nel piano non ha una copia che il piano tenga da prima: la copia è la sua cifra di adesso, e
 * il rifiuto del ricalcolo annulla la scrittura. Chi chiama passa gli id appena scritti, e quelle voci contano come voci: l'unica strada è correggerle.
 */
final class SpeseNegativeNelPiano
{
    public const ORIGINE_COPIA = 'copia';

    public const ORIGINE_VOCE = 'voce';

    public const ORIGINE_FIGLIO = 'figlio';

    /** Che cosa si perde ricreando il piano: lo dicono il rifiuto e l'avviso, perché chi lo fa possa annotare prima. */
    public const COSTO_DEL_PIANO_NUOVO = 'Ricreandolo si perdono il riparto manuale dei saldi solidali, i tratti di competenza delle voci, la ricorrenza, gli spostamenti di budget fra le voci e gli importi scelti per le voci, da scrivere di nuovo.';

    /** Che cosa costa riportare in bozza un piano approvato: lo dicono il rifiuto e l'avviso, perché chi lo fa possa annotare prima. */
    public const COSTO_DELLA_BOZZA = 'si azzerano il numero del verbale e la nota dell\'approvazione: annotali prima';

    /** Che cosa il piano fa della copia negativa di un capitolo con sottoconti di spesa e di entrata (alla terza persona del condizionale). */
    private const DIVISIONE_DEL_CAPITOLO_MISTO = 'dividerebbe fra i suoi sottoconti di spesa e di entrata: il totale del piano non sarebbe quello del capitolo';

    /**
     * @param  list<int>  $appenaSincronizzate  le voci che «Sincronizza» ha appena scritto nel piano: contano come voci, non come copia
     * @return list<array{conto_id: int, nome: string, importo: int, origine: string, padre: ?string, padre_id: ?int, copia_del_capitolo: ?int, appena_sincronizzato: bool, sottoconto: bool, voce_negativa: bool, discendenti_negativi: bool, capitolo: bool, misto: bool, fuori_dal_piano: bool, dal_piano_dei_conti: bool}> `importo` in centesimi, negativo;
     *         `origine`: la copia del piano è negativa, oppure la voce stessa (nessuna copia), oppure un figlio che il capitolo con la copia pesa;
     *         `copia_del_capitolo`: per un figlio, la copia a zero o negativa del capitolo (nulla se il capitolo ripartisce una copia positiva);
     *         `appena_sincronizzato`: il capitolo del figlio è una voce che «Sincronizza» ha appena scritto, quindi la copia non è ancora nel piano;
     *         `sottoconto`: la voce ha un padre (un sottoconto tolto dal piano non si riaggiunge con «Sincronizza», che riaggiunge solo i capitoli);
     *         `voce_negativa`: la voce nel piano dei conti ha ancora un importo negativo (correggerla è una strada);
     *         `discendenti_negativi`: un suo sottoconto, a qualunque livello, ha un importo negativo (correggerlo è l'altra strada: la voce è «già corretta» solo se nessuno dei due lo è);
     *         `capitolo`: la copia è quella di un capitolo senza tabelle con dei sottoconti nel piano (non si toglie con «Rimuovi voce» e «Sincronizza»: la pagina non riaggiunge i sottoconti);
     *         `misto`: quel capitolo ha sottoconti di spesa e di entrata, che il motore divide in un modo che non è la somma dei loro importi;
     *         `fuori_dal_piano`: per un figlio, nessun sottoconto del capitolo è nato fino alla creazione del piano: il motore salta il capitolo e il figlio non è ripartito;
     *         `dal_piano_dei_conti`: il piano non ha voci proprie e legge le radici del piano dei conti, quindi la voce non è una sua riga
     */
    public function trova(PianoRate $piano, array $appenaSincronizzate = []): array
    {
        if ($piano->tipo === 'straordinario') {
            return [];
        }

        $pianoConto = $piano->gestione?->pianoConto;
        if ($pianoConto === null) {
            return [];
        }

        $copie = DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->pluck('importo', 'conto_id');
        $overrides = [];
        foreach ($copie as $contoId => $importo) {
            if ($importo !== null) {
                $overrides[(int) $contoId] = (int) $importo;
            }
        }

        $voci = $pianoConto->conti()->with(['tabelleMillesimali', 'sottoconti.tabelleMillesimali', 'sottoconti.sottoconti']);
        $impegnati = [];
        if ($copie->isNotEmpty()) {
            $voci->whereIn('id', $copie->keys()->all());
        } else {
            $voci->whereNull('parent_id');
            $impegnati = DB::table('piano_rate_capitoli')
                ->join('piani_rate', 'piano_rate_capitoli.piano_rate_id', '=', 'piani_rate.id')
                ->where('piani_rate.gestione_id', $piano->gestione_id)
                ->where('piani_rate.attivo', true)
                ->where('piani_rate.id', '!=', $piano->id)
                ->pluck('conto_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $trovate = [];
        $visti = [];
        $propagati = [];
        $this->visita($voci->get(), $overrides, $propagati, $impegnati, $visti, $piano->created_at, $trovate);
        usort($trovate, fn ($a, $b) => $a['conto_id'] <=> $b['conto_id']);

        $senzaVoci = $copie->isEmpty();

        return array_map(function (array $v) use ($appenaSincronizzate, $senzaVoci) {
            if ($v['origine'] === self::ORIGINE_COPIA && in_array($v['conto_id'], $appenaSincronizzate, true)) {
                // La copia è la cifra di adesso e non c'è niente da togliere dal piano: la strada è correggere la voce, o il suo sottoconto negativo.
                $v['origine'] = self::ORIGINE_VOCE;
            }
            if ($v['origine'] === self::ORIGINE_FIGLIO && $v['padre_id'] !== null && in_array($v['padre_id'], $appenaSincronizzate, true)) {
                $v['appena_sincronizzato'] = true;
            }

            return $v + ['dal_piano_dei_conti' => $senzaVoci];
        }, $trovate);
    }

    /**
     * I capitoli del piano con la copia a zero o negativa e dei sottoconti con degli importi: il piano ripartisce la copia fra i sottoconti, non i loro importi, e
     * correggere un sottoconto non la cambia. Il fermo li nomina finché un sottoconto è negativo; corretto quello, «Ricalcola» passa e non segnala più niente,
     * e il capitolo resta com'era. Qui si dice solo che c'è, perché la stessa riga può essere voluta: lo è se uno spostamento di budget è partito dal capitolo, e non
     * conta un capitolo con le sue tabelle, con la copia positiva, senza sottoconti nati fino alla creazione del piano (il motore lo salta) o con i sottoconti tutti a zero.
     * Un capitolo che sta sotto un altro capitolo del piano riceve la copia da lui, e non è una riga sua.
     *
     * @return list<array{conto_id: int, nome: string, copia: int, sottoconti: list<string>}> `copia` in centesimi, a zero o negativa; `sottoconti`: i nomi di quelli con un importo
     */
    public function capitoliConLaCopiaRimasta(PianoRate $piano): array
    {
        if ($piano->tipo === 'straordinario') {
            return [];
        }

        $pianoConto = $piano->gestione?->pianoConto;
        if ($pianoConto === null) {
            return [];
        }

        $righe = DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->pluck('importo', 'conto_id');
        $copie = $righe->filter(fn ($importo) => $importo !== null && (int) $importo <= 0);
        if ($copie->isEmpty()) {
            return [];
        }

        // Uno spostamento di budget che parte dal capitolo ne ha abbassato la copia di proposito.
        $spostati = DB::table('budget_movements')
            ->where('piano_rate_id', $piano->id)
            ->whereIn('source_conto_id', $copie->keys()->all())
            ->pluck('source_conto_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $creatoIl = $piano->created_at;
        $trovati = [];
        $capitoli = $pianoConto->conti()
            ->whereIn('id', $copie->keys()->all())
            ->with(['tabelleMillesimali', 'sottoconti.tabelleMillesimali', 'sottoconti.sottoconti'])
            ->orderBy('id')
            ->get();

        foreach ($capitoli as $capitolo) {
            if (in_array((int) $capitolo->id, $spostati, true) || $capitolo->tabelleMillesimali->isNotEmpty() || $righe->has((int) $capitolo->parent_id)) {
                continue;
            }

            // I sottoconti che il motore guarda: quelli nati fino alla creazione del piano. Se nessuno lo è, il motore salta il capitolo e non c'è niente da ripartire.
            $figli = $creatoIl === null
                ? $capitolo->sottoconti
                : $capitolo->sottoconti->filter(fn (Conto $s) => $s->created_at === null || $s->created_at->lte($creatoIl));
            $conImporto = $figli->filter(fn (Conto $s) => $this->haImporti($s))->values();
            if ($conImporto->isEmpty()) {
                continue;
            }

            $trovati[] = [
                'conto_id' => (int) $capitolo->id,
                'nome' => (string) $capitolo->nome,
                'copia' => (int) $copie->get((int) $capitolo->id),
                'sottoconti' => $conImporto->map(fn (Conto $s) => (string) $s->nome)->all(),
            ];
        }

        return $trovati;
    }

    /** La voce o un suo sottoconto, a qualunque livello, ha un importo diverso da zero. */
    private function haImporti(Conto $conto): bool
    {
        if ((int) $conto->importo !== 0) {
            return true;
        }
        foreach ($conto->sottoconti as $figlio) {
            if ($this->haImporti($figlio)) {
                return true;
            }
        }

        return false;
    }

    /**
     * La stessa visita di `processaConti`: ogni voce una volta sola; con una copia (della riga del piano, o passata dal capitolo ai figli) si
     * ripartisce quella, senza copia l'importo della voce e poi i suoi sottoconti.
     *
     * @param  Collection<int, Conto>  $conti
     * @param  array<int, int>  $overrides  conto => copia in centesimi
     * @param  array<int, true>  $propagati  le copie che il capitolo ha passato ai figli (non sono righe del piano)
     * @param  list<int>  $impegnati
     * @param  list<int>  $visti
     * @param  list<array<string, mixed>>  $trovate
     */
    private function visita(Collection $conti, array &$overrides, array &$propagati, array $impegnati, array &$visti, ?CarbonInterface $creatoIl, array &$trovate, ?string $padre = null): void
    {
        foreach ($conti as $conto) {
            if (in_array($conto->id, $impegnati, true) || in_array($conto->id, $visti, true)) {
                continue;
            }
            $visti[] = $conto->id;

            if (isset($overrides[$conto->id])) {
                $copia = $overrides[$conto->id];
                $dellaRiga = ! isset($propagati[$conto->id]);

                if ($conto->tabelleMillesimali->isNotEmpty() || $conto->sottoconti->isEmpty()) {
                    if ($dellaRiga && $copia < 0 && Conto::segnoDelTipo((string) ($conto->tipo ?? '')) === 1) {
                        $trovate[] = $this->voce($conto, $copia, self::ORIGINE_COPIA, $padre);
                    }
                    continue;
                }

                $figliDelPiano = $creatoIl === null
                    ? $conto->sottoconti
                    : $conto->sottoconti->filter(fn (Conto $s) => $s->created_at === null || $s->created_at->lte($creatoIl));
                $figli = $figliDelPiano;
                if ($figli->isEmpty() && $copia > 0) {
                    $figli = $conto->sottoconti;
                }

                // Con la copia a zero o negativa ogni sottoconto con un importo negativo, di spesa o di entrata, falsa il totale: si nomina lui, e il capitolo
                // si nomina solo se non ne ha. Se nessun sottoconto è nato fino alla creazione del piano il motore salta il capitolo, ma i sottoconti di adesso
                // dicono lo stesso che la copia non è quella giusta.
                $negativi = $copia <= 0
                    ? ($figliDelPiano->isNotEmpty() ? $figliDelPiano : $conto->sottoconti)->filter(fn (Conto $s) => (int) $s->importo < 0)->values()
                    : collect();
                foreach ($negativi as $figlio) {
                    $trovate[] = $this->voce($figlio, (int) $figlio->importo, self::ORIGINE_FIGLIO, $conto->nome, (int) $conto->id, $copia, fuoriDalPiano: $figliDelPiano->isEmpty());
                }

                // Una copia negativa su un capitolo si addebita in positivo solo se il motore la divide fra i sottoconti: senza sottoconti nel piano lo salta.
                if ($dellaRiga && $copia < 0 && $negativi->isEmpty() && $figli->isNotEmpty() && $conto->segno($creatoIl) === 1) {
                    $trovate[] = $this->voce($conto, $copia, self::ORIGINE_COPIA, $padre, capitolo: true, misto: $conto->eMisto($creatoIl));
                }

                if ($figli->isEmpty()) {
                    continue;
                }

                $pesi = (int) $figli->sum('importo');
                if ($copia > 0 && $pesi > 0) {
                    foreach ($figli as $figlio) {
                        if ((int) $figlio->importo < 0 && $figlio->segno($creatoIl) === 1) {
                            $trovate[] = $this->voce($figlio, (int) $figlio->importo, self::ORIGINE_FIGLIO, $conto->nome);
                        }
                    }
                }

                $assegnata = 0;
                $contati = 0;
                foreach ($figli as $figlio) {
                    $contati++;
                    $quota = match (true) {
                        $contati === $figli->count() => $copia - $assegnata,
                        $pesi > 0 => (int) round($copia * ($figlio->importo / $pesi)),
                        default => (int) round($copia / $figli->count()),
                    };
                    $assegnata += $quota;
                    $overrides[$figlio->id] = $quota;
                    $propagati[$figlio->id] = true;
                }

                $this->visita($figli, $overrides, $propagati, $impegnati, $visti, $creatoIl, $trovate, $conto->nome);
                continue;
            }

            $importo = (int) $conto->importo;
            if ($importo < 0 && Conto::segnoDelTipo((string) ($conto->tipo ?? 'spesa')) === 1) {
                $trovate[] = $this->voce($conto, $importo, self::ORIGINE_VOCE, $padre);
            }

            if ($conto->sottoconti->isNotEmpty()) {
                $this->visita($conto->sottoconti, $overrides, $propagati, $impegnati, $visti, $creatoIl, $trovate, $conto->nome);
            }
        }
    }

    /** @return array{conto_id: int, nome: string, importo: int, origine: string, padre: ?string, padre_id: ?int, copia_del_capitolo: ?int, appena_sincronizzato: bool, sottoconto: bool, voce_negativa: bool, discendenti_negativi: bool, capitolo: bool, misto: bool, fuori_dal_piano: bool} */
    private function voce(Conto $conto, int $importo, string $origine, ?string $padre, ?int $padreId = null, ?int $copiaDelCapitolo = null, bool $capitolo = false, bool $misto = false, bool $fuoriDalPiano = false): array
    {
        $discendentiNegativi = $this->haDiscendentiNegativi($conto);

        return [
            'conto_id' => (int) $conto->id,
            'nome' => (string) $conto->nome,
            'importo' => $importo,
            'origine' => $origine,
            'padre' => $origine === self::ORIGINE_FIGLIO ? $padre : null,
            'padre_id' => $origine === self::ORIGINE_FIGLIO ? $padreId : null,
            'copia_del_capitolo' => $origine === self::ORIGINE_FIGLIO ? $copiaDelCapitolo : null,
            'appena_sincronizzato' => false,
            'sottoconto' => $conto->parent_id !== null,
            'voce_negativa' => (int) $conto->importo < 0,
            'discendenti_negativi' => $discendentiNegativi,
            'capitolo' => $capitolo,
            'misto' => $misto,
            'fuori_dal_piano' => $origine === self::ORIGINE_FIGLIO && $fuoriDalPiano,
        ];
    }

    /** Un sottoconto della voce, a qualunque livello, ha un importo negativo. */
    private function haDiscendentiNegativi(Conto $conto): bool
    {
        foreach ($conto->sottoconti as $figlio) {
            if ((int) $figlio->importo < 0 || $this->haDiscendentiNegativi($figlio)) {
                return true;
            }
        }

        return false;
    }

    // ─── Le frasi, per chi rifiuta e per chi avvisa ───────────────────────────────────────────────────────────────

    /**
     * La testa del rifiuto, senza l'apertura: «la voce di spesa «A» ha un importo negativo (meno € 240,00) e il piano la addebiterebbe in positivo, per € 240,00».
     * Una voce e più voci hanno la loro forma; una voce già corretta con la copia ancora negativa lo dice (la copia non si cambia correggendo la voce); un
     * figlio negativo sotto un capitolo con la copia ha una frase propria, perché la cifra che il piano addebita di più non è quella del figlio, e un figlio
     * sotto un capitolo con la copia a zero o negativa ne ha un'altra, perché lì il totale non torna per un altro motivo.
     *
     * @param  list<array<string, mixed>>  $voci
     */
    public static function testa(array $voci): string
    {
        $semplici = array_values(array_filter($voci, fn ($v) => $v['origine'] !== self::ORIGINE_FIGLIO));
        $figli = array_filter($voci, fn ($v) => $v['origine'] === self::ORIGINE_FIGLIO);
        $parti = [];

        if (count($semplici) === 1) {
            $cifra = self::cifra($semplici[0]['importo']);
            // Un capitolo con sottoconti di spesa e di entrata il motore lo divide fra le due: la cifra addebitata non è quella della copia, e non si dice.
            $misto = (bool) ($semplici[0]['misto'] ?? false);
            $parti[] = match (true) {
                self::giaCorretta($semplici[0]) => "la voce di spesa «{$semplici[0]['nome']}» è già corretta, ma il piano ne tiene la copia negativa (meno {$cifra}) e "
                    . ($misto ? 'la ' . self::DIVISIONE_DEL_CAPITOLO_MISTO : "la addebiterebbe in positivo, per {$cifra}"),
                self::soloLaCopia($semplici[0]) => "la voce di spesa «{$semplici[0]['nome']}» ha nel piano una copia negativa (meno {$cifra}) e il piano la "
                    . ($misto ? self::DIVISIONE_DEL_CAPITOLO_MISTO : "addebiterebbe in positivo, per {$cifra}"),
                default => "la voce di spesa «{$semplici[0]['nome']}» ha un importo negativo (meno {$cifra}) e il piano la addebiterebbe in positivo, per {$cifra}",
            };
        } elseif ($semplici !== []) {
            $tutteNegative = array_filter($semplici, fn ($v) => ! $v['voce_negativa']) === [];
            $parti[] = count($semplici) . ($tutteNegative
                    ? ' voci di spesa hanno un importo negativo e il piano le addebiterebbe in positivo: '
                    : ' voci di spesa hanno un importo o una copia nel piano negativi e il piano le addebiterebbe in positivo: ')
                . implode(', ', array_map(fn ($v) => match (true) {
                    self::giaCorretta($v) => "«{$v['nome']}» (già corretta, ma con la copia a meno " . self::cifra($v['importo']) . ')',
                    self::soloLaCopia($v) => "«{$v['nome']}» (con la copia a meno " . self::cifra($v['importo']) . ')',
                    default => "«{$v['nome']}» (meno " . self::cifra($v['importo']) . ')',
                }, $semplici));
        }

        foreach (self::perPadre($figli) as $padre => $loro) {
            $conCopia = $loro[0]['copia_del_capitolo'] !== null;
            $parti[] = match (true) {
                $conCopia => self::testaDeiFigliDiUnaCopiaNonPositiva($padre, $loro),
                count($loro) === 1 => "l'importo del capitolo «{$padre}» si ripartisce anche sul sottoconto «{$loro[0]['nome']}», che ha un importo negativo (meno " . self::cifra($loro[0]['importo']) . '): il totale del piano supererebbe quello del capitolo',
                default => "l'importo del capitolo «{$padre}» si ripartisce anche sui sottoconti con un importo negativo, "
                    . implode(', ', array_map(fn ($v) => "«{$v['nome']}» (meno " . self::cifra($v['importo']) . ')', $loro)) . ': il totale del piano supererebbe quello del capitolo',
            };
        }

        return implode('. ', array_map(fn ($p, $i) => $i === 0 ? $p : mb_strtoupper(mb_substr($p, 0, 1)) . mb_substr($p, 1), $parti, array_keys($parti)));
    }

    /** La voce e i suoi sottoconti non hanno un importo negativo, ma il piano ne tiene ancora la copia negativa. @param array<string, mixed> $v */
    public static function giaCorretta(array $v): bool
    {
        return $v['origine'] === self::ORIGINE_COPIA && ! $v['voce_negativa'] && ! $v['discendenti_negativi'];
    }

    /** La voce non ha un importo negativo, ma un suo sottoconto sì, e il piano ne tiene la copia negativa. @param array<string, mixed> $v */
    public static function soloLaCopia(array $v): bool
    {
        return $v['origine'] === self::ORIGINE_COPIA && ! $v['voce_negativa'] && $v['discendenti_negativi'];
    }

    /**
     * Il capitolo con la copia a zero o negativa e i suoi sottoconti negativi: «il sottoconto «B», sotto il capitolo «A», ha un importo negativo (meno € 500,00)
     * e il capitolo ha nel piano una copia negativa (meno € 400,00): il totale del piano non è quello del capitolo». Una voce che «Sincronizza» sta per scrivere
     * non ha ancora la copia nel piano: la frase è al condizionale.
     *
     * @param  list<array<string, mixed>>  $figli  i sottoconti negativi dello stesso capitolo
     */
    private static function testaDeiFigliDiUnaCopiaNonPositiva(string $padre, array $figli): string
    {
        $adesso = ! $figli[0]['appena_sincronizzato'];
        $copia = self::descriviLaCopia($figli[0]['copia_del_capitolo']);
        $totale = $adesso ? 'il totale del piano non è quello del capitolo' : 'il totale del piano non sarebbe quello del capitolo';
        $capitolo = $adesso ? 'ha nel piano' : 'avrebbe nel piano';

        return count($figli) === 1
            ? "il sottoconto «{$figli[0]['nome']}», sotto il capitolo «{$padre}», ha un importo negativo (meno " . self::cifra($figli[0]['importo']) . ") e il capitolo {$capitolo} una copia {$copia}: {$totale}"
            : "il capitolo «{$padre}» {$capitolo} una copia {$copia} e i sottoconti "
                . implode(', ', array_map(fn ($v) => "«{$v['nome']}» (meno " . self::cifra($v['importo']) . ')', $figli)) . " hanno un importo negativo: {$totale}";
    }

    /** «negativa (meno € 400,00)» o «a zero»: la copia a zero o negativa di un capitolo, a parole. */
    public static function descriviLaCopia(int $copia): string
    {
        return $copia < 0 ? 'negativa (meno ' . self::cifra($copia) . ')' : 'a zero';
    }

    /**
     * Le strade per togliere il segno dal piano, una frase per strada, ciascuna con quello che costa. Correggere l'importo della voce nel piano dei
     * conti non basta quando il piano ne tiene la copia: la copia di una voce di primo livello si cambia togliendola dal piano e riaggiungendola, e
     * togliendola se ne perdono i tratti di competenza. Un sottoconto tolto dal piano non si riaggiunge (la sincronizzazione riaggiunge solo i capitoli),
     * e la copia di un capitolo non cambia correggendone i sottoconti, né togliendo il capitolo (la pagina ripropone i sottoconti, non il capitolo): in tutti
     * questi casi, e con più di una voce così (togliere la prima ricalcola il piano con le altre dentro), si elimina il piano per ricrearlo, e si dice che cosa
     * si perde. Per il capitolo con la copia a zero o negativa si dice anche che, corretti i sottoconti, «Ricalcola» passa e non segnala più niente: il capitolo
     * resta sbagliato finché il piano non è ricreato.
     *
     * @param  list<array<string, mixed>>  $voci
     * @param  bool  $allaCreazione  il piano non esiste ancora: la copia nasce dall'importo della voce e si riscrive con il modulo
     * @return list<string>
     */
    public static function frasiDiCorrezione(array $voci, bool $allaCreazione = false): array
    {
        // La voce da correggere è quella con l'importo negativo; per la copia di un capitolo, se nessun sottoconto è già nominato, è il sottoconto negativo.
        $conFigli = array_filter($voci, fn ($v) => $v['origine'] === self::ORIGINE_FIGLIO) !== [];
        $daCorreggere = array_values(array_filter($voci, fn ($v) => $v['voce_negativa'] || ($v['discendenti_negativi'] && ! $conFigli)));
        $figliDiUnaCopia = array_values(array_filter($voci, fn ($v) => $v['origine'] === self::ORIGINE_FIGLIO && $v['copia_del_capitolo'] !== null));
        $copie = array_values(array_filter($voci, fn ($v) => $v['origine'] === self::ORIGINE_COPIA));
        // La copia di un capitolo con sottoconti è una strada a sé: i sottoconti non si riaggiungono, quindi non si toglie e non si riaggiunge.
        $copieDiCapitolo = array_values(array_filter($copie, fn ($v) => $v['capitolo'] ?? false));
        $altreCopie = array_values(array_filter($copie, fn ($v) => ! ($v['capitolo'] ?? false)));
        $frasi = [];

        // Se la sola cosa da correggere sono i sottoconti di un capitolo con la copia non positiva, si dicono per nome: nel capitolo non c'è niente da correggere.
        $soloFigli = $daCorreggere !== [] && count(array_filter($daCorreggere, fn ($v) => ! in_array($v, $figliDiUnaCopia, true))) === 0;
        if ($soloFigli) {
            $nomi = array_map(fn ($v) => "«{$v['nome']}»", $daCorreggere);
            $frasi[] = count($nomi) === 1
                ? "Correggi l'importo del sottoconto {$nomi[0]} nel piano dei conti: se è un provento, scegli «Entrata» e scrivi l'importo senza il segno."
                : "Correggi l'importo dei sottoconti " . self::elenco($nomi) . " nel piano dei conti: se sono proventi, scegli «Entrata» e scrivi l'importo senza il segno.";
        } elseif (count($daCorreggere) === 1) {
            $frasi[] = 'Correggi l\'importo della voce nel piano dei conti: se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.';
        } elseif (count($daCorreggere) > 1) {
            $frasi[] = 'Correggi l\'importo delle voci nel piano dei conti: se sono proventi, scegli «Entrata» e scrivi l\'importo senza il segno.';
        }

        if ($allaCreazione) {
            return $frasi;
        }

        // I capitoli con la copia che resta com'è: la copia della voce e quella di ogni capitolo con sottoconti negativi contano una volta ciascuna.
        $copiaDaRiscrivere = array_values(array_filter($figliDiUnaCopia, fn ($v) => ! $v['appena_sincronizzato']));
        $capitoli = [];
        foreach ($copiaDaRiscrivere as $v) {
            $capitoli[$v['padre_id']] = $v['padre'];
        }
        $quante = count($altreCopie) + count($copieDiCapitolo) + count($capitoli);

        if ($quante === 1 && $altreCopie !== [] && ! $altreCopie[0]['sottoconto']) {
            $frasi[] = 'Il piano tiene una copia dell\'importo della voce e correggere la voce non la cambia: togli la voce dal piano con «Rimuovi voce e ricalcola» e riaggiungila con «Sincronizza». '
                . 'Togliendola si perdono i suoi tratti di competenza, se ne ha: annotali prima e riscrivili ricreando il piano.';
        } elseif ($quante === 1 && $altreCopie !== []) {
            $frasi[] = 'Il piano tiene una copia dell\'importo della voce e correggere la voce non la cambia; un sottoconto tolto dal piano non si riaggiunge con «Sincronizza», che riaggiunge solo i capitoli: '
                . 'elimina il piano e ricrealo dopo aver corretto la voce. ' . self::COSTO_DEL_PIANO_NUOVO;
        } elseif ($quante === 1 && $copieDiCapitolo !== []) {
            $dopo = $copieDiCapitolo[0]['discendenti_negativi'] ? ' dopo aver corretto i sottoconti' : '';
            $frasi[] = "Il piano tiene una copia dell'importo del capitolo «{$copieDiCapitolo[0]['nome']}» e correggere i suoi sottoconti non la cambia: elimina il piano e ricrealo{$dopo}. " . self::COSTO_DEL_PIANO_NUOVO;
        } elseif ($quante === 1) {
            $frasi[] = 'Il piano tiene una copia dell\'importo del capitolo «' . reset($capitoli) . '» e correggere i suoi sottoconti non la cambia: elimina il piano e ricrealo dopo aver corretto i sottoconti, e non ricalcolarlo prima. '
                . 'Corretti i sottoconti, «Ricalcola» passa e non segnala più niente, ma il capitolo resta sbagliato. ' . self::COSTO_DEL_PIANO_NUOVO;
        } elseif ($quante > 1) {
            $frasi[] = 'Il piano tiene una copia dell\'importo delle voci e correggere le voci non la cambia, e con più di una voce così il piano si ferma a ogni rimozione: elimina il piano e ricrealo dopo aver corretto le voci. '
                . ($capitoli === [] ? '' : self::passaInSilenzio(array_values($capitoli)) . ' ')
                . self::COSTO_DEL_PIANO_NUOVO;
        }

        return $frasi;
    }

    /** Il capitolo con la copia a zero o negativa, corretti i sottoconti: «Ricalcola» non lo segnala più e il capitolo resta com'era. @param list<string> $capitoli i nomi */
    private static function passaInSilenzio(array $capitoli): string
    {
        return count($capitoli) === 1
            ? "Per il capitolo «{$capitoli[0]}», corretti i sottoconti, «Ricalcola» non lo segnala più ma il capitolo resta sbagliato."
            : 'Per i capitoli ' . self::elenco(array_map(fn ($nome) => "«{$nome}»", $capitoli)) . ', corretti i sottoconti, «Ricalcola» non li segnala più ma i capitoli restano sbagliati.';
    }

    /** ««A»», ««A» e «B»», ««A», «B» e «C»». @param list<string> $voci */
    public static function elenco(array $voci): string
    {
        return count($voci) <= 1 ? ($voci[0] ?? '') : implode(', ', array_slice($voci, 0, -1)) . ' e ' . $voci[count($voci) - 1];
    }

    /**
     * Se il piano non ha voci proprie (anche perché l'ultima è stata appena tolta) ripartisce tutte le voci del piano dei conti, e la voce negativa ne fa parte:
     * senza questa frase il rifiuto nomina una voce che nel piano non è mai stata.
     *
     * @param  list<array<string, mixed>>  $voci
     */
    public static function spiegaIlPianoSenzaVoci(array $voci): ?string
    {
        $nomi = array_values(array_unique(array_map(fn ($v) => "«{$v['nome']}»", array_filter($voci, fn ($v) => $v['dal_piano_dei_conti'] ?? false))));
        if ($nomi === []) {
            return null;
        }

        $elenco = count($nomi) === 1 ? $nomi[0] : implode(', ', array_slice($nomi, 0, -1)) . ' e ' . end($nomi);

        return 'Senza voci nel piano, il piano ripartisce tutte le voci del piano dei conti: ' . $elenco . (count($nomi) === 1 ? ' ne fa parte.' : ' ne fanno parte.');
    }

    /** Un importo in centesimi come cifra senza segno, col simbolo prima: «€ 240,00». */
    public static function cifra(int $centesimi): string
    {
        return MoneyHelper::format(abs($centesimi));
    }

    /**
     * @param  array<int, array<string, mixed>>  $figli
     * @return array<string, list<array<string, mixed>>> padre => figli
     */
    private static function perPadre(array $figli): array
    {
        $gruppi = [];
        foreach ($figli as $figlio) {
            $gruppi[(string) $figlio['padre']][] = $figlio;
        }

        return $gruppi;
    }
}
