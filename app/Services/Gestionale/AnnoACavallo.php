<?php

namespace App\Services\Gestionale;

use App\Models\Condominio;
use App\Models\Esercizio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Le registrazioni oltre il confine dell'esercizio, nei due versi, sulla data del fatto (`data_competenza`: la data del documento, dell'incasso, del
 * pagamento, la stessa su cui tagliano la situazione patrimoniale e la lettura dei debiti), del condominio, non cancellate, senza le aperture:
 * - **verso A**, scritte in questo esercizio con la data fuori dal suo periodo;
 * - **verso B**, con la data in questo esercizio e scritte in un altro.
 * La stessa registrazione sta nel verso A della pagina del suo esercizio e nel verso B di quella dell'esercizio della sua data.
 *
 * IL COLORE. Rosso ciò che cambia che cosa il riepilogo per competenza conta: le fatture e le note di credito, le emissioni delle rate, ogni scrittura
 * con una riga su un conto di costo o di ricavo (le regolazioni, gli incassi da terzi, le commissioni di un pagamento) o sulle quote emesse. «Da leggere»
 * il denaro che attraversa il confine senza cambiare la competenza: gli incassi delle rate, i pagamenti senza commissione, i versamenti F24, i giroconti,
 * i rimborsi e i loro storni. Si decide dalle righe della scrittura, non dal nome del tipo.
 * UNA FATTURA REGISTRATA COME PREGRESSA ha due colori. Vista dalla pagina dell'esercizio in cui sta (verso A) è «da leggere»: scrive sulle passate
 * gestioni, e quell'esercizio non la conta come costo. Vista dalla pagina dell'esercizio della sua data (verso B) è rossa: lì cambia la competenza. Se
 * scrive anche un costo vero (la parte non coperta dai saldi iniziali, una sopravvenienza passiva) l'esercizio in cui sta lo conta, e la riga è rossa
 * anche nel verso A. Il segno «pregressa» sulla riga c'è in tutti e due i versi.
 *
 * NIENTE DA CORREGGERE QUI. Finché la chiusura guidata dell'esercizio non c'è, ogni registrazione prende l'esercizio aperto, non quello della sua data:
 * l'elenco dice che cosa sta dove e che effetto ha sul riepilogo; la regola che sposterà le registrazioni arriva con la chiusura. Il rimedio si dice
 * dallo stato vero degli esercizi (quale era aperto quando le registrazioni sono state scritte, se questo è ancora aperto) e dalle regole del versamento
 * F24 e dello storno di un incasso, non dal verso; una fattura registrata come pregressa non ha un rimedio: la sua riga dice che nessun riepilogo la conta fra i costi,
 * tranne la parte non coperta dai saldi iniziali, se c'è.
 *
 * FUORI DA OGNI ESERCIZIO. Quando le registrazioni dell'esercizio con una data che non sta in nessun esercizio del condominio (senza le aperture e le
 * pregresse, che hanno la data prima dell'esercizio per costruzione) sono più di quelle con la data nel suo periodo, il programma non sa quale delle due
 * cause ha davanti: le date dell'esercizio sbagliate (un esercizio «2025» creato per le operazioni del 2026) o le registrazioni di un esercizio che nel
 * programma non c'è. Dice i numeri e le due cause, con l'azione «Modifica l'esercizio» per la prima; e R2 non toglie quelle registrazioni dal confronto.
 * Un esercizio tenuto aperto oltre la sua fine ha il suo anno di registrazioni nel periodo: lì la frase non esce, anche se l'esercizio dopo non c'è.
 */
final class AnnoACavallo
{
    /** Le righe dell'elenco per pagina, se chi lavora non ne ha scelte altre; la stampa ha le prime. */
    public const RIGHE_ELENCO = 50;

