<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Helpers\DateHelper;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\RendicontoVersione;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Il rendiconto di oggi contro una versione fermata per l'assemblea: dopo l'approvazione niente si blocca, e il programma dice che cosa è cambiato, con
 * le cifre di allora e di oggi. Se è un errore o una spesa sopravvenuta lo sa l'amministratore.
 *
 * DUE LETTURE. **Il giornale**: un'impronta del contenuto di ogni registrazione che le schede leggono (quelle dell'esercizio, per i costi, le quote, il
 * risultato, il registro e la cassa; quelle con la data nel periodo dell'esercizio scritte in un altro, per la situazione patrimoniale) e i saldi per conto
 * delle registrazioni con la data prima dell'esercizio (la situazione patrimoniale li somma, anche da un esercizio prima ancora aperto), fermata con la
 * versione. L'impronta guarda le cifre, i conti, le date, lo stato, non gli id: una fattura riscritta uguale (la modifica della sola scadenza la
 * cancella e la riscrive) non è un cambiamento; un allegato non tocca il giornale. Rifarla costa una lettura delle righe e una somma per conto, nessun
 * calcolo: si fa in testa a ogni scheda e dice soltanto se il giornale è cambiato. **Tutte le cifre**, con il conguaglio per persona
 * ({@see CifreDelRendiconto}): si confrontano dove la lettura del conguaglio si fa già, o a richiesta sopra la soglia di peso della Sintesi; dicono le
 * cifre di allora e di oggi, le registrazioni e le persone. Le cifre, non i nomi: {@see VersioniDelRendiconto::differenze()}.
 *
 * Il confronto non conta il saldo dell'estratto conto della banca, che l'amministratore scrive nella nota: non è una registrazione.
 */
final class ConfrontoDelRendiconto
{
    /** Le righe dell'elenco «che cosa è cambiato», per le registrazioni e per le persone. */
    public const RIGHE_ELENCO = 50;

    public function __construct(
        private readonly CifreDelRendiconto $cifre,
        private readonly ConguaglioDelRendiconto $conguaglio,
    ) {}

    /**
     * L'impronta del giornale dell'esercizio. Per registrazione il suo contenuto (la data, l'esercizio, lo stato, il tipo; ogni riga con il conto, la
     * cassa, la voce, il verso, l'importo, l'unità, la persona, la rata, in un ordine che non dipende dagli id); `prima`, i saldi per conto delle
     * registrazioni con la data prima dell'esercizio e i saldi di apertura delle casse non ancora a giornale; `impronta`, tutto insieme. `per_scrittura` è un
     * elenco di «id:impronta:protocollo» (il protocollo in breve), per dire quali registrazioni sono nuove, cambiate o tolte: un elenco, e non una mappa
     * per id, così le chiavi della forma delle cifre non dipendono dai dati ({@see mappa()} lo rilegge). Le registrazioni eliminate non ci sono.
     *
     * @return array{scritture:int, impronta:string, prima:string, per_scrittura:list<string>}
     */
    public static function giornale(Esercizio $esercizio): array
    {
        $dal = substr((string) $esercizio->data_inizio, 0, 10);
        $dopo = Carbon::parse(substr((string) $esercizio->data_fine, 0, 10))->addDay()->format('Y-m-d');
        $righe = DB::table('scritture_contabili as sc')->join('righe_scritture as rs', 'rs.scrittura_id', '=', 'sc.id')
            ->where('sc.condominio_id', $esercizio->condominio_id)->whereNull('sc.deleted_at')
            // `< giorno dopo`: su MySQL `data_competenza` è DATE, su SQLite può avere l'ora.
            ->where(fn ($q) => $q->where('sc.esercizio_id', $esercizio->id)->orWhere(fn ($q) => $q->where('sc.data_competenza', '>=', $dal)->where('sc.data_competenza', '<', $dopo)))
            ->orderBy('sc.id')->orderBy('rs.id')
            ->select(['sc.id as s', 'sc.esercizio_id as e', 'sc.data_competenza as d', 'sc.stato as st', 'sc.tipo_movimento as t', 'sc.numero_protocollo as p',
                'rs.conto_contabile_id as c', 'rs.cassa_id as ca', 'rs.voce_spesa_id as v', 'rs.tipo_riga as tr', 'rs.importo as i', 'rs.immobile_id as im',
                'rs.anagrafica_id as a', 'rs.rata_id as ra'])
            ->cursor();
        $per = [];
        $impronte = [];
        $corrente = null;
        $testa = '';
        $protocollo = '';
        $righeDi = [];
        $chiudi = function () use (&$per, &$impronte, &$corrente, &$testa, &$protocollo, &$righeDi) {
            if ($corrente !== null) {
                sort($righeDi);
                $impronta = substr(hash('sha256', $testa.'#'.implode('#', $righeDi)), 0, 12);
                $per[] = $corrente.':'.$impronta.':'.substr(hash('sha256', $protocollo), 0, 6);
                $impronte[] = $impronta;
            }
        };
        foreach ($righe as $x) {
            if ($x->s !== $corrente) {
                $chiudi();
                $corrente = $x->s;
                $testa = $x->e.'|'.substr((string) $x->d, 0, 10).'|'.$x->st.'|'.$x->t;
                $protocollo = (string) $x->p;
                $righeDi = [];
            }
            $righeDi[] = $x->c.'|'.$x->ca.'|'.$x->v.'|'.$x->tr.'|'.$x->i.'|'.$x->im.'|'.$x->a.'|'.$x->ra;
        }
        $chiudi();
        sort($impronte);
        $prima = self::saldiPrima($esercizio, $dal);

        return ['scritture' => count($per), 'impronta' => hash('sha256', implode(';', $impronte).'|'.$prima), 'prima' => $prima, 'per_scrittura' => $per];
    }

