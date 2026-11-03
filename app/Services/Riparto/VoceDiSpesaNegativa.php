<?php

namespace App\Services\Riparto;

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use App\Services\Gestionale\BloccoDellaVoce;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Le parole per una voce di spesa scritta con il meno (dato delle versioni fino alla 1.11.0-beta.51), fuori dal fermo e dall'avviso del piano.
 *
 * Il motore la addebita in positivo (`CalcoloQuoteService::processaConti`), e la creazione di un piano nuovo non la propone e non la accetta con
 * una cifra: la addebiterebbe una seconda volta se un piano la addebita già, o la addebiterebbe in positivo se nessuno la addebita. Che cosa dire
 * dipende da dove sta la voce:
 * - fuori da ogni piano della gestione correggerla è l'unica strada, e le parole sono quelle del fermo della creazione; se però un piano approvato
 *   tiene la voce o un capitolo sopra di lei senza addebitarla, la voce non cambia importo, padre né tipo (`Conto::pianiCheBloccanoLaVoce`), e le
 *   parole nominano quel piano e dicono la strada che passa: il piano riportato in bozza prima di correggere la voce (`BloccoDellaVoce`);
 * - in un piano le parole nominano il piano e rimandano alla sua pagina dove l'avviso della pagina nomina la voce (`AvvisoSpeseNegative`): la pagina
 *   dice le strade secondo lo stato del piano, e con il piano approvato l'importo della voce non si cambia. Dove l'avviso non la nomina (il piano ne
 *   tiene una copia positiva) le parole dicono solo che il piano la addebita in positivo.
 *
 * Le usano il selettore delle voci della creazione, le voci scoperte del cruscotto, il rifiuto delle voci scelte con una cifra e il rifiuto di «Sposta
 * spesa» (`BudgetMovementService`). La frase sotto i totali del piano dei conti la compone la pagina, con gli stessi dati (`pianiPerVoce` e i piani
 * che bloccano la voce).
 */
final class VoceDiSpesaNegativa
{
    /**
     * I piani della gestione che addebitano ciascuna voce data, in ordine di id: quelli dove il motore la raggiunge e le ripartisce una cifra diversa
     * da zero, dalla riga della voce o da quella di un capitolo sopra di lei (`laAddebita`). `avviso` è vero se l'avviso della pagina del piano nomina
     * la voce: lo decide il rilevamento dell'avviso stesso (`SpeseNegativeNelPiano::trova`), ed è l'unico punto che lo decide.
     *
     * Non conta un piano senza voci proprie (dei piani più vecchi), che legge tutte le radici del piano dei conti senza tenerne la copia.
     *
     * @param  list<int>  $contiIds
     * @return array<int, list<array{id: int, nome: string, avviso: bool}>> conto => piani; una voce senza piani non c'è
     */
    public static function pianiPerVoce(array $contiIds, int $gestioneId): array
    {
        $contiIds = array_values(array_unique(array_map('intval', $contiIds)));
        if ($contiIds === []) {
            return [];
        }

        $catene = Conto::catene($contiIds);
        $righe = DB::table('piano_rate_capitoli')
            ->join('piani_rate', 'piano_rate_capitoli.piano_rate_id', '=', 'piani_rate.id')
            ->where('piani_rate.gestione_id', $gestioneId)
            ->whereIn('piani_rate.stato', ['bozza', 'approvato'])
            ->whereIn('piano_rate_capitoli.conto_id', array_values(array_unique(array_merge(...array_values($catene)))))
            ->orderBy('piani_rate.id')
            ->get(['piano_rate_capitoli.conto_id', 'piano_rate_capitoli.importo', 'piani_rate.id']);
        if ($righe->isEmpty()) {
            return [];
        }

        // La copia di ogni riga per piano: nulla dove il piano legge l'importo della voce.
        $copie = [];
        foreach ($righe as $riga) {
            $copie[(int) $riga->id][(int) $riga->conto_id] = $riga->importo === null ? null : (int) $riga->importo;
        }
        $pianiRate = PianoRate::whereIn('id', array_keys($copie))->orderBy('id')->get();
        $conti = Conto::with(['tabelleMillesimali', 'sottoconti'])->whereIn('id', array_merge(...array_values($catene)))->get()->keyBy('id');

        $piani = [];
        foreach ($pianiRate as $piano) {
            $addebitate = array_values(array_filter($contiIds, fn (int $id) => self::laAddebita($catene[$id], $copie[(int) $piano->id], $conti, $piano->created_at)));
            if ($addebitate === []) {
                continue;
            }
            $nominate = array_map(fn (array $v) => (int) $v['conto_id'], app(SpeseNegativeNelPiano::class)->trova($piano));
            foreach ($addebitate as $id) {
                $piani[$id][] = ['id' => (int) $piano->id, 'nome' => (string) $piano->nome, 'avviso' => in_array($id, $nominate, true)];
            }
        }

        return $piani;
    }

