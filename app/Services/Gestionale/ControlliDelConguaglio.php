<?php

namespace App\Services\Gestionale;

use App\Models\Immobile;
use Illuminate\Support\Facades\DB;

/**
 * I quattro controlli del conguaglio per persona, scritti come uguaglianze, letti dalla stessa lettura che la richiesta ha già
 * ({@see ConguaglioDelRendiconto::leggi()}): nessuna seconda lettura del consuntivo.
 *
 * - **CG, il conguaglio divide le spese dell'esercizio**: le spese divise fra le persone + il già versato delle unità tolto dalle loro spese + le
 *   uscite che nessuno paga (+ le gestioni che non si calcolano, le opere in corso, − le spese di esercizi prima delle opere finite in questo) = le
 *   spese per capitolo meno le entrate del riepilogo; e i costi tornano con il riepilogo (il controllo «I costi tornano con il riepilogo», chiamato e non rifatto:
 *   quando non tornano la 8.3 lo dice e rimanda a lui, e l'esito non conta la stessa differenza due volte).
 *   Il motore toglie le uscite che nessuno paga e divide il resto al centesimo: costa poco e resta, ma non trova una spesa data alla persona sbagliata.
 * - **CU, ogni unità ha la sua parte**: per ogni unità la somma delle sue persone (prima del già versato dell'unità) è la sua parte della spesa divisa
 *   sulla sola tabella, senza i titolari, con i coefficienti delle tabelle della voce; a meno dei centesimi del resto, al massimo uno per persona e per
 *   ogni divisione della voce (una per competenza; il motore dà il resto fra tutte le persone, la divisione per unità fra le unità). Trova l'unità senza
 *   un titolare.
 * - **CB, le quote tornano con il riparto**: per ogni unità dei piani con il riparto registrato la spesa delle quote è la somma delle sue righe del
 *   riparto (la regola del deliberato ricostruito). Dove non torna il conguaglio di quell'unità non si calcola: la lettura la dice non disponibile. E dice
 *   dove una tabella è cambiata dopo il piano (il millesimo, la somma dei millesimi o il coefficiente delle righe del riparto contro quelli di oggi): una
 *   segnalazione, perché il conguaglio comprende la differenza.
 *
 * Senza la lettura (la Sintesi sopra la soglia, il fascicolo senza la parte del conguaglio, la stampa sopra la soglia) i quattro controlli ci sono lo stesso,
 * con un esito loro, «non calcolato», fuori dal conto dei «da leggere», e il rimando alla scheda «Controlli», che la lettura la fa sempre.
 */
final class ControlliDelConguaglio
{
    public const NOTA_NON_LETTO = 'Questo controllo legge il conguaglio per persona, che qui non si calcola: l\'esito è nella scheda «Controlli», che lo calcola sempre.';

    /** La nota in una stampa, che va ai condòmini: senza le schede del programma. */
    public const NOTA_NON_LETTO_STAMPA = 'Questo controllo legge il conguaglio per persona, che questa stampa non comprende.';

    /** Quando i costi non tornano con il riepilogo: la 8.3 lo dice e rimanda al controllo che ha il perché. */
    /** I rimedi del passaggio non registrato e delle spese personali: nella stampa hanno una variante senza l'istruzione ({@see ParoleDellaStampa}). */
    public const RIMEDIO_PASSAGGIO = 'Registra il passaggio dalla pagina dei titolari dell\'unità: con il passaggio chi entra conta dal giorno in cui entra.';

    public const RIMEDIO_PASSAGGIO_ANCHE = 'Registra il passaggio dove un titolare è entrato senza.';

    public const RIMEDIO_PERSONALI = 'Una spesa per una sola unità non è né fra quelle da chiedere né in un piano: guarda le fatture dell\'unità e i piani che le chiedono.';

    public const COSTI_NON_TORNANO = 'I costi non tornano con il riepilogo: lo dice il controllo «I costi tornano con il riepilogo», con il perché.';

    /** Le unità che non tornano, dette per nome nella nota: oltre, quante sono. */
    public const UNITA_NELLA_NOTA = 5;