    /**
     * I saldi per conto delle registrazioni con la data prima dell'esercizio, di ogni esercizio, e i saldi di apertura delle casse non ancora a giornale:
     * ciò che la situazione patrimoniale somma prima del primo giorno. Un'impronta breve, con una somma per conto.
     */
    private static function saldiPrima(Esercizio $esercizio, string $dal): string
    {
        $saldi = DB::table('righe_scritture as rs')->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
            ->where('sc.condominio_id', $esercizio->condominio_id)->whereNull('sc.deleted_at')->where('sc.data_competenza', '<', $dal)
            ->groupBy('rs.conto_contabile_id')->orderBy('rs.conto_contabile_id')
            ->selectRaw("rs.conto_contabile_id as conto, SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END) as netto")
            ->get()->map(fn ($r) => $r->conto.':'.(int) $r->netto)->implode(';');
        $aperture = DB::table('casse')->where('condominio_id', $esercizio->condominio_id)->where('saldo_iniziale', '!=', 0)->orderBy('id')
            ->get(['id', 'saldo_iniziale'])->map(fn ($c) => $c->id.':'.(int) $c->saldo_iniziale)->implode(';');

        return substr(hash('sha256', $saldi.'|'.$aperture), 0, 16);
    }

    /**
     * L'elenco «id:impronta:protocollo» del giornale fermato, come mappa: registrazione => [impronta, protocollo in breve].
     *
     * @param  list<string>  $elenco
     * @return array<int, array{0:string, 1:string}>
     */
    public static function mappa(array $elenco): array
    {
        $mappa = [];
        foreach ($elenco as $riga) {
            [$id, $impronta, $protocollo] = explode(':', (string) $riga, 3) + [1 => '', 2 => ''];
            $mappa[(int) $id] = [$impronta, $protocollo];
        }

        return $mappa;
    }