    /**
     * La nota del selettore della creazione e della voce scoperta del cruscotto.
     *
     * @param  int  $importo  l'importo scritto della voce, in centesimi (negativo)
     * @param  list<array{id: int, nome: string, avviso: bool}>  $piani  i piani della gestione che la addebitano (`pianiPerVoce`)
     * @param  list<array{id: int, nome: string, capitolo: ?string, movimenti: list<string>}>  $bloccano  i piani che la bloccano (`Conto::pianiCheBloccanoLaVoce`)
     * @param  bool  $terzoLivello  la voce è al terzo livello del piano dei conti, o più in basso ({@see alTerzoLivello()})
     */
    public static function nota(string $nome, int $importo, array $piani, array $bloccano = [], bool $terzoLivello = false): string
    {
        $cifra = SpeseNegativeNelPiano::cifra($importo);
        $testa = "La voce di spesa «{$nome}» ha un importo negativo (meno {$cifra})";
        $conAvviso = self::conAvviso($piani);

        return match (count($piani)) {
            0 => "{$testa} e il piano la addebiterebbe in positivo, per {$cifra}. " . self::correzione($bloccano, terzoLivello: $terzoLivello),
            1 => "{$testa} ed è nel piano «{$piani[0]['nome']}», che la addebita in positivo" . ($conAvviso === [] ? '.' : ': la pagina del piano dice che cosa fare.'),
            default => "{$testa} ed è nei piani " . self::nomi($piani) . ', che la addebitano in positivo'
                . (($pagine = self::pagine($piani, $conAvviso)) === null ? '.' : ": {$pagine}."),
        };
    }

    /**
     * Il rifiuto di una voce scelta con una cifra nella creazione di un piano.
     *
     * @param  int  $importo  l'importo scritto della voce, in centesimi (negativo)
     * @param  list<array{id: int, nome: string, avviso: bool}>  $piani  i piani della gestione che la addebitano (`pianiPerVoce`)
     * @param  list<array{id: int, nome: string, capitolo: ?string, movimenti: list<string>}>  $bloccano  i piani che la bloccano (`Conto::pianiCheBloccanoLaVoce`)
     * @param  bool  $terzoLivello  la voce è al terzo livello del piano dei conti, o più in basso ({@see alTerzoLivello()})
     */
    public static function rifiuto(string $nome, int $importo, array $piani, array $bloccano = [], bool $terzoLivello = false): string
    {
        $testa = "La voce di spesa «{$nome}» ha un importo negativo (meno " . SpeseNegativeNelPiano::cifra($importo) . ')';
        $conAvviso = self::conAvviso($piani);

        return match (count($piani)) {
            0 => "{$testa} e il piano la addebiterebbe in positivo. " . self::correzione($bloccano, terzoLivello: $terzoLivello),
            1 => "{$testa} ed è già nel piano «{$piani[0]['nome']}», che la addebita in positivo: un piano nuovo la addebiterebbe una seconda volta."
                . ($conAvviso === [] ? '' : " La pagina di «{$piani[0]['nome']}» dice che cosa fare."),
            default => "{$testa} ed è già nei piani " . self::nomi($piani) . ', che la addebitano in positivo: un piano nuovo la addebiterebbe un\'altra volta.'
                . (($pagine = self::pagine($piani, $conAvviso)) === null ? '' : ' ' . ucfirst($pagine) . '.'),
        };
    }