    private const NOMI = [
        'CG' => ['Il conguaglio divide le spese dell\'esercizio', 'Spese divise fra le persone + già versato delle unità + spese non attribuite = spese per capitolo meno entrate; e i costi tornano con il riepilogo'],
        'CU' => ['Ogni unità ha la sua parte', 'Per ogni unità: la somma delle sue persone = la sua parte della spesa divisa sulla sola tabella, a meno dei centesimi del resto'],
        'CB' => ['Le quote tornano con il riparto', 'Per ogni unità: la spesa delle quote dei piani = le righe del riparto registrato'],
        'CA' => ['Le spese personali tornano con le anticipazioni', 'Per ogni unità: spese personali non ancora chieste + di giorni senza un titolare + chieste con rate non ancora emesse = spese anticipate per l\'unità ancora aperte'],
    ];

    /**
     * I quattro controlli, nell'ordine CG, CU, CB, CA.
     *
     * @param  array<string,mixed>|null  $letto  la lettura del consuntivo per persona; null se la richiesta non l'ha
     * @param  array<string,int>  $riconciliazione  la riga che riconcilia del riepilogo (`capitoli_netti`)
     * @param  array<string,mixed>  $costi  il controllo «I costi tornano con il riepilogo» ({@see ControlliDelRendiconto::costi()})
     * @return list<array<string,mixed>>
     */
    public static function tutti(?array $letto, array $riconciliazione, array $costi): array
    {
        if ($letto === null) {
            return array_map(fn (string $id) => self::nonLetto($id), array_keys(self::NOMI));
        }

        return [self::spese($letto, $riconciliazione, $costi), self::perUnita($letto), self::quote($letto), self::personali($letto)];
    }

    /** Un controllo che qui non si calcola: c'è, da leggere, con il rimando alla scheda. */
    public static function nonLetto(string $id): array
    {
        // Un esito suo, fuori dal conto dei «da leggere»: il programma non ha calcolato il controllo in questa richiesta, non è un dubbio sui numeri.
        return ['id' => $id, 'nome' => self::NOMI[$id][0], 'titolo' => self::NOMI[$id][1], 'sinistra' => 0, 'destra' => 0, 'esito' => 'non_calcolato',
            'nota' => self::NOTA_NON_LETTO, 'breve' => null, 'rimedio' => null, 'azione' => null, 'non_letto' => true];
    }

    /** I termini della prima uguaglianza, dalla lettura: le spese divise, il già versato delle unità, le non attribuite e gli altri termini. */
    public static function termini(array $letto): array
    {
        $t = ['diviso' => 0, 'gia_versato_unita' => 0, 'non_attribuite' => 0, 'non_calcolabili' => 0, 'in_corso' => 0, 'esercizi_prima' => 0];
        foreach ($letto['gestioni'] as $gid => $g) {
            $t['non_attribuite'] += (int) array_sum($g['non_attribuite'] ?? []);
            if (($g['non_calcolabile'] ?? null) !== null) {
                $t['non_calcolabili'] += (int) ($g['speso'] ?? 0);
            }
            // Le opere in corso: la loro spesa dell'esercizio non si divide. Quelle finite in un esercizio prima dividono qui le spese scritte dopo la fine.
            $t['in_corso'] += (int) ($g['in_corso']['speso_esercizio'] ?? 0);
            if (($g['non_calcolabile'] ?? null) === null) {
                $t['esercizi_prima'] += (int) ($g['opera_finita']['spese_esercizi_prima'] ?? 0);
            }
            foreach ($letto['persone'][$gid] ?? [] as $perUnita) {
                foreach ($perUnita as $r) {
                    $t['diviso'] += (int) ($r['a'] ?? array_sum($r['voci'] ?? []));
                    $t['gia_versato_unita'] += (int) ($r['gia_versato_unita'] ?? 0);
                }
            }
        }

        return $t;
    }