    /**
     * Le registrazioni nuove, cambiate e tolte fra due giornali. Una nuova e una tolta con lo stesso contenuto sono la stessa registrazione riscritta, e
     * non contano; una nuova e una tolta con lo stesso protocollo sono una registrazione cambiata.
     *
     * @param  array<int, array{0:string, 1:string}>  $prima
     * @param  array<int, array{0:string, 1:string}>  $oggi
     * @return array<int,string> registrazione => «nuova», «cambiata», «tolta»
     */
    public static function differenzeDelGiornale(array $prima, array $oggi): array
    {
        $cosa = [];
        $nuove = array_diff_key($oggi, $prima);
        $tolte = array_diff_key($prima, $oggi);
        foreach (array_intersect_key($oggi, $prima) as $id => [$impronta]) {
            if ($prima[$id][0] !== $impronta) {
                $cosa[$id] = 'cambiata';
            }
        }
        // Riscritta uguale: la stessa impronta fra le tolte.
        foreach ($nuove as $id => [$impronta]) {
            $uguale = array_key_first(array_filter($tolte, fn ($t) => $t[0] === $impronta));
            if ($uguale !== null) {
                unset($nuove[$id], $tolte[$uguale]);
            }
        }
        // Riscritta diversa: lo stesso protocollo fra le tolte.
        foreach ($nuove as $id => [, $protocollo]) {
            $stesso = $protocollo === '' ? null : array_key_first(array_filter($tolte, fn ($t) => $t[1] === $protocollo));
            if ($stesso !== null) {
                unset($nuove[$id], $tolte[$stesso]);
                $cosa[$id] = 'cambiata';
            }
        }
        foreach (array_keys($nuove) as $id) {
            $cosa[$id] = 'nuova';
        }
        foreach (array_keys($tolte) as $id) {
            $cosa[$id] = 'tolta';
        }
        ksort($cosa);

        return $cosa;
    }

    /**
     * Il giornale è cambiato dal giorno della versione? Null se la versione non ha l'impronta del giornale di oggi (fermata con una forma prima della 3).
     */
    public static function giornaleCambiato(RendicontoVersione $versione, ?array $oggi = null): ?bool
    {
        if ((int) $versione->forma < 3) {
            return null;
        }
        $raw = DB::table('rendiconto_versioni')->where('id', $versione->id)->value(DB::raw("json_extract(cifre, '$.giornale.impronta')"));
        if ($raw === null) {
            return null;
        }
        // MySQL restituisce la stringa fra virgolette, SQLite senza.
        $decodificata = json_decode((string) $raw, true);
        $impronta = is_string($decodificata) ? $decodificata : (string) $raw;
        $oggi ??= self::giornale($versione->esercizio);

        return $impronta !== $oggi['impronta'];
    }

    /**
     * La riga in testa a ogni scheda dopo l'approvazione: se il giornale è cambiato dal giorno della versione approvata, lo dice. Null quando non c'è
     * una versione approvata, o il giornale non è cambiato, o la versione non ha l'impronta.
     */
    public static function avvisoInTesta(Esercizio $esercizio): ?string
    {
        $approvata = RendicontoVersione::where('esercizio_id', $esercizio->id)->where('stato', RendicontoVersione::APPROVATA)
            ->orderByDesc('numero')->first(['id', 'esercizio_id', 'numero', 'stato', 'forma', 'calcolato_il', 'data_delibera']);
        if ($approvata === null || ! self::giornaleCambiato($approvata)) {
            return null;
        }

        return 'Dopo l\'approvazione del '.$approvata->data_delibera->format('d/m/Y').' sono cambiate registrazioni che queste schede leggono: le schede non sono '
            .'più quelle approvate. Il rendiconto approvato resta quello del PDF.';
    }

    /**
     * Le cifre di oggi contro quelle di una versione: che cosa è cambiato, in frasi, con le registrazioni e le persone.
     *
     * @param  array<string,mixed>|null  $letto  la lettura del conguaglio della stessa richiesta, se c'è
     * @param  array<string,mixed>|null  $oggi  le cifre di oggi già lette ({@see CifreDelRendiconto::perEsercizio()}), per confrontarle con più versioni
     * @return array<string,mixed>
     */
    public function perLaVersione(Condominio $condominio, Esercizio $esercizio, RendicontoVersione $versione, ?array $letto = null, ?array $oggi = null): array
    {
        $oggi ??= $this->cifreDiOggi($condominio, $esercizio, $letto);
        $prima = (array) $versione->cifre;
        $frasi = VersioniDelRendiconto::differenze($prima, $oggi);
        $approvata = $versione->stato !== RendicontoVersione::PRESENTATA && $versione->data_delibera !== null;
        $giorno = $approvata ? $versione->data_delibera->format('d/m/Y') : VersioniDelRendiconto::emessaIl($versione)->format('d/m/Y');
        $persone = self::personeCambiate($prima, $oggi);

        return [
            'versione_id' => (int) $versione->id, 'numero' => (int) $versione->numero, 'stato' => $versione->stato, 'giorno' => $giorno, 'cambiato' => $frasi !== [],
            'frasi' => $frasi,
            'frase' => $frasi === [] ? null : ($approvata
                ? 'Dopo l\'approvazione del '.$giorno.' sono cambiati i conti dell\'esercizio: '.implode('; ', $frasi).'. Il rendiconto approvato resta quello del PDF.'
                : 'Dal '.$giorno.', il giorno della versione '.$versione->numero.', sono cambiati i conti dell\'esercizio: '.implode('; ', $frasi).'.'),
            'scritture' => self::scrittureCambiate($prima, $oggi),
            'persone' => array_slice($persone, 0, self::RIGHE_ELENCO),
            'persone_cambiate' => count($persone),
        ];
    }