    /**
     * Il rifiuto di «Sposta spesa» su una voce che l'avviso della pagina del piano nomina (`SpeseNegativeNelPiano::trova`): il piano la conta per il
     * valore assoluto, e spostare budget su di lei o da lei cambierebbe il totale del piano invece di lasciarlo com'è. La testa è quella del fermo del
     * piano, che dice la voce come la dice l'avviso; le strade le dice l'avviso, sulla stessa pagina.
     *
     * @param  string  $nome  la voce scelta nel modale: la voce stessa, o il capitolo che ripartisce i sottoconti negativi
     * @param  list<array<string, mixed>>  $voci  le voci dell'avviso che la riguardano: lei, o i sottoconti negativi che ripartisce
     * @param  bool  $comeDestinazione  la voce è quella su cui si sposta il budget; altrimenti è quella da cui si preleva
     */
    public static function spostamentoNelPiano(string $nome, array $voci, bool $comeDestinazione): string
    {
        $testa = SpeseNegativeNelPiano::testa($voci);

        return mb_strtoupper(mb_substr($testa, 0, 1)) . mb_substr($testa, 1) . '. '
            . ($comeDestinazione ? 'Spostare budget su' : 'Prelevare budget da') . " «{$nome}» cambierebbe il totale del piano: l'avviso della pagina dice che cosa fare.";
    }

    /**
     * Il rifiuto di «Sposta spesa» su una voce di spesa scritta con il meno che l'avviso della pagina non nomina (fuori dal piano, con la copia a zero o
     * con una copia positiva): con il budget spostato il piano la addebiterebbe in positivo, o più di adesso, e la creazione di un piano non accetta una
     * cifra per una voce così. La strada è quella della nota: correggere la voce, se nessun altro piano la addebita; altrimenti i piani che la addebitano.
     *
     * @param  int  $importo  l'importo scritto della voce, in centesimi (negativo)
     * @param  int|null  $copia  la copia positiva che il piano ne tiene, in centesimi; null se il piano non la addebita
     * @param  list<array{id: int, nome: string, avviso: bool}>  $altriPiani  gli altri piani della gestione che la addebitano (`pianiPerVoce`)
     * @param  list<array{id: int, nome: string, capitolo: ?string, movimenti: list<string>}>  $bloccano  i piani che la bloccano (`Conto::pianiCheBloccanoLaVoce`)
     * @param  bool  $terzoLivello  la voce è al terzo livello del piano dei conti, o più in basso ({@see alTerzoLivello()})
     * @param  list<array{id: int, nome: string, capitolo: string, cifra: int}>  $perdono  i piani in bozza da cui il nuovo padre la porta fuori ({@see pianiCheLaPerdono()})
     */
    public static function spostamentoSuUnaSpesaNegativa(string $nome, int $importo, ?int $copia, array $altriPiani, array $bloccano = [], bool $terzoLivello = false, array $perdono = []): string
    {
        $testa = "La voce di spesa «{$nome}» ha un importo negativo (meno " . SpeseNegativeNelPiano::cifra($importo) . ')';
        $perche = $copia === null
            ? "{$testa}: spostandoci budget, questo piano la addebiterebbe in positivo."
            : "{$testa} e questo piano la addebita già in positivo, per " . SpeseNegativeNelPiano::cifra($copia) . ': spostandoci budget la addebiterebbe di più.';
        $conAvviso = self::conAvviso($altriPiani);

        return $perche . ' ' . match (count($altriPiani)) {
            0 => self::correzione($bloccano, terzoLivello: $terzoLivello, perdono: $perdono),
            1 => "È nel piano «{$altriPiani[0]['nome']}», che la addebita in positivo" . ($conAvviso === [] ? '.' : ": la pagina di «{$altriPiani[0]['nome']}» dice che cosa fare."),
            default => 'È nei piani ' . self::nomi($altriPiani) . ', che la addebitano in positivo'
                . (($pagine = self::pagine($altriPiani, $conAvviso)) === null ? '.' : ": {$pagine}."),
        };
    }