    /** CG: la 8.3, con tutti i termini; e l'uguaglianza dei costi del riepilogo, chiamata e non rifatta. */
    public static function spese(array $letto, array $riconciliazione, array $costi): array
    {
        $t = self::termini($letto);
        $sinistra = $t['diviso'] + $t['gia_versato_unita'] + $t['non_attribuite'] + $t['non_calcolabili'] + $t['in_corso'] - $t['esercizi_prima'];
        $destra = (int) $riconciliazione['capitoli_netti'];
        $torna = $sinistra === $destra;
        $eq = 'Spese divise fra le persone '.self::euro($t['diviso']).' + già versato delle unità tolto dalle loro spese '.self::euro($t['gia_versato_unita'])
            .' + spese non attribuite '.self::euro($t['non_attribuite'])
            .($t['non_calcolabili'] !== 0 ? ' + spese delle gestioni che non si calcolano '.self::euro($t['non_calcolabili']) : '')
            .($t['in_corso'] !== 0 ? ' + spese delle opere in corso, non divise '.self::euro($t['in_corso']) : '')
            .($t['esercizi_prima'] !== 0 ? ' − spese di esercizi prima delle opere finite in questo '.self::euro($t['esercizi_prima']) : '')
            .($torna ? ' = ' : ' ≠ ').'spese per capitolo meno entrate '.self::euro($destra).'.';
        // I costi del riepilogo: se tornano, la loro uguaglianza completa questa; se no, la differenza è del controllo dei costi, che la dice con il perché.
        // Qui si rimanda a lui, senza contarla una seconda volta nell'esito.
        $costiTornano = $costi['esito'] === 'quadra';

        return [
            'id' => 'CG',
            'nome' => self::NOMI['CG'][0],
            'titolo' => self::NOMI['CG'][1],
            'sinistra' => $sinistra,
            'destra' => $destra,
            'segno' => $torna ? '=' : '≠',
            'esito' => $torna ? 'quadra' : 'non_quadra',
            'nota' => $eq.' '.($costiTornano ? $costi['nota'] : self::COSTI_NON_TORNANO),
            'breve' => $torna ? null : 'differenza '.self::euro($sinistra - $destra),
            'rimedio' => $torna ? null
                : 'La differenza è una spesa che il conguaglio non legge come il riepilogo: una voce senza la sua gestione nell\'esercizio, o una spesa registrata dopo la lettura. Apri la scheda «Conguaglio» e il riepilogo.',
            'azione' => $torna ? null : 'conguaglio',
        ];
    }

    /**
     * CU: per ogni unità la somma delle sue persone contro la sua parte della spesa divisa sulla sola tabella. Le gestioni che non si calcolano e le
     * opere in corso non si dividono, e non contano.
     */
    public static function perUnita(array $letto): array
    {
        $persone = [];
        $chiavi = [];
        $esatta = [];
        $nomiGestione = [];
        foreach ($letto['gestioni'] as $gid => $g) {
            if (($g['non_calcolabile'] ?? null) !== null || ($g['in_corso'] ?? null) !== null || ($g['uscite'] ?? []) === []) {
                continue;
            }
            $nomiGestione[$gid] = (string) $g['nome'];
            foreach ($letto['persone'][$gid] ?? [] as $perUnita) {
                foreach ($perUnita as $iid => $r) {
                    $persone[$gid][$iid] = ($persone[$gid][$iid] ?? 0) + (int) array_sum($r['voci'] ?? []) + (int) ($r['gia_versato_unita'] ?? 0);
                    // Il resto del centesimo: al più uno per persona e per ogni divisione del conto (una per competenza: le fatture datate, le competenze
                    // di una straordinaria).
                    foreach (array_keys($r['voci'] ?? []) as $cid) {
                        if (isset($g['uscite'][$cid])) {
                            $chiavi[$gid][$iid] = ($chiavi[$gid][$iid] ?? 0) + max(1, (int) ($g['divisioni'][$cid] ?? 1));
                        }
                    }
                }
            }
            foreach (self::pesiDelleUnita(array_keys($g['uscite'])) as $cid => $pesi) {
                foreach ($pesi as $iid => $peso) {
                    $esatta[$gid][$iid] = ($esatta[$gid][$iid] ?? 0.0) + (int) $g['uscite'][$cid] * $peso;
                }
            }
        }

        $controllate = 0;
        $fuori = [];
        foreach ($esatta + $persone as $gid => $_) {
            foreach (($esatta[$gid] ?? []) + ($persone[$gid] ?? []) as $iid => $__) {
                $controllate++;
                $suaParte = (float) ($esatta[$gid][$iid] ?? 0.0);
                $diPersone = (int) ($persone[$gid][$iid] ?? 0);
                // A meno dei centesimi del resto: ogni persona su ogni voce ha la sua parte arrotondata, al più un centesimo.
                if (abs($diPersone - $suaParte) >= max(1, (int) ($chiavi[$gid][$iid] ?? 0))) {
                    $fuori[] = ['gestione_id' => (int) $gid, 'immobile_id' => (int) $iid, 'persone' => $diPersone, 'parte' => (int) round($suaParte)];
                }
            }
        }
        $nomi = $fuori === [] ? [] : Immobile::whereIn('id', array_column(array_slice($fuori, 0, self::UNITA_NELLA_NOTA), 'immobile_id'))->get()
            ->mapWithKeys(fn (Immobile $i) => [(int) $i->id => (string) $i->etichetta])->all();
        $n = count($fuori);
        $righe = array_map(fn ($f) => ($nomi[$f['immobile_id']] ?? 'Unità '.$f['immobile_id']).' ('.$nomiGestione[$f['gestione_id']].'): persone '.self::euro($f['persone'])
            .', sua parte '.self::euro($f['parte']).($f['persone'] < $f['parte'] ? ', mancano '.self::euro($f['parte'] - $f['persone']) : ', in più '.self::euro($f['persone'] - $f['parte'])),
            array_slice($fuori, 0, self::UNITA_NELLA_NOTA));

        // I cambi di titolare con giorni scoperti e senza un passaggio registrato: il motore non cambia (chi entra conta dall'inizio), il controllo lo dice.
        $nonRegistrati = array_filter($letto['passaggi_non_registrati'] ?? []);
        $avvisi = $nonRegistrati === [] ? '' : ' '.implode(' ', array_merge(...array_values($nonRegistrati)));
        $esito = $n !== 0 ? 'non_quadra' : ($nonRegistrati === [] ? 'quadra' : 'segnalazione');

        return [
            'id' => 'CU',
            'nome' => self::NOMI['CU'][0],
            'titolo' => self::NOMI['CU'][1],
            'sinistra' => $n,
            'destra' => $controllate,
            'esito' => $esito,
            'nota' => ($n === 0
                ? ($controllate === 0 ? 'Nessuna spesa divisa fra le persone.' : 'Per ognuna delle '.$controllate.' unità la somma delle persone è la sua parte della spesa divisa sulla sola tabella, a meno dei centesimi del resto.')
                : ($n === 1 ? 'Un\'unità non torna: ' : $n.' unità non tornano: ').implode('; ', $righe).($n > self::UNITA_NELLA_NOTA ? '; e altre '.($n - self::UNITA_NELLA_NOTA) : '')
                    .'. La differenza è fra le spese non attribuite della voce.').$avvisi,
            'breve' => $n !== 0 ? ($n === 1 ? '1 unità' : $n.' unità') : ($nonRegistrati === [] ? null : 'passaggio da registrare'),
            'rimedio' => $n !== 0
                ? 'Nei giorni in cui un\'unità non ha un titolare la sua parte non va a nessuno: controlla le date dei titolari dell\'unità.'
                    .($nonRegistrati === [] ? '' : ' '.self::RIMEDIO_PASSAGGIO_ANCHE)
                : ($nonRegistrati === [] ? null : self::RIMEDIO_PASSAGGIO),
            'azione' => $n === 0 && $nonRegistrati === [] ? null : 'titolari',
            'azione_parametro' => $n !== 0 ? (string) $fuori[0]['immobile_id'] : ($nonRegistrati === [] ? null : (string) array_key_first($nonRegistrati)),
        ];
    }