    /**
     * Il confronto per la Sintesi: la versione approvata (l'avviso) e le versioni fermate dopo di lei e non ancora approvate (la finestra
     * dell'approvazione), con una lettura sola delle cifre di oggi. Sotto la soglia di peso si fa da sé; sopra, solo a richiesta, e senza la richiesta c'è
     * il solo giornale, per l'approvata e per ogni fermata.
     *
     * @return array{automatico:bool, fatto:bool, approvata:?array, presentate:list<array<string,mixed>>, giornale_cambiato:?bool}|null  null: nessuna versione
     */
    public function perLaSintesi(Condominio $condominio, Esercizio $esercizio, ?array $letto, bool $chiesto, ?array $controlli = null): ?array
    {
        // Senza le cifre (nel condominio grande più di un MB per versione): si leggono solo per le versioni che si confrontano, e solo se si confronta.
        $versioni = RendicontoVersione::where('esercizio_id', $esercizio->id)->whereIn('stato', [RendicontoVersione::APPROVATA, RendicontoVersione::PRESENTATA])
            ->orderByDesc('numero')->get(RendicontoVersione::COLONNE_DELLA_LISTA);
        if ($versioni->isEmpty()) {
            return null;
        }
        $approvata = $versioni->firstWhere('stato', RendicontoVersione::APPROVATA);
        // Le fermate che si possono ancora approvare: quelle più recenti della versione approvata.
        $presentate = $versioni->filter(fn ($v) => $v->stato === RendicontoVersione::PRESENTATA && ($approvata === null || $v->numero > $approvata->numero))->values();
        $automatico = $letto !== null;
        if (! $automatico && ! $chiesto) {
            $giornale = $versioni->isEmpty() ? null : self::giornale($esercizio);

            return ['automatico' => false, 'fatto' => false, 'approvata' => null,
                'presentate' => $presentate->map(fn ($v) => ['versione_id' => (int) $v->id, 'numero' => (int) $v->numero,
                    'giornale_cambiato' => self::giornaleCambiato($v, $giornale)])->all(),
                'giornale_cambiato' => $approvata === null ? null : self::giornaleCambiato($approvata, $giornale)];
        }
        // I controlli della Sintesi valgono solo se letti con la stessa lettura del conguaglio: a richiesta, sopra la soglia, si rifanno con la sua.
        $oggi = $this->cifreDiOggi($condominio, $esercizio, $letto, $automatico ? $controlli : null);

        return ['automatico' => $automatico, 'fatto' => true,
            'approvata' => $approvata === null ? null : $this->perLaVersione($condominio, $esercizio, RendicontoVersione::findOrFail($approvata->id), null, $oggi),
            'presentate' => $presentate->map(fn ($v) => $this->perLaVersione($condominio, $esercizio, RendicontoVersione::findOrFail($v->id), null, $oggi))->all(),
            'giornale_cambiato' => null];
    }

    /** Le cifre di oggi, nella forma delle versioni: con la lettura del conguaglio della richiesta (e i suoi controlli), o una sua. */
    private function cifreDiOggi(Condominio $condominio, Esercizio $esercizio, ?array $letto, ?array $controlli = null): array
    {
        if ($letto === null) {
            $letto = $this->conguaglio->leggi($esercizio, [], true);
            $controlli = null;
        }

        return $this->cifre->perEsercizio($condominio, $esercizio, $letto, DateHelper::adessoUtente(), null, $controlli);
    }