    /** Le categorie della tabella per tipo, nell'ordine in cui si mostrano. */
    public const CATEGORIE = [
        'fattura' => 'Fatture',
        'fattura_pregressa' => 'Fatture registrate come pregresse',
        'nota_credito' => 'Note di credito',
        'emissione' => 'Emissioni delle rate',
        'regolazione' => 'Regolazioni immediate',
        'incasso_da_terzi' => 'Incassi da terzi',
        'incasso' => 'Incassi delle rate',
        'pagamento' => 'Pagamenti ai fornitori',
        'f24' => 'Versamenti F24',
        'giroconto' => 'Giroconti e accantonamenti',
        'rimborso' => 'Rimborsi ai condòmini',
        'storno' => 'Storni',
        'altro' => 'Altre registrazioni',
    ];

    /** Detto una volta, in testa: perché il confine si attraversa quasi ogni anno. */
    public const FRASE_NORMA = 'Finché non c\'è la chiusura guidata dell\'esercizio (in arrivo), una registrazione va nell\'esercizio aperto quando la scrivi, non in quello della sua data '
        .'(un versamento F24 va in quello della sua delega, lo storno di un incasso in quello dell\'incasso): '
        .'registrazioni oltre il confine ci saranno quasi ogni anno, e l\'elenco dice dove stanno. La regola che le sposterà arriva con la chiusura.';

    /** Scritte qui con la data di un altro esercizio (non pregresse, non F24): stanno qui perché questo era l'esercizio aperto quando sono state scritte. */
    public const RIMEDIO_QUI = 'Le registrazioni scritte qui con la data di un altro esercizio stanno in questo perché, quando sono state scritte, l\'esercizio aperto '
        .'era questo; restano qui fino alla chiusura guidata, che le potrà riportare.';

    /** In più, quando questo esercizio è ancora aperto e qualcuna ha la data dopo la sua fine. */
    public const RIMEDIO_QUI_APERTO = 'Dal giorno in cui chiudi questo esercizio e apri il successivo, le nuove vanno lì.';

    /** Con la data di questo esercizio e scritte in un altro (non F24): stanno lì perché quello era l'esercizio aperto quando sono state scritte. */
    public const RIMEDIO_ALTRO = 'Le registrazioni con la data di questo esercizio scritte in un altro stanno lì perché, quando sono state scritte, l\'esercizio aperto '
        .'era quello; restano lì fino alla chiusura guidata, che le potrà riportare.';

    /** Il versamento F24 sta nell'esercizio della sua delega, qualunque sia la data e qualunque esercizio fosse aperto. */
    public const RIMEDIO_F24 = 'I versamenti F24 stanno nell\'esercizio della loro delega, anche con la data di un altro: è la regola fino alla chiusura guidata.';

    /**
     * Lo storno di un incasso o di un rimborso, e la figlia dell'emissione che lo storno scrive per il credito restituito, stanno nell'esercizio
     * dell'originale (anche chiuso), con la data dello storno: non nell'esercizio aperto.
     */
    public const RIMEDIO_STORNO_INCASSO = 'Lo storno di un incasso o di un rimborso sta nell\'esercizio dell\'incasso o del rimborso che storna, anche con la data di un altro: '
        .'è la regola fino alla chiusura guidata.';

    /** Le due cause, quando le registrazioni fuori da ogni esercizio sono più di quelle nel periodo. */
    public const RIMEDIO_FUORI = 'Se le date dell\'esercizio sono sbagliate, correggile con «Modifica l\'esercizio». Se sono registrazioni di un esercizio che nel '
        .'programma non c\'è, restano qui fino alla chiusura guidata; se è l\'esercizio dopo, le nuove vanno lì dal giorno in cui chiudi questo e apri il successivo.';

    /**
     * La riga di una fattura registrata come pregressa: non è un costo né dell'esercizio in cui sta né di quello della sua data (con un esercizio solo,
     * nemmeno di quello). La parte non coperta dai saldi iniziali (la sopravvenienza passiva) sì: è un costo dell'esercizio in cui sta, e la nota lo dice.
     */
    public const NOTA_PREGRESSA = 'registrata come pregressa: nessun riepilogo la conta fra i costi';