    /**
     * Il rifiuto di «Sposta spesa» su un capitolo che il piano non tiene e che ha un sottoconto di spesa scritto con il meno: con la riga nuova il motore
     * ripartirebbe la copia del capitolo anche sul sottoconto, e lo addebiterebbe in positivo. La strada è correggere il sottoconto. Un sottoconto più in
     * basso di un livello si dice con il suo padre («ha, sotto «Impianti», il sottoconto «Rimborso»»).
     *
     * @param  int  $importo  l'importo scritto del sottoconto, in centesimi (negativo)
     * @param  list<array{id: int, nome: string, capitolo: ?string, movimenti: list<string>}>  $bloccano  i piani che bloccano il sottoconto (`Conto::pianiCheBloccanoLaVoce`)
     * @param  string|null  $padre  il padre del sottoconto, se non è il capitolo; nullo se il sottoconto è suo
     * @param  bool  $terzoLivello  il sottoconto è al terzo livello del piano dei conti, o più in basso ({@see alTerzoLivello()}); se è suo, il capitolo
     *                              è a sua volta un sottoconto, e la frase lo dice di lui
     * @param  list<array{id: int, nome: string, capitolo: string, cifra: int}>  $perdono  i piani in bozza da cui il nuovo padre porta fuori il sottoconto ({@see pianiCheLaPerdono()})
     */
    public static function spostamentoSulCapitolo(string $capitolo, string $sottoconto, int $importo, array $bloccano = [], ?string $padre = null, bool $terzoLivello = false, array $perdono = []): string
    {
        $dove = $padre === null ? '' : ", sotto «{$padre}»,";

        return "Il capitolo «{$capitolo}» ha{$dove} il sottoconto «{$sottoconto}» con un importo negativo (meno " . SpeseNegativeNelPiano::cifra($importo) . '): '
            . 'spostandoci budget, questo piano ripartirebbe la copia del capitolo anche sul sottoconto e lo addebiterebbe in positivo. '
            . self::correzione($bloccano, $sottoconto, $terzoLivello, $padre === null ? $capitolo : null, $perdono);
    }

    /**
     * La strada per una voce che nessun piano addebita: correggerla nel piano dei conti. Se un piano approvato tiene la voce, o un capitolo sopra di lei,
     * senza addebitarla, la voce non cambia importo, padre né tipo («Modifica inibita», `Conto::pianiCheBloccanoLaVoce`): la frase nomina il piano e dice
     * la strada della bozza, con quello che costa e con che cosa annullare prima, se ci sono delle rate emesse; poi la correzione. Se un passaggio di
     * titolarità di prima della 1.11.0-beta.37, che dal programma non si annulla, ferma l'annullamento delle emissioni, la bozza non è una strada, e la
     * frase dice che la voce resta com'è (`BloccoDellaVoce`).
     *
     * Una voce sotto un sottoconto, al terzo livello o più in basso, che il piano dei conti non prevede (dato delle versioni fino alla 1.9.1), si salva
     * solo con un capitolo padre di primo livello (`UpdateContoRequest`): la frase lo dice prima di ogni altra strada. Il sottoconto di un capitolo fuori
     * dal piano di «Sposta spesa» può essere addebitato da un piano in bozza con la riga di un capitolo sopra di lui: il nuovo padre lo porta fuori da quel
     * capitolo, e la frase lo dice, con quanto il piano lo addebita oggi ({@see pianiCheLaPerdono()}).
     *
     * @param  list<array{id: int, nome: string, capitolo: ?string, movimenti: list<string>}>  $bloccano  `Conto::pianiCheBloccanoLaVoce`
     * @param  string|null  $sottoconto  il nome del sottoconto da correggere, quando la frase parla del suo capitolo; nullo se parla della voce
     * @param  bool  $terzoLivello  la voce, o il sottoconto, è al terzo livello o più in basso
     * @param  string|null  $capitoloSottoconto  il capitolo che la frase ha nominato, quando è lui il padre del sottoconto: la frase dice che è a sua volta
     *                                           un sottoconto, perché la stessa voce non sia un capitolo in una frase e un altro sottoconto in quella dopo
     * @param  list<array{id: int, nome: string, capitolo: string, cifra: int}>  $perdono  i piani in bozza da cui il nuovo padre porta fuori la voce ({@see pianiCheLaPerdono()})
     */
    private static function correzione(array $bloccano = [], ?string $sottoconto = null, bool $terzoLivello = false, ?string $capitoloSottoconto = null, array $perdono = []): string
    {
        $diChi = $sottoconto === null ? 'della voce' : "del sottoconto «{$sottoconto}»";
        [$sottoUnSottoconto, $comeSuoPadre, $ogniModifica] = $capitoloSottoconto === null
            ? [($sottoconto === null ? 'La voce è sotto un sottoconto' : "Il sottoconto «{$sottoconto}» è sotto un altro sottoconto") . ': nel piano dei conti, che non lo prevede,', 'come suo capitolo padre', 'ogni sua modifica']
            : ["«{$capitoloSottoconto}» è a sua volta un sottoconto, e il piano dei conti non lo prevede:", "come capitolo padre di «{$sottoconto}»", "ogni modifica di «{$sottoconto}»"];
        $provento = 'se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno';
        if ($bloccano === []) {
            $esce = $terzoLivello && $perdono !== [] ? ' ' . self::fuoriDaiPiani($perdono, $sottoconto) : '';

            return $terzoLivello
                ? "{$sottoUnSottoconto} scegli prima {$comeSuoPadre} una voce di primo livello, poi correggi l'importo; {$provento}.{$esce}"
                : "Correggi l'importo {$diChi} nel piano dei conti: {$provento}.";
        }

        $lei = $sottoconto === null ? 'la voce' : 'il sottoconto';
        $premessa = "L'importo, il padre e il tipo {$diChi} non si cambiano finché " . BloccoDellaVoce::chi($bloccano, $lei) . ' ' . BloccoDellaVoce::nelPiano($bloccano);
        if (BloccoDellaVoce::fermi($bloccano) !== []) {
            return BloccoDellaVoce::frase($premessa, $bloccano, '', "{$lei} resta com'è");
        }

        return ($terzoLivello ? "{$sottoUnSottoconto} {$ogniModifica} chiede come capitolo padre una voce di primo livello. " : '')
            . BloccoDellaVoce::frase($premessa, $bloccano, ($terzoLivello ? 'scegli il capitolo padre e correggi l\'importo' : 'correggi l\'importo nel piano dei conti') . "; {$provento}", "{$lei} resta com'è");
    }