    /**
     * Le persone il cui conguaglio è cambiato, con le cifre di allora e di oggi: una riga per persona, unità e gestione, confrontata sulle sue cifre
     * ({@see VersioniDelRendiconto::CIFRE_DELLA_RIGA}), non sui nomi. Una riga nuova (un titolare entrato con un passaggio registrato dopo) ha «allora»
     * vuoto; una che non c'è più ha «oggi» vuoto.
     *
     * Le cifre della riga sono due coppie: il conguaglio (`prima`, `oggi`: spese meno quote) e il saldo finale (`saldo_prima`, `saldo_oggi`: con la
     * posizione sulle rate). Un incasso dopo l'approvazione cambia il saldo finale e non il conguaglio: con la sola prima coppia l'elenco direbbe due
     * volte la stessa cifra.
     *
     * @return list<array{nome:string, unita:?string, gestione:string, prima:?int, oggi:?int, saldo_prima:?int, saldo_oggi:?int}>
     */
    public static function personeCambiate(array $prima, array $oggi): array
    {
        $chiave = fn ($r) => $r['gestione_id'].'|'.$r['anagrafica_id'].'|'.$r['immobile_id'];
        $righePrima = collect($prima['conguaglio']['righe'] ?? [])->keyBy($chiave);
        $righeOggi = collect($oggi['conguaglio']['righe'] ?? [])->keyBy($chiave);
        $gestioni = collect([...($prima['conguaglio']['gestioni'] ?? []), ...($oggi['conguaglio']['gestioni'] ?? [])])->pluck('nome', 'gestione_id');
        $cifre = fn (?array $r) => $r === null ? null : array_intersect_key($r, array_flip(VersioniDelRendiconto::CIFRE_DELLA_RIGA));
        $elenco = [];
        foreach ($righeOggi->keys()->merge($righePrima->keys())->unique() as $k) {
            $p = $righePrima[$k] ?? null;
            $o = $righeOggi[$k] ?? null;
            if ($cifre($p) == $cifre($o)) {
                continue;
            }
            $r = $o ?? $p;
            $elenco[] = ['nome' => (string) ($r['nome'] ?? ''), 'unita' => $r['unita'] ?? null, 'gestione' => (string) ($gestioni[$r['gestione_id']] ?? ''),
                'prima' => $p === null ? null : $p['conguaglio'], 'oggi' => $o === null ? null : $o['conguaglio'],
                'saldo_prima' => isset($p['saldo']) ? (int) $p['saldo'] : null, 'saldo_oggi' => isset($o['saldo']) ? (int) $o['saldo'] : null];
        }
        usort($elenco, fn ($a, $b) => [$a['nome'], (string) $a['unita']] <=> [$b['nome'], (string) $b['unita']]);

        return $elenco;
    }

    /**
     * Le registrazioni nuove, cambiate o tolte dal giorno della versione, dalle impronte del giornale ({@see differenzeDelGiornale()}); le prime
     * {@see RIGHE_ELENCO}, con la data, il protocollo, la causale e che cosa è successo. Vuoto se la versione non ha l'impronta del giornale di oggi.
     *
     * @return list<array{scrittura_id:int, cosa:string, data:?string, protocollo:?string, causale:?string}>
     */
    public static function scrittureCambiate(array $prima, array $oggi): array
    {
        if ((int) ($prima['forma'] ?? 1) < 3 || ! isset($prima['giornale']['per_scrittura'], $oggi['giornale']['per_scrittura'])) {
            return [];
        }
        $cosa = array_slice(self::differenzeDelGiornale(self::mappa((array) $prima['giornale']['per_scrittura']), self::mappa((array) $oggi['giornale']['per_scrittura'])),
            0, self::RIGHE_ELENCO, true);
        // Una registrazione tolta può essere cancellata del tutto (la modifica di una fattura la riscrive): si legge anche fra le eliminate, se c'è.
        $dati = DB::table('scritture_contabili')->whereIn('id', array_keys($cosa))->get(['id', 'data_competenza', 'numero_protocollo', 'causale'])->keyBy('id');

        return array_values(array_map(fn (int $id, string $c) => ['scrittura_id' => $id, 'cosa' => $c,
            'data' => isset($dati[$id]) ? substr((string) $dati[$id]->data_competenza, 0, 10) : null,
            'protocollo' => $dati[$id]->numero_protocollo ?? null, 'causale' => $dati[$id]->causale ?? null], array_keys($cosa), $cosa));
    }
}
