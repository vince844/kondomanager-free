<?php

namespace App\Services\Gestionale;

use App\Models\Condominio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Il saldo dell'estratto conto della banca, scritto dall'amministratore nella nota sintetica per ogni conto della banca del condominio (le casse di tipo
 * banca), contro il saldo dello stesso conto nella contabilità alla stessa data. Il programma dice le due cifre e se coincidono o di quanto sono diverse;
 * non dice mai che la differenza è un errore (un assegno non ancora incassato, una commissione non ancora registrata: lo sa l'amministratore, che lo
 * spiega accanto).
 *
 * UNA FONTE. Il saldo della contabilità è quello che la situazione patrimoniale e la liquidità leggono: il saldo del conto della cassa nella fotografia
 * del giornale alla data ({@see StatoPatrimonialeService::calcola()}), più il saldo di apertura della cassa non ancora registrato a giornale (la stessa
 * cifra che la situazione mostra come «Saldi di apertura non ancora registrati a giornale»). Alla data della situazione la fotografia è quella della
 * pagina, già letta; a un'altra data (l'estratto di un esercizio aperto, alla fine dell'anno) si legge quella.
 *
 * I CONTI. Tutte le casse di tipo banca con il loro conto; una cassa non più attiva, senza saldo in contabilità e senza un estratto scritto, no: non c'è
 * niente da confrontare. Un conto attivo senza movimenti sì: l'estratto a zero coincide.
 *
 * I FONDI. Un fondo è una partizione del conto corrente, non denaro altrove ({@see SaldoCassaService}, {@see RiportoDiCassa}): un accantonamento abbassa il
 * conto della banca in contabilità e alza quello del fondo, e sull'estratto il denaro è sempre lì. Dalla parte della contabilità: con **un conto in banca
 * solo**, il conto e tutte le partizioni (i fondi e le casse virtuali), ognuna con il suo saldo dalla stessa fotografia e il saldo di apertura non ancora a
 * giornale; con **più conti**, il conto e gli accantonamenti partiti da lui (la lettura del registro di contabilità, che non conta un accantonamento come
 * un'uscita), perché il programma non scrive in quale banca sta un fondo. Un fondo aperto con un saldo suo, con più conti, non ha una banca: lo dice la
 * causa «i fondi» ({@see PisteDellaBanca}). La frase dice le due parti.
 */
final class SaldiDelleBanche
{
    public function __construct(private readonly StatoPatrimonialeService $statoPatrimoniale) {}

    /**
     * Per ogni conto della banca: la cassa, la data (dell'estratto, o della situazione se l'estratto non è scritto), il saldo dell'estratto (null se non
     * è scritto), quello della contabilità (sul conto, nei fondi, in tutto), la differenza (estratto − contabilità), l'esito e la spiegazione
     * dell'amministratore. `fondi` dice quali fondi la contabilità conta: «tutti» con un conto solo, «accantonati» con più conti.
     *
     * @param  array<string, array{data:?string, saldo:?int, spiegazione:string}>  $banca  dalla nota, per cassa
     * @param  array<string,mixed>  $pagina  la situazione patrimoniale già letta ({@see StatoPatrimonialePaginaService::costruisci()})
     * @return list<array{cassa_id:int, cassa:string, data:string, estratto:?int, sul_conto:int, nei_fondi:int, contabilita:int, fondi:string, differenza:?int, esito:string, spiegazione:string}>
     */
    public function confronto(Condominio $condominio, array $banca, array $pagina): array
    {
        $tutte = DB::table('casse')->where('condominio_id', $condominio->id)->whereNotNull('conto_contabile_id')
            ->orderBy('id')->get(['id', 'nome', 'tipo', 'conto_contabile_id', 'attiva', 'saldo_iniziale']);
        $partizioni = $tutte->filter(fn ($c) => in_array($c->tipo, RiportoDiCassa::CASSE_PARTIZIONE, true));
        $allaPagina = (string) $pagina['data'];
        $voci = [$allaPagina => self::saldiDellaPagina($pagina)];
        $conti = [];
        foreach ($tutte->where('tipo', 'banca') as $c) {
            $scritto = $banca[(string) $c->id] ?? null;
            $estratto = $scritto === null ? null : ($scritto['saldo'] ?? null);
            $data = $estratto !== null && ! empty($scritto['data']) ? (string) $scritto['data'] : $allaPagina;
            $voci[$data] ??= collect($this->statoPatrimoniale->calcola($condominio, allaData: $data)['attivo']['voci'])
                ->mapWithKeys(fn ($v) => [(int) $v['id'] => (int) $v['saldo']])->all();
            $sulConto = (int) ($voci[$data][(int) $c->conto_contabile_id] ?? 0) + (int) $c->saldo_iniziale;
            if (! self::contaIlConto((bool) $c->attiva, $sulConto, $estratto !== null)) {
                continue;
            }
            $conti[] = [$c, $scritto, $estratto, $data, $sulConto];
        }
        $unico = count($conti) === 1;
        $righe = [];
        foreach ($conti as [$c, $scritto, $estratto, $data, $sulConto]) {
            $neiFondi = $unico
                ? (int) $partizioni->sum(fn ($p) => (int) ($voci[$data][(int) $p->conto_contabile_id] ?? 0) + (int) $p->saldo_iniziale)
                : self::accantonatiDa($condominio, (int) $c->conto_contabile_id, $data);
            $contabilita = $sulConto + $neiFondi;
            $differenza = $estratto === null ? null : (int) $estratto - $contabilita;
            $righe[] = ['cassa_id' => (int) $c->id, 'cassa' => (string) $c->nome, 'data' => $data, 'estratto' => $estratto === null ? null : (int) $estratto,
                'sul_conto' => $sulConto, 'nei_fondi' => $neiFondi, 'contabilita' => $contabilita, 'fondi' => $unico ? 'tutti' : 'accantonati',
                'differenza' => $differenza,
                'esito' => $differenza === null ? 'non_calcolato' : ($differenza === 0 ? 'quadra' : 'segnalazione'),
                'spiegazione' => (string) ($scritto['spiegazione'] ?? '')];
        }

        return $righe;
    }

    /**
     * Un conto della banca conta (qui, e nella scheda «Cassa» per decidere se il conto in banca è uno solo) se è attivo, se ha ancora un saldo o se il
     * suo estratto è scritto: una banca chiusa e svuotata non ha niente da confrontare, e non tiene i fondi.
     */
    public static function contaIlConto(bool $attiva, int $sulConto, bool $conEstratto): bool
    {
        return $attiva || $sulConto !== 0 || $conEstratto;
    }

    /**
     * Gli accantonamenti partiti da un conto della banca, fino al giorno, ancora nei fondi: le righe del conto nelle registrazioni che hanno una riga
     * sorella su una partizione (un giroconto banca→fondo, il suo ritorno, il suo storno), con il segno girato. È il filtro del registro di contabilità
     * ({@see RiportoDiCassa::senzaAccantonamenti()}), al contrario.
     */
    public static function accantonatiDa(Condominio $condominio, int $conto, string $alla): int
    {
        $dopo = CarbonImmutable::parse($alla)->addDay()->format('Y-m-d');

        return -(int) DB::table('righe_scritture as rs')->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
            ->where('sc.condominio_id', $condominio->id)->whereNull('sc.deleted_at')->where('rs.conto_contabile_id', $conto)->where('sc.data_competenza', '<', $dopo)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('righe_scritture as rs2')->join('casse as ca2', 'ca2.id', '=', 'rs2.cassa_id')
                ->whereColumn('rs2.scrittura_id', 'rs.scrittura_id')->whereColumn('rs2.id', '!=', 'rs.id')->whereIn('ca2.tipo', RiportoDiCassa::CASSE_PARTIZIONE))
            ->sum(DB::raw("CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END"));
    }

    /** I saldi dei conti di liquidità nella fotografia della situazione, per conto. */
    private static function saldiDellaPagina(array $pagina): array
    {
        $saldi = [];
        foreach ($pagina['situazione']['attivo']['gruppi'] ?? [] as $gruppo) {
            foreach ($gruppo['voci'] as $v) {
                $saldi[(int) $v['id']] = (int) $v['saldo'];
            }
        }

        return $saldi;
    }

    /**
     * La frase di un conto per la nota: le due cifre, e «coincidono» o la differenza con il suo verso. Nessun giudizio.
     */
    public static function frase(array $r): string
    {
        $cifra = fn (int $c) => ($c < 0 ? 'sotto zero di ' : '').FattiDellaNota::euro($c);
        // Con i fondi, la contabilità dice le sue due parti: il conto e i fondi, che stanno sullo stesso conto corrente.
        $parti = (int) ($r['nei_fondi'] ?? 0) === 0 ? '' : ' ('.$cifra((int) $r['sul_conto']).' sul conto e '.$cifra((int) $r['nei_fondi']).' nei fondi)';
        $inizio = 'Il conto «'.$r['cassa'].'» al '.CarbonImmutable::parse($r['data'])->format('d/m/Y').': secondo la banca (dal suo estratto conto) '.$cifra((int) $r['estratto'])
            .', secondo la contabilità '.$cifra((int) $r['contabilita']).$parti;

        return $r['differenza'] === 0 ? $inizio.': coincidono.'
            : $inizio.': la differenza è '.FattiDellaNota::euro((int) $r['differenza']).', in più '.($r['differenza'] > 0 ? 'secondo la banca' : 'nella contabilità').'.';
    }

    /** Il condominio senza un conto in banca: il controllo torna, perché non c'è niente da confrontare, e lo dice. Mai «non calcolato» per sempre. */
    public const FRASE_SENZA_CONTI = 'Nella contabilità del condominio non c\'è un conto in banca (una cassa di tipo banca): non c\'è un saldo della banca da confrontare.';

    /** Il rimedio di un saldo non scritto, e quello di una differenza: la pagina li dice all'amministratore; la stampa ha le sue parole ({@see ParoleDellaStampa}). */
    public const RIMEDIO_SCRIVI = 'Scrivi nella nota sintetica il saldo che leggi sull\'estratto conto che ti manda la banca.';

    public const RIMEDIO_CAUSE = 'Qui sotto le possibili cause che il programma trova nei movimenti, ognuna con la correzione consigliata: la confermi tu, o la ignori con una nota.';

    /**
     * Il controllo «Il saldo della banca e la contabilità», nella forma dei controlli del rendiconto ({@see ControlliDelRendiconto}): «torna» se ogni conto
     * scritto coincide e nessuno manca, o se il condominio non ha un conto in banca (niente da confrontare, e la frase lo dice); «da leggere» con la
     * differenza se un conto non coincide; «non calcolato» se il saldo della banca non è scritto. `conti` e `scritti` dicono quanti conti ci sono e di
     * quanti è scritto l'estratto: le pagine e la stampa li dicono al posto di due cifre a zero.
     *
     * @param  list<array<string,mixed>>  $righe  {@see confronto()}
     */
    public static function controllo(array $righe): array
    {
        $scritti = array_values(array_filter($righe, fn ($r) => $r['estratto'] !== null));
        $diversi = array_values(array_filter($scritti, fn ($r) => $r['differenza'] !== 0));
        $mancano = array_values(array_filter($righe, fn ($r) => $r['estratto'] === null));
        $nomi = fn (array $rr) => implode(', ', array_map(fn ($r) => '«'.$r['cassa'].'»', $rr));
        $esito = match (true) {
            $diversi !== [] => 'segnalazione',
            $mancano !== [] => 'non_calcolato',
            default => 'quadra',
        };
        $nota = match (true) {
            $righe === [] => self::FRASE_SENZA_CONTI,
            $diversi !== [] => implode(' ', array_map(fn ($r) => self::frase($r).($r['spiegazione'] !== '' ? ' '.$r['spiegazione'] : ''), $diversi)),
            $mancano !== [] => 'Il saldo che dice la banca non è scritto nella nota sintetica per '.$nomi($mancano).': il confronto con il saldo registrato in KondoManager '
                .'alla stessa data non si fa.',
            default => 'Il saldo che dice la banca e il saldo registrato in KondoManager coincidono per '.$nomi($scritti).'.',
        };
        $rimedio = match (true) {
            $mancano !== [] && $diversi === [] => self::RIMEDIO_SCRIVI,
            $diversi !== [] => self::RIMEDIO_CAUSE,
            default => null,
        };

        return ['id' => 'BC', 'nome' => 'Il saldo della banca e la contabilità', 'titolo' => 'Il saldo che dice la banca, confrontato con il saldo registrato in KondoManager alla stessa data',
            'sinistra' => (int) array_sum(array_column($scritti, 'estratto')), 'destra' => (int) array_sum(array_column($scritti, 'contabilita')), 'esito' => $esito,
            // Due cifre diverse non si scrivono con «=», anche se il controllo è da leggere e non «non torna».
            'segno' => $diversi === [] ? '=' : '≠',
            'nota' => $nota, 'rimedio' => $rimedio, 'azione' => $righe === [] ? null : 'nota', 'conti' => count($righe), 'scritti' => count($scritti)];
    }
}