    /**
     * Il controllo della pagina dell'esercizio: i conteggi per verso, l'effetto sul riepilogo, la tabella per tipo e una pagina dell'elenco con i filtri
     * (`verso` A o B, `tipo` una categoria, `colore` rosso o da_leggere). Una pagina oltre l'ultima è vuota, come nelle altre liste; un numero enorme
     * scritto a mano nell'indirizzo non è un errore.
     *
     * @param  array{verso?:?string, tipo?:?string, colore?:?string}  $filtri
     * @return array<string,mixed>
     */
    public function perEsercizio(Condominio $condominio, Esercizio $esercizio, array $filtri = [], int $pagina = 1, int $perPagina = self::RIGHE_ELENCO): array
    {
        $dal = $esercizio->data_inizio->format('Y-m-d');
        $fine = $esercizio->data_fine->format('Y-m-d');
        $esercizi = $this->esercizi($condominio);
        $righe = $this->righe($condominio, $esercizio, $esercizi);
        $fuori = $this->fuoriDaOgniEsercizio($esercizio, $righe);

        $versi = ['A' => ['numero' => 0, 'rosse' => 0, 'da_leggere' => 0], 'B' => ['numero' => 0, 'rosse' => 0, 'da_leggere' => 0]];
        $vuoto = ['quote' => 0, 'costi' => 0, 'entrate' => 0, 'pregresse' => 0, 'pregresse_costi' => 0, 'anni' => []];
        $effetto = ['A' => $vuoto, 'B' => $vuoto];
        $perTipo = [];
        foreach ($righe as $r) {
            $v = $r['verso'];
            $versi[$v]['numero']++;
            $versi[$v][$r['rossa'] ? 'rosse' : 'da_leggere']++;
            $effetto[$v]['quote'] += $r['quote'];
            $effetto[$v]['costi'] += $r['costi'];
            $effetto[$v]['entrate'] += $r['entrate'];
            $effetto[$v]['pregresse'] += $r['categoria'] === 'fattura_pregressa' ? $r['importo'] : 0;
            // La parte non coperta di una pregressa: un costo dentro il suo importo, che la frase del verso B non deve contare due volte.
            $effetto[$v]['pregresse_costi'] += $r['categoria'] === 'fattura_pregressa' ? $r['costi'] : 0;
            $effetto[$v]['anni'][substr($r['data'], 0, 4)] = true;
            $k = $v.'|'.$r['categoria'];
            $perTipo[$k] ??= ['verso' => $v, 'tipo' => $r['categoria'], 'etichetta' => self::CATEGORIE[$r['categoria']], 'numero' => 0, 'importo' => 0, 'rosse' => 0];
            $perTipo[$k]['numero']++;
            $perTipo[$k]['importo'] += $r['importo'];
            $perTipo[$k]['rosse'] += $r['rossa'] ? 1 : 0;
        }
        $ordine = array_flip(array_keys(self::CATEGORIE));
        usort($perTipo, fn ($a, $b) => [$a['verso'], $ordine[$a['tipo']]] <=> [$b['verso'], $ordine[$b['tipo']]]);

        $filtri = [
            'verso' => in_array($filtri['verso'] ?? null, ['A', 'B'], true) ? $filtri['verso'] : null,
            'tipo' => isset(self::CATEGORIE[$filtri['tipo'] ?? '']) ? $filtri['tipo'] : null,
            'colore' => in_array($filtri['colore'] ?? null, ['rosso', 'da_leggere'], true) ? $filtri['colore'] : null,
        ];
        $filtrate = array_values(array_filter($righe, fn ($r) => ($filtri['verso'] === null || $r['verso'] === $filtri['verso'])
            && ($filtri['tipo'] === null || $r['categoria'] === $filtri['tipo'])
            && ($filtri['colore'] === null || ($filtri['colore'] === 'rosso') === $r['rossa'])));

        $rosse = $versi['A']['rosse'] + $versi['B']['rosse'];
        $numero = $versi['A']['numero'] + $versi['B']['numero'];
        $perPagina = max(1, $perPagina);
        $ultima = max(1, intdiv(count($filtrate) + $perPagina - 1, $perPagina));
        // Oltre l'ultima, la pagina vuota subito dopo (come le altre liste): un numero enorme scritto a mano non arriva al calcolo dello scarto.
        $pagina = max(1, min($pagina, $ultima + 1));
        $mostrate = array_slice($filtrate, ($pagina - 1) * $perPagina, $perPagina);

        return [
            'dal' => $dal,
            'al' => $fine,
            'numero' => $numero,
            'rosse' => $rosse,
            'da_leggere' => $numero - $rosse,
            'versi' => $versi,
            'effetto' => [
                'A' => array_diff_key($effetto['A'], ['anni' => 1, 'pregresse_costi' => 1]) + ['frase' => $this->fraseEffettoA($effetto['A'])],
                'B' => array_diff_key($effetto['B'], ['anni' => 1, 'pregresse_costi' => 1]) + ['frase' => $this->fraseEffettoB($effetto['B'])],
            ],
            'per_tipo' => array_values($perTipo),
            'filtri' => $filtri,
            'righe_filtrate' => count($filtrate),
            'righe' => array_map(fn ($r) => array_diff_key($r, ['quote' => 1, 'costi' => 1, 'entrate' => 1, 'cassa' => 1, 'storno_incasso' => 1]), $mostrate),
            'pagine' => ['current_page' => $pagina, 'last_page' => $ultima, 'per_page' => $perPagina, 'total' => count($filtrate),
                'from' => $mostrate === [] ? null : ($pagina - 1) * $perPagina + 1, 'to' => $mostrate === [] ? null : ($pagina - 1) * $perPagina + count($mostrate), 'nome' => 'pagina_confine'],
            'fuori_da_ogni_esercizio' => $fuori,
            'frase_norma' => self::FRASE_NORMA,
            'rimedi' => $this->rimedi($righe, $esercizio, $fuori['prevale']),
        ];
    }

