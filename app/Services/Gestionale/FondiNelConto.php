<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Models\Esercizio;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Le casse dell'esercizio come le legge la scheda «Cassa» del rendiconto: ogni cassa reale (un conto in banca, i contanti) con dentro le partizioni che
 * le appartengono (i fondi e le casse virtuali), perché un fondo non è denaro altrove ma una parte dello stesso conto corrente ({@see SaldoCassaService}).
 * Il conto chiude così con il saldo che dice la banca, la stessa cifra del controllo della banca ({@see SaldiDelleBanche}); sotto, le righe «di cui»: ogni
 * partizione (iniziale, accantonati, usati, finale) e la parte libera, che è il conto contabile della banca da solo.
 *
 * QUALE BANCA. La regola del controllo della banca: con **un conto in banca solo** (quelli che contano, {@see SaldiDelleBanche::contaIlConto()}) tutte le
 * partizioni sono sue; con **più conti**, le righe di una partizione nelle registrazioni che hanno una riga di una banca sola sono di quella banca (un
 * accantonamento, il suo ritorno), e un passaggio fra due partizioni della stessa banca resta in quella banca; il resto (un fondo aperto con un saldo suo,
 * un passaggio fra fondi di banche diverse) non sta nel conto di una banca e ha la sua riga. Senza un conto in banca le partizioni hanno la loro riga.
 *
 * LE CIFRE. Nessuna somma nuova: le righe di ogni cassa sono quelle del prospetto per cassa ({@see RiepilogoFinanziarioService}), con lo stesso perimetro
 * (le registrazioni dell'esercizio, senza le aperture e senza le coppie stornate). Il conto somma la cassa reale e le sue partizioni; le entrate e le
 * uscite sono quelle con l'esterno del conto: lo spostamento fra la parte libera e un fondo (l'accantonamento, il suo ritorno, un passaggio fra due fondi)
 * si compensa nella registrazione e non c'è, come il piede non ha i trasferimenti fra le casse. Le righe «di cui» lo hanno, e `interni` dice quanto; la
 * parte libera dice quanto ne è andato nei fondi (`messi_nei_fondi`, fra le sue uscite) e quanto ne è tornato (`tornati_dai_fondi`, fra le sue entrate).
 * Il passaggio fra due fondi e quello con una cassa virtuale non sono né l'uno né l'altro.
 *
 * IL TIPO DEI FONDI. Ogni fondo ha il suo tipo (`tipo_fondo`, l'etichetta e il tono) e dice se il suo denaro si può usare (`usabile`); un conto con dei
 * fondi dice quanto si può usare alla fine dell'esercizio (`si_puo_usare`): la parte libera e i fondi generici o sbloccati dall'assemblea; un fondo vincolato
 * in rosso ne toglie il rosso, che è denaro uscito dalla parte libera. La regola è una sola, {@see TipoDelFondo}. Una cassa virtuale non è un fondo e non è
 * nella parte libera: resta fuori.
 */
final class FondiNelConto
{
    private const ORDINE_TIPI = ['banca' => 0, 'contanti' => 1, 'fondo' => 2, 'virtuale' => 3];

    /**
     * @param  Collection<int, object>  $casse  le casse del condominio con il conto (id, nome, tipo, conto_contabile_id, saldo_iniziale, attiva; per i fondi
     *                                            sottotipo_fondo, vincolo_descrizione, is_override_assemblea, motivazione_override)
     * @param  list<array<string,mixed>>  $righe  le righe per cassa del prospetto
     * @param  list<int>  $esclusi  le registrazioni delle coppie stornate dell'esercizio
     * @param  int  $interno  la parte che si compensa fra le casse, in tutto (i giroconti del prospetto)
     * @return array{conti: list<array<string,mixed>>, fra_le_casse: int}
     */
    public function perEsercizio(Esercizio $esercizio, Collection $casse, array $righe, array $esclusi, int $interno): array
    {
        $riga = collect($righe)->keyBy('cassa_id');
        $partizioni = $casse->filter(fn ($c) => in_array($c->tipo, RiportoDiCassa::CASSE_PARTIZIONE, true))->keyBy('id');
        $reali = $casse->reject(fn ($c) => in_array($c->tipo, RiportoDiCassa::CASSE_PARTIZIONE, true))->keyBy('id');
        $cassaDelConto = $casse->mapWithKeys(fn ($c) => [(int) $c->conto_contabile_id => (int) $c->id])->all();

        $banche = $this->bancheCheContano($esercizio, $reali->where('tipo', 'banca'));
        $unica = count($banche) === 1 ? $banche[0] : null;
        $contiBanca = $reali->where('tipo', 'banca')->mapWithKeys(fn ($c) => [(int) $c->conto_contabile_id => (int) $c->id])->all();

        // Le parti delle partizioni: per conto reale (o null, senza una banca) e per partizione, iniziale, entrate e uscite; e per conto reale gli
        // spostamenti che si compensano dentro il conto.
        $parti = [];
        $interni = [];
        // Per conto reale, il denaro andato dalla parte libera ai fondi e quello tornato dai fondi alla parte libera.
        $messi = [];
        $tornati = [];
        // Per ogni cassa reale, le partizioni che le appartengono giorno per giorno: il loro iniziale e la variazione di ogni giorno (le «casse sotto
        // zero» guardano il conto con i fondi dentro).
        $perGiorno = [];
        $aggiungi = function (?int $gruppo, int $p, string $campo, int $cifra) use (&$parti) {
            $parti[$gruppo ?? 0][$p] ??= ['iniziale' => 0, 'entrate' => 0, 'uscite' => 0];
            $parti[$gruppo ?? 0][$p][$campo] += $cifra;
        };

        if ($partizioni->isNotEmpty()) {
            // Le registrazioni dell'esercizio con una riga su una partizione, e le loro righe di cassa, per conto.
            $flussi = $this->righeConPartizioni($esercizio, $partizioni->pluck('conto_contabile_id')->map(fn ($c) => (int) $c)->all(),
                $casse->pluck('conto_contabile_id')->map(fn ($c) => (int) $c)->all(), $esclusi);
            // Il riporto delle partizioni, con più conti in banca: registrazione per registrazione, per sapere da quale banca viene.
            $riporto = $unica === null && $banche !== [] ? $this->riportoDellePartizioni($esercizio, $partizioni, $contiBanca) : [];

            // Di chi è la riga di una partizione in una registrazione: la banca sola, o la banca della registrazione, o nessuna (null), o «da vedere» con
            // i legami (una registrazione fra sole partizioni, con più conti).
            $padrone = function (array $contiReali) use ($unica, $banche, $contiBanca, $cassaDelConto): int|string|null {
                if ($unica !== null) {
                    return $unica;
                }
                if ($banche === []) {
                    return null;
                }
                if ($contiReali === []) {
                    return 'legami';
                }

                return count($contiReali) === 1 && isset($contiBanca[$contiReali[0]]) ? $cassaDelConto[$contiReali[0]] : null;
            };

            // Prima i legami: con quali banche ogni partizione si muove (con più conti).
            $legami = [];
            foreach ([...$riporto, ...$flussi] as $s) {
                $chi = $padrone($s['reali']);
                if (is_int($chi)) {
                    foreach (array_keys($s['partizioni']) as $p) {
                        $legami[$p][$chi] = true;
                    }
                }
            }
            $daiLegami = function (array $parts) use ($legami): ?int {
                $tutte = array_unique(array_map(fn ($p) => count($legami[$p] ?? []) === 1 ? (int) array_key_first($legami[$p]) : null, $parts));

                return count($tutte) === 1 && $tutte[0] !== null ? $tutte[0] : null;
            };

            // Il riporto.
            if ($unica !== null || $banche === []) {
                foreach ($partizioni as $p) {
                    $aggiungi($unica, (int) $p->id, 'iniziale', (int) ($riga[$p->id]['iniziale'] ?? 0));
                }
            } else {
                foreach ($partizioni as $p) {
                    // Il saldo di apertura non ancora a giornale non viene da una banca.
                    $aggiungi(null, (int) $p->id, 'iniziale', (int) $p->saldo_iniziale);
                }
                foreach ($riporto as $s) {
                    $chi = $padrone($s['reali']);
                    $chi = $chi === 'legami' ? $daiLegami(array_keys($s['partizioni'])) : $chi;
                    foreach ($s['partizioni'] as $p => $r) {
                        $aggiungi($chi, $p, 'iniziale', $r['dare'] - $r['avere']);
                    }
                }
            }

            // I flussi dell'esercizio.
            foreach ($flussi as $s) {
                $chi = $padrone($s['reali']);
                $chi = $chi === 'legami' ? $daiLegami(array_keys($s['partizioni'])) : $chi;
                foreach ($s['partizioni'] as $p => $r) {
                    $aggiungi($chi, $p, 'entrate', $r['dare']);
                    $aggiungi($chi, $p, 'uscite', $r['avere']);
                }
                if ($chi !== null) {
                    $perGiorno[$chi][$s['data']] = ($perGiorno[$chi][$s['data']] ?? 0) + array_sum(array_column($s['partizioni'], 'dare')) - array_sum(array_column($s['partizioni'], 'avere'));
                    // Lo spostamento dentro il conto: la parte che si compensa fra la cassa reale e le sue partizioni in questa registrazione.
                    $conto = (int) $reali[$chi]->conto_contabile_id;
                    $dare = ($s['righe'][$conto]['dare'] ?? 0) + array_sum(array_column($s['partizioni'], 'dare'));
                    $avere = ($s['righe'][$conto]['avere'] ?? 0) + array_sum(array_column($s['partizioni'], 'avere'));
                    $interni[$chi] = ($interni[$chi] ?? 0) + min($dare, $avere);
                    // La parte con i soli fondi: dal conto al fondo (un accantonamento), dal fondo al conto (il suo ritorno).
                    $deiFondi = array_filter($s['partizioni'], fn ($p) => $partizioni[$p]->tipo === 'fondo', ARRAY_FILTER_USE_KEY);
                    $messi[$chi] = ($messi[$chi] ?? 0) + min($s['righe'][$conto]['avere'] ?? 0, (int) array_sum(array_column($deiFondi, 'dare')));
                    $tornati[$chi] = ($tornati[$chi] ?? 0) + min($s['righe'][$conto]['dare'] ?? 0, (int) array_sum(array_column($deiFondi, 'avere')));
                }
            }
        }

        $conti = [];
        foreach ($reali->sortBy(fn ($c) => [self::ORDINE_TIPI[$c->tipo] ?? 9, $c->nome])->values() as $c) {
            $r = $riga[$c->id] ?? null;
            if ($r === null) {
                continue;
            }
            $dentro = collect($parti[(int) $c->id] ?? [])->filter(fn ($m) => $m['iniziale'] !== 0 || $m['entrate'] !== 0 || $m['uscite'] !== 0)
                ->map(fn ($m, $p) => self::cifre(['cassa_id' => (int) $p, 'cassa' => (string) $partizioni[$p]->nome, 'tipo' => (string) $partizioni[$p]->tipo,
                    'conto_contabile_id' => (int) $partizioni[$p]->conto_contabile_id] + self::tipoDelFondo($partizioni[$p]), $m))
                ->sortBy('cassa')->values()->all();
            $dentroInterni = $dentro === [] ? 0 : (int) ($interni[(int) $c->id] ?? 0);
            $somma = fn (string $campo) => (int) $r[$campo] + (int) array_sum(array_column($dentro, $campo));
            $libero = self::cifre(['cassa_id' => (int) $c->id, 'cassa' => null, 'tipo' => 'libero', 'conto_contabile_id' => (int) $c->conto_contabile_id,
                'messi_nei_fondi' => $dentro === [] ? 0 : (int) ($messi[(int) $c->id] ?? 0), 'tornati_dai_fondi' => $dentro === [] ? 0 : (int) ($tornati[(int) $c->id] ?? 0)], $r);
            $fondiDentro = array_values(array_filter($dentro, fn ($d) => $d['tipo'] === 'fondo'));
            $conti[] = self::cifre([
                'cassa_id' => (int) $c->id, 'cassa' => (string) $c->nome, 'tipo' => (string) $c->tipo, 'conto_contabile_id' => (int) $c->conto_contabile_id,
                'negativa' => (bool) $r['negativa'], 'minimo' => $r['minimo'], 'minimo_il' => $r['minimo_il'], 'senza_banca' => false, 'interni' => $dentroInterni,
                'di_cui' => $dentro === [] ? [] : [...$dentro, $libero],
                // Quanto si può usare alla fine dell'esercizio, solo per un conto con dei fondi: la parte libera e i fondi che si possono usare.
                'si_puo_usare' => $fondiDentro === [] ? null : TipoDelFondo::quantoSiPuoUsare($libero['finale'], array_map(fn ($d) => [
                    'sottotipo' => $d['sottotipo_fondo'], 'sbloccato' => $d['sbloccato'], 'saldo' => $d['finale']], $fondiDentro)),
            ], ['iniziale' => $somma('iniziale'), 'entrate' => $somma('entrate') - $dentroInterni, 'uscite' => $somma('uscite') - $dentroInterni]);
        }

        // Le partizioni, o le loro parti, che non stanno nel conto di una banca: una riga ciascuna.
        foreach ($partizioni->sortBy(fn ($c) => [self::ORDINE_TIPI[$c->tipo] ?? 9, $c->nome])->values() as $p) {
            $m = $parti[0][(int) $p->id] ?? null;
            if ($m === null || ($m['iniziale'] === 0 && $m['entrate'] === 0 && $m['uscite'] === 0)) {
                continue;
            }
            $conti[] = self::cifre(['cassa_id' => (int) $p->id, 'cassa' => (string) $p->nome, 'tipo' => (string) $p->tipo, 'conto_contabile_id' => (int) $p->conto_contabile_id,
                'negativa' => false, 'minimo' => null, 'minimo_il' => null, 'senza_banca' => true, 'interni' => 0, 'di_cui' => [], 'si_puo_usare' => null]
                + self::tipoDelFondo($p), $m);
        }

        $nelConto = [];
        foreach ($parti as $gruppo => $membri) {
            if ($gruppo !== 0) {
                $nelConto[$gruppo] = ['iniziale' => (int) array_sum(array_column($membri, 'iniziale')), 'giorni' => $perGiorno[$gruppo] ?? []];
            }
        }

        return ['conti' => $conti, 'fra_le_casse' => $interno - (int) array_sum(array_column($conti, 'interni')), 'banca_unica' => $unica, 'partizioni_nel_conto' => $nelConto];
    }

    /**
     * La cassa della banca che tiene tutti i fondi: quella sola che conta, se è una ({@see SaldiDelleBanche::contaIlConto()}); con più conti in banca, o
     * senza, nessuna. La stessa regola per la scheda «Cassa», il registro di contabilità e il controllo della banca.
     */
    public function bancaUnica(Esercizio $esercizio): ?int
    {
        $banche = DB::table('casse')->where('condominio_id', $esercizio->condominio_id)->where('tipo', 'banca')->whereNotNull('conto_contabile_id')
            ->get(['id', 'conto_contabile_id', 'saldo_iniziale', 'attiva']);
        $contano = $this->bancheCheContano($esercizio, $banche);

        return count($contano) === 1 ? $contano[0] : null;
    }

    /**
     * Le parti del saldo di una cassa reale che il registro di contabilità non vede come movimenti di quella cassa, perché il denaro dei fondi non è
     * passato da lì: con un conto in banca solo, il saldo iniziale di un fondo, il denaro registrato direttamente in un fondo, un fondo accantonato da
     * un'altra cassa (una banca poi chiusa). La banca che tiene i fondi li ha in più, la cassa da cui l'accantonamento è partito in meno. Con queste
     * parti il registro dice la cifra della scheda «Cassa» e del controllo della banca, e «Come è composta» le dice una per una.
     *
     * `quando`: «inizio» se la parte c'era il primo giorno dell'esercizio (il registro la conta nel riporto), «esercizio» se è nata durante, con la sua data.
     * Il passaggio fra due fondi non è una parte: resta fra i fondi della stessa banca. Le casse virtuali restano fuori: il registro legge banca e contanti.
     *
     * @return array<int, list<array{voce:string, importo:int, quando:string, data:?string}>>  per cassa reale
     */
    public function partiFuoriDalRegistro(Esercizio $esercizio): array
    {
        $unica = $this->bancaUnica($esercizio);
        $fondi = DB::table('casse')->where('condominio_id', $esercizio->condominio_id)->where('tipo', 'fondo')->whereNotNull('conto_contabile_id')
            ->orderBy('nome')->get(['id', 'nome', 'conto_contabile_id', 'saldo_iniziale']);
        if ($unica === null || $fondi->isEmpty()) {
            return [];
        }
        $reali = DB::table('casse')->where('condominio_id', $esercizio->condominio_id)->whereIn('tipo', RiportoDiCassa::CASSE_REALI)->whereNotNull('conto_contabile_id')
            ->get(['id', 'nome', 'conto_contabile_id']);
        $realeDelConto = $reali->mapWithKeys(fn ($c) => [(int) $c->conto_contabile_id => $c])->all();
        $nomeUnica = (string) $reali->firstWhere('id', $unica)->nome;
        $fondoDelConto = $fondi->mapWithKeys(fn ($f) => [(int) $f->conto_contabile_id => $f])->all();
        $contiFondo = array_keys($fondoDelConto);
        $contiCassa = DB::table('casse')->where('condominio_id', $esercizio->condominio_id)->whereNotNull('conto_contabile_id')->pluck('conto_contabile_id')
            ->map(fn ($c) => (int) $c)->all();

        // Le righe dei fondi del riporto (il primo giorno) e dell'esercizio (con la data), per registrazione.
        $righe = [];
        foreach (app(RiportoDiCassa::class)->perScrittura($esercizio, $contiFondo) as $r) {
            $righe[] = $r + ['quando' => 'inizio', 'data' => null];
        }
        foreach (DB::table('righe_scritture as rs')->join('scritture_contabili as sc', fn ($j) => $j->on('rs.scrittura_id', '=', 'sc.id')->whereNull('sc.deleted_at'))
            ->whereIn('rs.conto_contabile_id', $contiFondo)->where('sc.esercizio_id', $esercizio->id)->where('sc.tipo_movimento', '<>', 'apertura')
            ->groupBy('rs.scrittura_id', 'rs.conto_contabile_id', 'sc.data_competenza')
            ->select('rs.scrittura_id', 'rs.conto_contabile_id', 'sc.data_competenza',
                DB::raw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END), 0) as netto"))->get() as $r) {
            $righe[] = ['scrittura_id' => (int) $r->scrittura_id, 'conto' => (int) $r->conto_contabile_id, 'netto' => (int) $r->netto, 'quando' => 'esercizio',
                'data' => substr((string) $r->data_competenza, 0, 10)];
        }
        if ($righe === [] && (int) $fondi->sum('saldo_iniziale') === 0) {
            return [];
        }
        // Le altre righe delle stesse registrazioni: le casse reali che ci sono, e se c'è una riga fuori dalle casse (un saldo di apertura, un già versato).
        $altre = [];
        foreach (DB::table('righe_scritture')->whereIn('scrittura_id', array_values(array_unique(array_column($righe, 'scrittura_id'))))
            ->whereNotIn('conto_contabile_id', $contiFondo)->get(['scrittura_id', 'conto_contabile_id']) as $r) {
            $conto = (int) $r->conto_contabile_id;
            $altre[(int) $r->scrittura_id]['reali'][$conto] = isset($realeDelConto[$conto]);
            $altre[(int) $r->scrittura_id]['fuori'] = ($altre[(int) $r->scrittura_id]['fuori'] ?? false) || ! in_array($conto, $contiCassa, true);
        }

        $parti = [];
        $aggiungi = function (int $cassa, string $voce, int $importo, string $quando, ?string $data) use (&$parti) {
            if ($importo !== 0) {
                $parti[$cassa][] = ['voce' => $voce, 'importo' => $importo, 'quando' => $quando, 'data' => $data];
            }
        };
        foreach ($fondi as $f) {
            $aggiungi($unica, 'Il saldo iniziale di «'.$f->nome.'»', (int) $f->saldo_iniziale, 'inizio', null);
        }
        foreach ($righe as $r) {
            $fondo = $fondoDelConto[$r['conto']];
            $realiQui = array_keys(array_filter($altre[$r['scrittura_id']]['reali'] ?? []));
            $daQuale = count($realiQui) === 1 ? $realeDelConto[$realiQui[0]] : null;
            $dopo = $r['quando'] === 'esercizio' ? ' nell\'esercizio' : '';
            if ($daQuale !== null && (int) $daQuale->id === $unica) {
                // L'accantonamento dalla banca che tiene i fondi: il registro lo conta già, è denaro di quella banca.
                continue;
            }
            if ($daQuale !== null) {
                $aggiungi($unica, '«'.$fondo->nome.'», accantonato da «'.$daQuale->nome.'»'.$dopo, $r['netto'], $r['quando'], $r['data']);
                $aggiungi((int) $daQuale->id, '«'.$fondo->nome.'», accantonato da qui'.$dopo.': è nel conto «'.$nomeUnica.'»', -$r['netto'], $r['quando'], $r['data']);
            } elseif ($realiQui === [] && ($altre[$r['scrittura_id']]['fuori'] ?? false)) {
                $aggiungi($unica, $r['quando'] === 'inizio' ? 'Il saldo iniziale di «'.$fondo->nome.'»' : 'Il denaro registrato direttamente in «'.$fondo->nome.'»',
                    $r['netto'], $r['quando'], $r['data']);
            }
            // Un passaggio fra due fondi resta fra i fondi della stessa banca: non è una parte.
        }

        return $parti;
    }

    /**
     * Le parti del registro di una cassa, unite per voce: quelle del primo giorno e quelle dell'esercizio fino al giorno dato (tutte, senza un giorno).
     *
     * @param  list<array{voce:string, importo:int, quando:string, data:?string}>  $parti
     * @return list<array{voce:string, importo:int, quando:string}>
     */
    public static function unite(array $parti, ?string $alla = null): array
    {
        $unite = [];
        foreach ($parti as $p) {
            if ($p['quando'] === 'esercizio' && $alla !== null && $p['data'] > $alla) {
                continue;
            }
            $k = $p['quando'].'|'.$p['voce'];
            $unite[$k] = ['voce' => $p['voce'], 'importo' => ($unite[$k]['importo'] ?? 0) + $p['importo'], 'quando' => $p['quando']];
        }

        return array_values(array_filter($unite, fn ($p) => $p['importo'] !== 0));
    }

    /**
     * Il tipo di un fondo, per la sua riga: l'etichetta con il tono, e se il suo denaro si può usare ({@see TipoDelFondo}). Niente per una cassa virtuale.
     *
     * @return array{tipo_fondo?: array{testo:string, tono:string}, sottotipo_fondo?: ?string, sbloccato?: bool, usabile?: bool}
     */
    private static function tipoDelFondo(object $cassa): array
    {
        if ($cassa->tipo !== 'fondo') {
            return [];
        }
        $sottotipo = isset($cassa->sottotipo_fondo) && $cassa->sottotipo_fondo !== '' ? (string) $cassa->sottotipo_fondo : null;
        $sbloccato = (bool) ($cassa->is_override_assemblea ?? false);

        return [
            'tipo_fondo' => TipoDelFondo::etichetta($sottotipo, $cassa->vincolo_descrizione ?? null, $sbloccato, $cassa->motivazione_override ?? null),
            'sottotipo_fondo' => $sottotipo, 'sbloccato' => $sbloccato, 'usabile' => TipoDelFondo::siPuoUsare($sottotipo, $sbloccato),
        ];
    }

    /** @param array{iniziale:int, entrate:int, uscite:int} $m */
    private static function cifre(array $testa, array $m): array
    {
        return $testa + ['iniziale' => (int) $m['iniziale'], 'entrate' => (int) $m['entrate'], 'uscite' => (int) $m['uscite'],
            'finale' => (int) $m['iniziale'] + (int) $m['entrate'] - (int) $m['uscite']];
    }

    /**
     * Le casse della banca che contano, con la regola del controllo della banca: attiva, o con un saldo alla fine dell'esercizio (il conto della cassa nel
     * giornale fino a quel giorno, più il saldo di apertura non ancora a giornale), o con il saldo della banca scritto nella nota. La nota si legge solo
     * per una banca chiusa e a zero, l'unico caso in cui decide.
     *
     * @return list<int>
     */
    private function bancheCheContano(Esercizio $esercizio, Collection $banche): array
    {
        $dopo = $esercizio->data_fine->copy()->addDay()->format('Y-m-d');
        $estratti = null;
        $contano = [];
        foreach ($banche as $b) {
            $saldo = (bool) $b->attiva ? null : (int) $b->saldo_iniziale + (int) DB::table('righe_scritture as rs')
                ->join('scritture_contabili as sc', fn ($j) => $j->on('rs.scrittura_id', '=', 'sc.id')->whereNull('sc.deleted_at'))
                ->where('rs.conto_contabile_id', $b->conto_contabile_id)->where('sc.data_competenza', '<', $dopo)
                ->sum(DB::raw("CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END"));
            if ($saldo === 0) {
                $estratti ??= NotaSinteticaDelRendiconto::contenuto($esercizio)['banca'] ?? [];
            }
            $conEstratto = ($estratti[(string) $b->id]['saldo'] ?? null) !== null;
            if (SaldiDelleBanche::contaIlConto((bool) $b->attiva, (int) $saldo, $conEstratto)) {
                $contano[] = (int) $b->id;
            }
        }

        return $contano;
    }

    /**
     * Le registrazioni dell'esercizio con una riga su una partizione (lo stesso perimetro del prospetto), con le righe di cassa per conto: quelle delle
     * partizioni, per cassa, e le altre, per conto.
     *
     * @param  list<int>  $contiPartizione
     * @param  list<int>  $contiCassa
     * @param  list<int>  $esclusi
     * @return list<array{reali: list<int>, partizioni: array<int, array{dare:int, avere:int}>, righe: array<int, array{dare:int, avere:int}>}>
     */
    private function righeConPartizioni(Esercizio $esercizio, array $contiPartizione, array $contiCassa, array $esclusi): array
    {
        $righe = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', fn ($j) => $j->on('rs.scrittura_id', '=', 'sc.id')->whereNull('sc.deleted_at'))
            ->whereIn('rs.conto_contabile_id', $contiCassa)
            ->where('sc.esercizio_id', $esercizio->id)
            ->where('sc.tipo_movimento', '<>', 'apertura')
            ->whereNotIn('sc.id', $esclusi)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('righe_scritture as rp')->whereColumn('rp.scrittura_id', 'rs.scrittura_id')
                ->whereIn('rp.conto_contabile_id', $contiPartizione))
            ->groupBy('rs.scrittura_id', 'rs.conto_contabile_id', 'sc.data_competenza')
            ->select('rs.scrittura_id', 'rs.conto_contabile_id', 'sc.data_competenza',
                DB::raw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE 0 END), 0) as dare"),
                DB::raw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'avere' THEN rs.importo ELSE 0 END), 0) as avere"))
            ->get();

        return $this->perRegistrazione($righe->map(fn ($r) => ['scrittura_id' => (int) $r->scrittura_id, 'conto' => (int) $r->conto_contabile_id,
            'dare' => (int) $r->dare, 'avere' => (int) $r->avere, 'data' => substr((string) $r->data_competenza, 0, 10)])->all(), $contiPartizione);
    }

    /**
     * Il riporto delle partizioni, registrazione per registrazione, con le righe delle banche nelle stesse registrazioni (per sapere da quale viene).
     *
     * @param  array<int,int>  $contiBanca  conto => cassa
     */
    private function riportoDellePartizioni(Esercizio $esercizio, Collection $partizioni, array $contiBanca): array
    {
        $contiPartizione = $partizioni->pluck('conto_contabile_id')->map(fn ($c) => (int) $c)->all();
        $righe = app(RiportoDiCassa::class)->perScrittura($esercizio, $contiPartizione);
        if ($righe === []) {
            return [];
        }
        $scritture = array_values(array_unique(array_column($righe, 'scrittura_id')));
        $reali = DB::table('righe_scritture as rs')->join('casse as ca', 'ca.conto_contabile_id', '=', 'rs.conto_contabile_id')
            ->whereIn('rs.scrittura_id', $scritture)->whereIn('ca.tipo', RiportoDiCassa::CASSE_REALI)
            ->select('rs.scrittura_id', 'rs.conto_contabile_id')->distinct()->get()
            ->map(fn ($r) => ['scrittura_id' => (int) $r->scrittura_id, 'conto' => (int) $r->conto_contabile_id, 'dare' => 0, 'avere' => 0])->all();

        return $this->perRegistrazione([...array_map(fn ($r) => ['scrittura_id' => $r['scrittura_id'], 'conto' => $r['conto'],
            'dare' => max(0, $r['netto']), 'avere' => max(0, -$r['netto'])], $righe), ...$reali], $contiPartizione);
    }

    /**
     * Le righe per registrazione: i conti reali che ci sono, le partizioni (per cassa) e tutte le righe per conto.
     *
     * @param  list<array{scrittura_id:int, conto:int, dare:int, avere:int}>  $righe
     * @param  list<int>  $contiPartizione
     */
    private function perRegistrazione(array $righe, array $contiPartizione): array
    {
        $cassaDi = DB::table('casse')->whereIn('conto_contabile_id', $contiPartizione)->pluck('id', 'conto_contabile_id')->map(fn ($id) => (int) $id)->all();
        $esito = [];
        foreach ($righe as $r) {
            $s = &$esito[$r['scrittura_id']];
            $s ??= ['reali' => [], 'partizioni' => [], 'righe' => [], 'data' => $r['data'] ?? null];
            if (isset($cassaDi[$r['conto']])) {
                $p = $cassaDi[$r['conto']];
                $s['partizioni'][$p] = ['dare' => ($s['partizioni'][$p]['dare'] ?? 0) + $r['dare'], 'avere' => ($s['partizioni'][$p]['avere'] ?? 0) + $r['avere']];
            } else {
                $s['reali'][] = $r['conto'];
                $s['righe'][$r['conto']] = ['dare' => ($s['righe'][$r['conto']]['dare'] ?? 0) + $r['dare'], 'avere' => ($s['righe'][$r['conto']]['avere'] ?? 0) + $r['avere']];
            }
            unset($s);
        }

        return array_values(array_map(fn ($s) => $s + ['reali' => array_values(array_unique($s['reali']))], $esito));
    }
}