    /**
     * CA: per ogni unità, le spese per singole unità ancora da chiedere, quelle di giorni senza un titolare e quelle chieste con rate non ancora emesse,
     * contro le anticipazioni aperte dell'unità sul giornale. Dove non tornano, una spesa personale non è né da chiedere né in un piano.
     */
    public static function personali(array $letto): array
    {
        $per = $letto['personali_per_unita'] ?? [];
        $sinistra = 0;
        $destra = 0;
        $somme = ['non_chieste' => 0, 'senza_titolare' => 0, 'non_emesse' => 0];
        $fuori = [];
        foreach ($per as $iid => $u) {
            $dette = (int) $u['non_chieste'] + (int) $u['senza_titolare'] + (int) $u['non_emesse'];
            $sinistra += $dette;
            $destra += (int) $u['aperte'];
            foreach ($somme as $k => $_) {
                $somme[$k] += (int) $u[$k];
            }
            if ($dette !== (int) $u['aperte']) {
                $fuori[] = ['immobile_id' => (int) $iid, 'dette' => $dette, 'aperte' => (int) $u['aperte']];
            }
        }
        $n = count($fuori);
        $nomi = $fuori === [] ? [] : Immobile::whereIn('id', array_column(array_slice($fuori, 0, self::UNITA_NELLA_NOTA), 'immobile_id'))->get()
            ->mapWithKeys(fn (Immobile $i) => [(int) $i->id => (string) $i->etichetta])->all();
        $righe = array_map(fn ($f) => ($nomi[$f['immobile_id']] ?? 'Unità '.$f['immobile_id']).': spese '.self::euro($f['dette']).', anticipazioni aperte '.self::euro($f['aperte'])
            .($f['dette'] < $f['aperte'] ? ', mancano '.self::euro($f['aperte'] - $f['dette']) : ', in più '.self::euro($f['dette'] - $f['aperte'])), array_slice($fuori, 0, self::UNITA_NELLA_NOTA));
        $termini = 'non ancora chieste '.self::euro($somme['non_chieste']).' + chieste con rate non emesse '.self::euro($somme['non_emesse'])
            .($somme['senza_titolare'] !== 0 ? ' + senza un titolare '.self::euro($somme['senza_titolare']) : '');

        return [
            'id' => 'CA',
            'nome' => self::NOMI['CA'][0],
            'titolo' => self::NOMI['CA'][1],
            'sinistra' => $sinistra,
            'destra' => $destra,
            'segno' => $n === 0 ? '=' : '≠',
            'esito' => $n === 0 ? 'quadra' : 'non_quadra',
            'nota' => $n === 0
                ? ($per === [] ? 'Nessuna spesa per singole unità aperta.' : 'Per ognuna delle '.count($per).' unità con spese per singole unità: '.$termini.' = anticipazioni aperte '.self::euro($destra).'.')
                : ($n === 1 ? 'Un\'unità non torna: ' : $n.' unità non tornano: ').implode('; ', $righe).($n > self::UNITA_NELLA_NOTA ? '; e altre '.($n - self::UNITA_NELLA_NOTA) : '').'.',
            'breve' => $n === 0 ? null : ($n === 1 ? '1 unità' : $n.' unità'),
            'rimedio' => $n === 0 ? null : self::RIMEDIO_PERSONALI,
            'azione' => null,
        ];
    }