    /**
     * I rimedi, dallo stato vero degli esercizi e non dal verso: le due cause quando le registrazioni fuori da ogni esercizio prevalgono; le scritte qui
     * con la data di un altro esercizio (qui perché questo era aperto quando sono state scritte), con il «chiudi e apri il successivo» solo se questo è
     * ancora aperto e qualcuna ha la data dopo la sua fine; le scritte in un altro con la data di questo (lì perché quello era aperto); i versamenti F24,
     * che seguono la delega, e gli storni degli incassi, che seguono l'incasso. Le pregresse non hanno un rimedio.
     *
     * @param  list<array<string,mixed>>  $righe
     * @return list<string>
     */
    private function rimedi(array $righe, Esercizio $esercizio, bool $prevale): array
    {
        $fine = $esercizio->data_fine->format('Y-m-d');
        $qui = $dopo = $altro = $f24 = $storno = 0;
        foreach ($righe as $r) {
            if ($r['categoria'] === 'f24') {
                $f24++;
            } elseif ($r['storno_incasso']) {
                $storno++;
            } elseif ($r['verso'] === 'B') {
                $altro++;
            } elseif ($r['categoria'] !== 'fattura_pregressa' && ! ($prevale && $r['senza_esercizio'])) {
                $qui++;
                $dopo += $r['data'] > $fine ? 1 : 0;
            }
        }

        return array_values(array_filter([
            $prevale ? self::RIMEDIO_FUORI : null,
            $qui > 0 ? self::RIMEDIO_QUI.($dopo > 0 && $esercizio->stato === 'aperto' ? ' '.self::RIMEDIO_QUI_APERTO : '') : null,
            $altro > 0 ? self::RIMEDIO_ALTRO : null,
            $storno > 0 ? self::RIMEDIO_STORNO_INCASSO : null,
            $f24 > 0 ? self::RIMEDIO_F24 : null,
        ]));
    }