    /**
     * «Il nuovo padre porta «Rimborso» fuori da «Impianti», da cui oggi il piano «Preventivo B» lo addebita per € 300,00.»
     *
     * @param  list<array{id: int, nome: string, capitolo: string, cifra: int}>  $perdono
     */
    private static function fuoriDaiPiani(array $perdono, ?string $sottoconto): string
    {
        [$chi, $lo] = $sottoconto === null ? ['la voce', 'la'] : ["«{$sottoconto}»", 'lo'];
        $capitoli = SpeseNegativeNelPiano::elenco(array_map(fn (string $c) => "«{$c}»", array_values(array_unique(array_column($perdono, 'capitolo')))));
        $cifre = SpeseNegativeNelPiano::elenco(array_map(fn (array $p) => SpeseNegativeNelPiano::cifra($p['cifra']), $perdono));

        return "Il nuovo padre porta {$chi} fuori da {$capitoli}, da cui oggi "
            . (count($perdono) === 1 ? "il piano «{$perdono[0]['nome']}» {$lo} addebita" : 'i piani ' . self::nomi($perdono) . " {$lo} addebitano")
            . " per {$cifre}.";
    }

    /**
     * Le pagine a cui rimanda la frase di più piani: tutte se l'avviso c'è su tutte, altrimenti solo quelle con l'avviso, per nome; null se nessuna.
     *
     * @param  list<array{id: int, nome: string, avviso: bool}>  $piani
     * @param  list<array{id: int, nome: string, avviso: bool}>  $conAvviso
     */
    private static function pagine(array $piani, array $conAvviso): ?string
    {
        return match (true) {
            $conAvviso === [] => null,
            count($conAvviso) === count($piani) => 'le pagine dei piani dicono che cosa fare',
            count($conAvviso) === 1 => "la pagina di «{$conAvviso[0]['nome']}» dice che cosa fare",
            default => 'le pagine di ' . self::nomi($conAvviso) . ' dicono che cosa fare',
        };
    }