    /**
     * I pesi delle unità di ogni conto sulle sue tabelle, senza i titolari: per ogni tabella il coefficiente per il millesimo dell'unità sul totale
     * della tabella, come il motore del riparto. Le voci con le stesse tabelle e gli stessi coefficienti si leggono una volta.
     *
     * @param  list<int>  $conti
     * @return array<int, array<int, float>> conto => unità => peso
     */
    public static function pesiDelleUnita(array $conti): array
    {
        $legami = DB::table('conto_tabella_millesimale')->whereIn('conto_id', $conti)->orderBy('id')->get(['conto_id', 'tabella_id', 'coefficiente'])->groupBy('conto_id');
        $perFirma = [];
        $esito = [];
        foreach ($legami as $cid => $righe) {
            $firma = $righe->map(fn ($r) => $r->tabella_id.':'.$r->coefficiente)->implode('|');
            if (! isset($perFirma[$firma])) {
                $pesi = [];
                foreach ($righe as $r) {
                    $coeff = (float) $r->coefficiente / 100.0;
                    if ($coeff <= 0) {
                        continue;
                    }
                    $valori = DB::table('quote_tabella')->where('tabella_id', $r->tabella_id)->pluck('valore', 'immobile_id');
                    $somma = (float) $valori->sum();
                    if ($somma <= 0.0) {
                        continue;
                    }
                    foreach ($valori as $iid => $v) {
                        if ($v !== null && (float) $v > 0.0) {
                            $pesi[(int) $iid] = ($pesi[(int) $iid] ?? 0.0) + $coeff * (float) $v / $somma;
                        }
                    }
                }
                $perFirma[$firma] = $pesi;
            }
            $esito[(int) $cid] = $perFirma[$firma];
        }

        return $esito;
    }