    /**
     * Le righe di cassa che attraversano la data di fine, per il confronto fra la liquidità della situazione (per data) e la disponibilità finale del
     * prospetto per cassa (per esercizio): quelle di questo esercizio con la data dopo la fine (nel prospetto, non nella situazione) e quelle di un
     * esercizio dopo con la data entro la data della situazione (nella situazione, non nel prospetto). Quando le registrazioni fuori da ogni esercizio
     * prevalgono su quelle nel periodo, quelle non si tolgono: il programma non sa se attraversano un confine o se è il confine a essere sbagliato.
     *
     * @return array{dopo_la_fine: array{netto:int, lordo:int, numero:int}, di_un_esercizio_dopo: array{netto:int, lordo:int, numero:int}, fuori_da_ogni_esercizio: array{fuori:int, nel_periodo:int, prevale:bool}}
     */
    public function perLaCassa(Condominio $condominio, Esercizio $esercizio, string $al): array
    {
        $vuoto = ['netto' => 0, 'lordo' => 0, 'numero' => 0];
        $righe = $this->righe($condominio, $esercizio, $this->esercizi($condominio));
        $fuori = $this->fuoriDaOgniEsercizio($esercizio, $righe);
        $fine = $esercizio->data_fine->format('Y-m-d');
        $dal = $esercizio->data_inizio->format('Y-m-d');
        $gruppi = ['dopo_la_fine' => $vuoto, 'di_un_esercizio_dopo' => $vuoto];
        foreach ($righe as $r) {
            if ($r['cassa'] === 0 || ($fuori['prevale'] && $r['senza_esercizio'])) {
                continue;
            }
            $gruppo = match (true) {
                $r['verso'] === 'A' && $r['data'] > $fine => 'dopo_la_fine',
                $r['verso'] === 'B' && $r['esercizio_dal'] > $dal && $r['data'] <= $al => 'di_un_esercizio_dopo',
                default => null,
            };
            if ($gruppo !== null) {
                $gruppi[$gruppo]['netto'] += $r['cassa'];
                $gruppi[$gruppo]['lordo'] += abs($r['cassa']);
                $gruppi[$gruppo]['numero']++;
            }
        }

        return $gruppi + ['fuori_da_ogni_esercizio' => $fuori];
    }

    /** Gli esercizi del condominio: id => [dal, al, nome]. */
    private function esercizi(Condominio $condominio): array
    {
        return DB::table('esercizi')->where('condominio_id', $condominio->id)->get(['id', 'nome', 'data_inizio', 'data_fine'])
            ->mapWithKeys(fn ($e) => [(int) $e->id => ['dal' => substr((string) $e->data_inizio, 0, 10), 'al' => substr((string) $e->data_fine, 0, 10), 'nome' => (string) $e->nome]])
            ->all();
    }