    /**
     * @param  list<array{id: int, nome: string, avviso?: bool}>  $piani
     * @return list<array{id: int, nome: string, avviso?: bool}>
     */
    private static function conAvviso(array $piani): array
    {
        return array_values(array_filter($piani, fn (array $p) => ($p['avviso'] ?? false) === true));
    }

    /** ««A» e «B»», ««A», «B» e «C»». @param list<array{id: int, nome: string}> $piani */
    private static function nomi(array $piani): string
    {
        return SpeseNegativeNelPiano::elenco(array_map(fn (array $p) => "«{$p['nome']}»", $piani));
    }

    /**
     * Le voci date che stanno al terzo livello del piano dei conti, o più in basso: il piano dei conti ne prevede due, e la modifica di una voce così si
     * salva solo con un capitolo padre di primo livello (`UpdateContoRequest`). Sono dati delle versioni fino alla 1.9.1.
     *
     * @param  list<int>  $contiIds
     * @return list<int>
     */
    public static function alTerzoLivello(array $contiIds): array
    {
        $contiIds = array_values(array_unique(array_map('intval', $contiIds)));

        return $contiIds === [] ? [] : array_keys(array_filter(Conto::catene($contiIds), fn (array $catena) => count($catena) >= 3));
    }

    /**
     * I piani in bozza che addebitano la voce con la riga di un capitolo sopra di lei che non è di primo livello, ed è la loro riga più in alto nella
     * catena. Una voce al terzo livello, o più in basso, si salva solo con un capitolo padre di primo livello (`UpdateContoRequest`), che la porta fuori da
     * quel capitolo. Non si conta un piano che la raggiunge da un capitolo di primo livello o che tiene anche la riga della voce. Un piano in uno stato
     * che blocca non c'è: finché la tiene, il padre della voce non cambia (`Conto::pianiCheBloccanoLaVoce`).
     *
     * @return list<array{id: int, nome: string, capitolo: string, cifra: int}> in ordine di id; `capitolo` è il capitolo della riga, `cifra` quanto il
     *                                                                          motore addebita oggi alla voce, in centesimi
     */
    public static function pianiCheLaPerdono(int $contoId): array
    {
        $catena = Conto::catene([$contoId])[$contoId] ?? [];
        $ultimo = count($catena) - 1;
        if ($ultimo < 2) {
            return [];
        }

        $righe = DB::table('piano_rate_capitoli')
            ->join('piani_rate', 'piano_rate_capitoli.piano_rate_id', '=', 'piani_rate.id')
            ->where('piani_rate.stato', 'bozza')
            ->whereIn('piano_rate_capitoli.conto_id', $catena)
            ->get(['piano_rate_capitoli.conto_id', 'piano_rate_capitoli.importo', 'piani_rate.id']);
        if ($righe->isEmpty()) {
            return [];
        }

        $copie = [];
        foreach ($righe as $riga) {
            $copie[(int) $riga->id][(int) $riga->conto_id] = $riga->importo === null ? null : (int) $riga->importo;
        }
        $conti = Conto::with(['tabelleMillesimali', 'sottoconti'])->whereIn('id', $catena)->get()->keyBy('id');

        $piani = [];
        foreach (PianoRate::whereIn('id', array_keys($copie))->orderBy('id')->get() as $piano) {
            $delPiano = $copie[(int) $piano->id];
            $piuInAlto = array_search(true, array_map(fn (int $id) => array_key_exists($id, $delPiano), $catena), true);
            $arrivo = self::arrivo($catena, $delPiano, $conti, $piano->created_at);
            if ($piuInAlto === 0 || array_key_exists($contoId, $delPiano) || $arrivo === null || $arrivo[1] !== $piuInAlto) {
                continue;
            }
            $cifra = abs($arrivo[0] ?? (int) $conti->get($contoId)?->importo);
            if ($cifra !== 0) {
                $piani[] = ['id' => (int) $piano->id, 'nome' => (string) $piano->nome, 'capitolo' => (string) $conti->get($catena[$piuInAlto])?->nome, 'cifra' => $cifra];
            }
        }

        return $piani;
    }