    /** CB: per ogni unità dei piani con il riparto registrato, la spesa delle quote contro le righe del riparto; dove non torna, il conguaglio non c'è. */
    public static function quote(array $letto): array
    {
        $controllate = 0;
        $fuori = [];
        $cambiate = [];
        $pianoDelCambio = null;
        $nomiGestione = [];
        foreach ($letto['gestioni'] as $gid => $g) {
            $r = $g['riparto_per_unita'] ?? null;
            if ($r === null) {
                continue;
            }
            $nomiGestione[$gid] = (string) $g['nome'];
            $controllate += (int) $r['controllate'];
            foreach ($r['non_tornano'] as $iid => $f) {
                $fuori[] = ['gestione_id' => (int) $gid, 'immobile_id' => (int) $iid] + $f;
            }
            foreach ($r['tabelle_cambiate'] ?? [] as $iid => $tabelle) {
                $cambiate[(int) $iid] = [...($cambiate[(int) $iid] ?? []), ...$tabelle];
                $pianoDelCambio ??= (int) ($r['piano_rate_id'] ?? 0);
            }
        }
        $n = count($fuori);
        $c = count($cambiate);
        $daNominare = array_unique([...array_column(array_slice($fuori, 0, self::UNITA_NELLA_NOTA), 'immobile_id'), ...array_slice(array_keys($cambiate), 0, self::UNITA_NELLA_NOTA)]);
        $nomi = $daNominare === [] ? [] : Immobile::whereIn('id', $daNominare)->get()->mapWithKeys(fn (Immobile $i) => [(int) $i->id => (string) $i->etichetta])->all();
        $righe = array_map(fn ($f) => ($nomi[$f['immobile_id']] ?? 'Unità '.$f['immobile_id']).' ('.$nomiGestione[$f['gestione_id']].'): quote '.self::euro($f['quote'])
            .', riparto '.self::euro($f['riparto']), array_slice($fuori, 0, self::UNITA_NELLA_NOTA));
        $mill = fn (?float $v) => $v === null ? 'nessun millesimo' : number_format($v, 2, ',', '.');
        $perc = fn (?float $v) => $v === null ? 'nessuno' : rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',').' %';
        $righeCambiate = [];
        foreach (array_slice($cambiate, 0, self::UNITA_NELLA_NOTA, true) as $iid => $tabelle) {
            $righeCambiate[] = ($nomi[$iid] ?? 'Unità '.$iid).' ('.implode('; ', array_map(fn ($t) => $t['tabella'].', '.$mill($t['prima']).' su '.$mill($t['somma_prima']).' nel piano, '
                .$mill($t['oggi']).' su '.$mill($t['somma_oggi']).' oggi'.($t['coefficiente_prima'] !== null ? ', coefficiente '.$perc($t['coefficiente_prima']).' nel piano, '.$perc($t['coefficiente_oggi']).' oggi' : ''),
                $tabelle)).')';
        }
        // La tabella cambiata dopo il piano: le quote e il riparto sono del piano, le spese si dividono con la tabella di oggi. Il conguaglio comprende la
        // differenza (la regola scelta): il controllo la fa leggere.
        $notaCambiate = $c === 0 ? '' : ' La tabella è cambiata dopo il piano su '.($c === 1 ? 'un\'unità' : $c.' unità').': '.implode('; ', $righeCambiate)
            .($c > self::UNITA_NELLA_NOTA ? '; e altre '.($c - self::UNITA_NELLA_NOTA) : '').'. Il conguaglio comprende la differenza: le quote sono del piano, le spese '
            .'dell\'esercizio sono divise con la tabella di oggi.';

        return [
            'id' => 'CB',
            'nome' => self::NOMI['CB'][0],
            'titolo' => self::NOMI['CB'][1],
            'sinistra' => $n,
            'destra' => $controllate,
            'esito' => $n > 0 ? 'non_quadra' : ($c > 0 ? 'segnalazione' : 'quadra'),
            'nota' => ($n === 0
                ? ($controllate === 0 ? 'Nessun piano dell\'esercizio ha il riparto registrato: non c\'è niente da confrontare.'
                    : 'Per ognuna delle '.$controllate.' unità dei piani con il riparto registrato la spesa delle quote è il riparto.')
                : ($n === 1 ? 'Un\'unità non torna: ' : $n.' unità non tornano: ').implode('; ', $righe).($n > self::UNITA_NELLA_NOTA ? '; e altre '.($n - self::UNITA_NELLA_NOTA) : '')
                    .'. Il conguaglio di queste unità non si calcola: la scheda «Conguaglio» le dice non disponibili.').$notaCambiate,
            'breve' => $n > 0 ? ($n === 1 ? '1 unità' : $n.' unità') : ($c > 0 ? 'tabella cambiata dopo il piano' : null),
            'rimedio' => $n > 0
                ? 'Le quote dell\'unità sono cambiate dopo il riparto del piano: apri il piano e controlla le quote dell\'unità.'
                : ($c > 0 ? 'Se la tabella di oggi è quella giusta, il conguaglio comprende la differenza; se no, correggi la tabella. Il piano dice i millesimi che ha usato.' : null),
            'azione' => $n > 0 || $c > 0 ? 'piano' : null,
            'azione_parametro' => $n > 0 ? (string) $fuori[0]['piano_rate_id'] : ($c > 0 ? (string) $pianoDelCambio : null),
        ];
    }

    private static function euro(int $c): string
    {
        return '€ '.number_format($c / 100, 2, ',', '.');
    }
}