    /**
     * Le registrazioni oltre il confine, una per scrittura, in ordine di data: il verso, l'importo (la somma dei DARE), le quote e i costi che porta,
     * l'effetto sulle casse, il colore, la categoria, l'esercizio in cui sta e la nota della riga.
     *
     * @return list<array<string,mixed>>
     */
    private function righe(Condominio $condominio, Esercizio $esercizio, array $esercizi): array
    {
        $dal = $esercizio->data_inizio->format('Y-m-d');
        $dopo = CarbonImmutable::parse($esercizio->data_fine->format('Y-m-d'), 'UTC')->addDay()->format('Y-m-d');
        $n = (int) $esercizio->id;
        $contiCassa = DB::table('casse')->where('condominio_id', $condominio->id)->whereNotNull('conto_contabile_id')->pluck('conto_contabile_id')
            ->map(fn ($id) => (int) $id)->unique()->values()->all() ?: [0];
        $inCassa = implode(',', $contiCassa);
        $netto = "(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END)";

        $diSpesa = "'".implode("','", \App\Models\Gestionale\Conto::TIPI_DI_SPESA)."'";
        // Una riga di costo sulla voce di un'entrata (l'incasso da terzi, il suo storno) è un'entrata, non un costo negativo.
        $entrata = "cc.tipo = 'costo' AND vo.id IS NOT NULL AND vo.tipo NOT IN ({$diSpesa})";

        $righe = DB::table('scritture_contabili as sc')
            ->join('righe_scritture as rs', 'rs.scrittura_id', '=', 'sc.id')
            ->leftJoin('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
            ->leftJoin('conti as vo', 'vo.id', '=', 'rs.voce_spesa_id')
            ->where('sc.condominio_id', $condominio->id)
            ->whereNull('sc.deleted_at')
            ->where('sc.tipo_movimento', '<>', 'apertura')
            ->where(fn ($q) => $q
                ->where(fn ($a) => $a->where('sc.esercizio_id', $n)->where(fn ($d) => $d->where('sc.data_competenza', '<', $dal)->orWhere('sc.data_competenza', '>=', $dopo)))
                ->orWhere(fn ($b) => $b->where('sc.esercizio_id', '<>', $n)->where('sc.data_competenza', '>=', $dal)->where('sc.data_competenza', '<', $dopo)))
            ->groupBy('sc.id', 'sc.esercizio_id', 'sc.data_competenza', 'sc.numero_protocollo', 'sc.tipo_movimento', 'sc.causale', 'sc.scrittura_padre_id')
            ->orderBy('sc.data_competenza')->orderBy('sc.id')
            ->select(['sc.id', 'sc.esercizio_id', 'sc.data_competenza', 'sc.numero_protocollo', 'sc.tipo_movimento', 'sc.causale', 'sc.scrittura_padre_id'])
            ->selectRaw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE 0 END), 0) as importo")
            ->selectRaw("COALESCE(SUM(CASE WHEN cc.ruolo = 'gestione_rate' THEN -{$netto} ELSE 0 END), 0) as quote")
            ->selectRaw("COALESCE(SUM(CASE WHEN cc.tipo = 'costo' AND NOT ({$entrata}) THEN {$netto} ELSE 0 END), 0) as costi")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$entrata} THEN -{$netto} ELSE 0 END), 0) as entrate")
            ->selectRaw("COALESCE(SUM(CASE WHEN cc.tipo IN ('costo', 'ricavo') OR cc.ruolo = 'gestione_rate' THEN 1 ELSE 0 END), 0) as economiche")
            ->selectRaw("COALESCE(SUM(CASE WHEN rs.conto_contabile_id IN ({$inCassa}) THEN {$netto} ELSE 0 END), 0) as cassa")
            ->selectRaw("(SELECT MAX(CASE WHEN fp.is_pregresso THEN 1 ELSE 0 END) FROM fattura_scrittura fs JOIN fatture_passive fp ON fp.id = fs.fattura_passiva_id WHERE fs.scrittura_contabile_id = sc.id AND fs.tipo = 'competenza') as pregressa")
            ->get();

        $ultimo = $esercizi === [] ? null : max(array_column($esercizi, 'al'));

        return $righe->map(function ($r) use ($n, $esercizi, $ultimo) {
            $data = substr((string) $r->data_competenza, 0, 10);
            $tipo = $r->tipo_movimento instanceof \BackedEnum ? $r->tipo_movimento->value : (string) $r->tipo_movimento;
            $fattura = $r->pregressa !== null;
            $categoria = $this->categoria($tipo, $fattura, (int) $r->pregressa === 1, $r->scrittura_padre_id !== null);
            $verso = (int) $r->esercizio_id === $n ? 'A' : 'B';
            $esercizioDellaData = $this->esercizioDi($data, $esercizi);
            $sta = $esercizi[(int) $r->esercizio_id] ?? ['dal' => '', 'al' => '', 'nome' => ''];

            return [
                'scrittura_id' => (int) $r->id,
                'verso' => $verso,
                'data' => $data,
                'esercizio_id' => (int) $r->esercizio_id,
                'esercizio' => $sta['nome'],
                'esercizio_dal' => $sta['dal'],
                'protocollo' => $r->numero_protocollo,
                'tipo' => $tipo,
                'categoria' => $categoria,
                'etichetta' => self::CATEGORIE[$categoria],
                'causale' => $r->causale,
                'importo' => (int) $r->importo,
                'quote' => (int) $r->quote,
                'costi' => (int) $r->costi,
                'entrate' => (int) $r->entrate,
                'cassa' => (int) $r->cassa,
                // Una fattura registrata come pregressa, vista dall'esercizio in cui sta, non cambia la competenza: scrive sulle passate gestioni (`economiche` è
                // zero) e resta da leggere. Se scrive un costo vero (la sopravvenienza della parte non coperta), `economiche` la tiene rossa.
                'rossa' => ($fattura && ! ($verso === 'A' && (int) $r->pregressa === 1)) || (int) $r->economiche > 0,
                'senza_esercizio' => $verso === 'A' && $esercizioDellaData === null,
                // Lo scrive lo storno di un incasso (o di un rimborso): la rettifica e la figlia dell'emissione, entrambe legate alla scrittura che storna.
                'storno_incasso' => $r->scrittura_padre_id !== null && in_array($tipo, ['rettifica', 'emissione_rata'], true),
                'nota' => $this->nota($categoria, $verso, $data, $sta, $esercizioDellaData !== null, self::nonAncoraCreato($data, $ultimo), (int) $r->costi),
            ];
        })->all();
    }

    /**
     * Le registrazioni dell'esercizio con una data che non sta in nessun esercizio del condominio, senza le pregresse (le aperture non sono fra le righe),
     * contro quelle con la data nel suo periodo, senza le aperture e le cancellate; «prevale» quando le prime sono di più.
     *
     * @return array{fuori:int, nel_periodo:int, prevale:bool}
     */
    private function fuoriDaOgniEsercizio(Esercizio $esercizio, array $righe): array
    {
        $fuori = count(array_filter($righe, fn ($r) => $r['senza_esercizio'] && $r['categoria'] !== 'fattura_pregressa'));
        $dopo = CarbonImmutable::parse($esercizio->data_fine->format('Y-m-d'), 'UTC')->addDay()->format('Y-m-d');
        $nelPeriodo = DB::table('scritture_contabili')->where('esercizio_id', $esercizio->id)->whereNull('deleted_at')
            ->where('tipo_movimento', '<>', 'apertura')->where('data_competenza', '>=', $esercizio->data_inizio->format('Y-m-d'))->where('data_competenza', '<', $dopo)->count();

        return ['fuori' => $fuori, 'nel_periodo' => $nelPeriodo, 'prevale' => $fuori > $nelPeriodo];
    }

    /** La data è dopo l'ultimo esercizio del condominio, entro un anno dalla sua fine: è l'anno dopo, non ancora creato. */
    private static function nonAncoraCreato(string $data, ?string $ultimo): bool
    {
        return $ultimo !== null && $data > $ultimo && $data <= CarbonImmutable::parse($ultimo, 'UTC')->addYear()->format('Y-m-d');
    }

    /** L'esercizio del condominio che contiene la data, o null. */
    private function esercizioDi(string $data, array $esercizi): ?int
    {
        foreach ($esercizi as $id => $e) {
            if ($e['dal'] <= $data && $data <= $e['al']) {
                return $id;
            }
        }

        return null;
    }

    /** La categoria della tabella per tipo. Lo storno di un incasso (`rettifica` con la scrittura che storna) sta con gli storni, come nei totali del registro; l'uso di un credito dentro un incasso sta con gli incassi delle rate. */
    private function categoria(string $tipo, bool $fattura, bool $pregressa, bool $haPadre): string
    {
        return match (true) {
            $pregressa => 'fattura_pregressa',
            $tipo === 'nota_credito_fornitore' => 'nota_credito',
            $tipo === 'fattura_acquisto' || ($fattura && ! str_starts_with($tipo, 'storno_')) => 'fattura',
            $tipo === 'emissione_rata' => 'emissione',
            $tipo === 'regolazione_immediata' => 'regolazione',
            $tipo === 'incasso_diverso' => 'incasso_da_terzi',
            $tipo === 'incasso_rata' || $tipo === 'storno_credito' => 'incasso',
            $tipo === 'rettifica' && $haPadre => 'storno',
            $tipo === 'pagamento_fornitore' => 'pagamento',
            $tipo === 'pagamento_f24' => 'f24',
            in_array($tipo, ['giroconto', 'accantonamento'], true) => 'giroconto',
            $tipo === 'rimborso_condomino' => 'rimborso',
            str_starts_with($tipo, 'storno_') => 'storno',
            default => 'altro',
        };
    }

    /** «del 2026» per un esercizio solare, «dell'esercizio «nome»» per gli altri. */
    private static function nomeEsercizio(array $e): string
    {
        $anno = substr($e['dal'], 0, 4);

        return $e['dal'] === $anno.'-01-01' && $e['al'] === $anno.'-12-31' ? 'del '.$anno : 'dell\'esercizio «'.$e['nome'].'»';
    }

    /**
     * La nota della riga. «In nessun esercizio» solo quando la data non è nemmeno dell'anno dopo l'ultimo esercizio (prima del primo, fra due esercizi, o
     * oltre l'anno dopo: un errore di battitura); con la data dell'anno dopo, non ancora creato, la fattura dice il suo anno.
     */
    private function nota(string $categoria, string $verso, string $data, array $sta, bool $haEsercizio, bool $nonAncoraCreato, int $costi): ?string
    {
        if ($categoria === 'fattura_pregressa') {
            return self::NOTA_PREGRESSA.($costi > 0 ? ', tranne la parte non coperta, '.self::euro($costi).', che è un costo dell\'esercizio in cui sta' : '');
        }
        if ($verso === 'A' && ! $haEsercizio && ! $nonAncoraCreato) {
            return 'la data non sta in nessun esercizio del condominio';
        }
        if ($categoria === 'fattura' || $categoria === 'nota_credito') {
            return 'costo '.self::nomeEsercizio($sta).' con la data del '.substr($data, 0, 4).($haEsercizio ? '' : ', un esercizio non ancora creato');
        }

        return null;
    }

    private static function euro(int $c): string
    {
        return '€ '.number_format($c / 100, 2, ',', '.');
    }

    private static function anni(array $anni): string
    {
        $a = array_keys($anni);
        sort($a);

        return 'del '.implode(' e del ', $a);
    }

    private function fraseEffettoA(array $e): ?string
    {
        $parti = array_filter([$e['quote'] !== 0 ? self::euro($e['quote']).' di quote' : null, $e['costi'] !== 0 ? self::euro($e['costi']).' di costi' : null,
            $e['entrate'] !== 0 ? self::euro($e['entrate']).' di entrate da terzi' : null]);
        if ($parti === []) {
            return null;
        }

        return 'Il riepilogo di questo esercizio contiene '.implode(' e ', $parti).' con la data '.self::anni($e['anni']).'.';
    }

    private function fraseEffettoB(array $e): ?string
    {
        // L'importo di una pregressa comprende già la sua parte non coperta, che è anche fra i costi delle righe.
        $costi = $e['costi'] - $e['pregresse_costi'] + $e['pregresse'];
        $parti = array_filter([$e['quote'] !== 0 ? self::euro($e['quote']).' di quote' : null, $costi !== 0 ? self::euro($costi).' di costi' : null,
            $e['entrate'] !== 0 ? self::euro($e['entrate']).' di entrate da terzi' : null]);
        if ($parti === []) {
            return null;
        }

        return 'Il riepilogo di questo esercizio non contiene '.implode(' e ', $parti).' con la data di questo esercizio, registrati in un altro'
            .($e['pregresse'] !== 0 ? ' (di cui '.self::euro($e['pregresse']).' registrati come pregresse: nessuno dei due riepiloghi li conta fra i costi'
                .($e['pregresse_costi'] > 0 ? ', tranne la parte non coperta, '.self::euro($e['pregresse_costi']).', che è un costo dell\'esercizio in cui stanno' : '').')' : '').'.';
    }
}