    /** Il motore raggiunge la voce (l'ultima della catena) e le ripartisce una cifra diversa da zero ({@see arrivo()}). */
    private static function laAddebita(array $catena, array $copie, Collection $conti, ?CarbonInterface $creatoIl): bool
    {
        $arrivo = self::arrivo($catena, $copie, $conti, $creatoIl);

        return $arrivo !== null && $arrivo[0] !== 0;
    }

    /**
     * Con che cosa il motore arriva alla voce (l'ultima della catena): la stessa discesa di `processaConti`.
     *
     * Si parte dalla riga del piano più in alto nella catena. Senza copia il motore ripartisce l'importo del capitolo e visita tutti i suoi
     * sottoconti, ciascuno con il suo importo o con la copia della sua riga. Con una copia, se il capitolo ha le sue tabelle la ripartisce lì e i
     * sottoconti non contano; altrimenti la divide fra i sottoconti nati fino alla creazione del piano (tutti quelli di adesso se nessuno lo è e la
     * copia è positiva), in proporzione al loro importo se la somma è positiva, in parti uguali se no, l'ultimo con il resto. Dove la discesa si
     * ferma, una riga più in basso nella catena riparte. Alla voce, la copia a zero non addebita niente; senza copia conta il suo importo, negativo.
     *
     * @param  list<int>  $catena  dalla radice alla voce
     * @param  array<int, ?int>  $copie  le righe del piano: conto => copia in centesimi, nulla se il piano legge l'importo
     * @param  Collection<int, Conto>  $conti
     * @return array{0: ?int, 1: int}|null la copia che arriva alla voce (nulla: il motore conta il suo importo) e la posizione nella catena della riga
     *                                     da cui viene; null se il motore non arriva alla voce
     */
    private static function arrivo(array $catena, array $copie, Collection $conti, ?CarbonInterface $creatoIl): ?array
    {
        $inDiscesa = false;
        $copia = null;
        $origine = 0;
        $ultimo = count($catena) - 1;
        foreach ($catena as $k => $id) {
            if (! $inDiscesa) {
                if (! array_key_exists($id, $copie)) {
                    continue;
                }
                $inDiscesa = true;
                $copia = $copie[$id];
                $origine = $k;
            } elseif ($copia === null && array_key_exists($id, $copie)) {
                $copia = $copie[$id];
                $origine = $k;
            }

            if ($k === $ultimo) {
                return [$copia, $origine];
            }
            if ($copia === null) {
                continue;
            }

            $conto = $conti->get($id);
            $figli = $conto === null ? collect() : self::figliDelPiano($conto, $copia, $creatoIl);
            $prossimo = $catena[$k + 1];
            if ($conto === null || $conto->tabelleMillesimali->isNotEmpty() || ! $figli->contains(fn (Conto $f) => (int) $f->id === $prossimo)) {
                $inDiscesa = false;
                $copia = null;

                continue;
            }
            $copia = self::quotaDelFiglio($copia, $figli, $prossimo);
        }

        return null;
    }

    /**
     * I sottoconti fra cui il motore divide la copia di un capitolo senza tabelle.
     *
     * @return Collection<int, Conto>
     */
    private static function figliDelPiano(Conto $conto, int $copia, ?CarbonInterface $creatoIl): Collection
    {
        $figli = $creatoIl === null
            ? $conto->sottoconti
            : $conto->sottoconti->filter(fn (Conto $s) => $s->created_at === null || $s->created_at->lte($creatoIl));

        return ($figli->isEmpty() && $copia > 0 ? $conto->sottoconti : $figli)->values();
    }

    /**
     * La parte della copia che il motore passa al sottoconto dato.
     *
     * @param  Collection<int, Conto>  $figli
     */
    private static function quotaDelFiglio(int $copia, Collection $figli, int $figlioId): int
    {
        $pesi = (int) $figli->sum(fn (Conto $f) => (int) $f->importo);
        $assegnata = 0;
        foreach ($figli as $i => $figlio) {
            $quota = match (true) {
                $i === $figli->count() - 1 => $copia - $assegnata,
                $pesi > 0 => (int) round($copia * ((int) $figlio->importo / $pesi)),
                default => (int) round($copia / $figli->count()),
            };
            if ((int) $figlio->id === $figlioId) {
                return $quota;
            }
            $assegnata += $quota;
        }

        return 0;
    }
}
