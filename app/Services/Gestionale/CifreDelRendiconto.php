<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\RendicontoVersione;
use App\Models\Immobile;
use App\Settings\PrintSettings;
use Carbon\CarbonInterface;

/**
 * Le cifre di un rendiconto fermato per l'assemblea, nella forma {@see RendicontoVersione::FORMA}: ciò che un programma deve poter rileggere della
 * versione (la chiusura dell'esercizio, il confronto con le cifre di oggi), non ciò che serve a ristamparla. Il documento di riferimento è il PDF.
 *
 * UNA FONTE PER NUMERO. Ogni cifra viene dalla lettura che la scheda fa già, nella stessa richiesta: i controlli con la situazione patrimoniale, il
 * riepilogo e le posizioni ({@see ControlliDelRendiconto}), la sintesi con il suo prospetto ({@see SintesiDelRendiconto}), il consuntivo per persona
 * dalla lettura del conguaglio ({@see ConguaglioDelRendiconto::leggi()}) e i giorni dalle righe del conguaglio, il registro di contabilità
 * ({@see RegistroContabilitaService}). Qui non si calcola niente: si sceglie e si copia.
 *
 * LA FORMA 3 (la 1 senza `giornale`):
 * - `intestazione`: il condominio, l'esercizio e lo studio di quel giorno; `alla` (il giorno della lettura), `finito`, `calcolato_il` (giorno e ora);
 * - `prospetto`: le cifre del prospetto della gestione della Sintesi; `esito`: quanti controlli tornano, non tornano, sono da leggere o non calcolati;
 * - `riepilogo`: per gestione le voci con il preventivo e il deliberato di quel giorno, il consuntivo, l'incassato, l'anno prima e lo scostamento;
 *   le spese fuori dai capitoli;
 * - `situazione`: i totali della situazione patrimoniale e il movimento dei fondi;
 * - `controlli`: ogni controllo con l'esito e le due parti dell'uguaglianza;
 * - `conguaglio`: per gestione le uscite, le voci ferme, le non attribuite, i periodi diversi, il già versato da decidere, l'opera e i totali, e la
 *   tabella per voce dell'uguaglianza «uscite + voci ferme = persone + non attribuite» con le voci che non la rispettano (`invariante`); per persona,
 *   unità e gestione A, le spese personali, B con i suoi termini, il conguaglio, la posizione, i saldi fuori dalle rate, il saldo, il già versato
 *   non usato e quello da decidere, i giorni (`giorni` con un ruolo solo, `giorni_per_ruolo` con più ruoli); le righe da altre gestioni; la riga del
 *   condominio;
 * - `registro`: le scritture del registro di contabilità nell'ordine dei numeri di quel giorno, con un'impronta breve per riga;
 * - `giornale` (dalla forma 3): l'impronta del giornale che le schede leggono, in tutto, per registrazione e prima dell'esercizio
 *   ({@see ConfrontoDelRendiconto::giornale()}).
 *
 * Le righe di spiegazione delle persone non si fermano (nel condominio grande pesano decine di MB): il foglio di una persona di una versione si legge dal
 * suo PDF, se è stato mandato. La nota sintetica sta nella sua colonna della versione, non qui.
 */
final class CifreDelRendiconto
{
    public function __construct(
        private readonly ControlliDelRendiconto $controlli,
        private readonly SintesiDelRendiconto $sintesi,
        private readonly ConguaglioDelRendiconto $conguaglio,
        private readonly RegistroContabilitaService $registro,
    ) {}

    /**
     * @param  array<string,mixed>  $letto  la lettura del conguaglio della stessa richiesta ({@see ConguaglioDelRendiconto::leggi()})
     * @param  CarbonInterface  $adesso  il giorno e l'ora della versione, nel fuso dell'amministratore
     * @param  array<string,mixed>|null  $banca  il saldo dell'estratto conto per cassa, se non quello della nota di oggi (le cifre rifatte per una versione)
     * @param  array<string,mixed>|null  $controlli  i controlli già letti nella stessa richiesta con la stessa lettura del conguaglio (la Sintesi): non si rifanno
     * @return array<string,mixed>
     */
    public function perEsercizio(Condominio $condominio, Esercizio $esercizio, array $letto, CarbonInterface $adesso, ?array $banca = null, ?array $controlli = null): array
    {
        $oggi = $adesso->format('Y-m-d');
        $c = $controlli ?? $this->controlli->perEsercizio($condominio, $esercizio, $oggi, [], null, null, [], $letto, $banca);
        $s = $this->sintesi->perEsercizio($condominio, $esercizio, $oggi, $c);
        $r = $c['riepilogo'];
        $p = $c['pagina'];
        $studio = app(PrintSettings::class);

        return [
            'forma' => RendicontoVersione::FORMA,
            'intestazione' => [
                'condominio' => ['id' => (int) $condominio->id, 'nome' => (string) $condominio->nome, 'indirizzo' => $condominio->indirizzo, 'codice_fiscale' => $condominio->codice_fiscale],
                'esercizio' => ['id' => (int) $esercizio->id, 'nome' => (string) $esercizio->nome, 'dal' => $r['esercizio']['dal'], 'al' => $r['esercizio']['al']],
                'studio' => ['nome' => $studio->studio_nome, 'indirizzo' => $studio->studio_indirizzo, 'comune' => $studio->studio_comune,
                    'telefono' => $studio->studio_telefono, 'email' => $studio->studio_email, 'pec' => $studio->studio_pec],
            ],
            'alla' => $r['alla'],
            'finito' => (bool) $r['finito'],
            'calcolato_il' => $adesso->toIso8601String(),
            'prospetto' => array_intersect_key($s['prospetto'], array_flip(['preventivo', 'deliberato', 'quote_piani', 'quote_pagate', 'quote_non_pagate', 'costi',
                'quote_giornale', 'risultato', 'in_fondi'])),
            'esito' => array_intersect_key($s['esito'], array_flip(['tornano', 'non_tornano', 'da_leggere', 'non_calcolati'])),
            'riepilogo' => self::riepilogo($r),
            'situazione' => self::situazione($p),
            'controlli' => array_map(fn (array $x) => ['id' => $x['id'], 'nome' => $x['nome'], 'esito' => $x['esito'], 'sinistra' => $x['sinistra'] ?? null,
                'destra' => $x['destra'] ?? null], $c['controlli']),
            'conguaglio' => $this->conguaglio($esercizio, $letto),
            'registro' => $this->registro($esercizio),
            'giornale' => ConfrontoDelRendiconto::giornale($esercizio),
        ];
    }

    /** Il riepilogo per voce: le voci dei capitoli in fila, ognuna con il suo capitolo. */
    private static function riepilogo(array $r): array
    {
        $voce = fn (array $v, ?int $capitolo) => ['voce_id' => (int) $v['voce_id'], 'capitolo_id' => $capitolo, 'nome' => (string) $v['nome'], 'tipo' => (string) $v['tipo'],
            'preventivo' => $v['preventivo'] ?? null, 'deliberato' => $v['deliberato'] ?? null, 'consuntivo' => $v['consuntivo'] ?? null, 'incassato' => $v['incassato'] ?? null,
            'anno_prima' => $v['anno_prima'] ?? null, 'scostamento' => $v['scostamento'] ?? null, 'scostamento_preventivo' => $v['scostamento_preventivo'] ?? null];
        $gestioni = [];
        foreach ($r['blocchi'] as $b) {
            $voci = [];
            foreach ([...$b['spese'], ...$b['entrate']] as $capitolo) {
                if (isset($capitolo['voci'])) {
                    foreach ($capitolo['voci'] as $v) {
                        $voci[] = $voce($v, (int) $capitolo['voce_id']);
                    }
                } else {
                    $voci[] = $voce($capitolo, null);
                }
            }
            $gestioni[] = [
                'gestione_id' => (int) $b['gestione']['id'], 'nome' => (string) $b['gestione']['nome'], 'tipo' => (string) $b['gestione']['tipo'],
                'deliberato_stato' => (string) $b['deliberato_stato'], 'voci' => $voci,
                'totale_spese' => array_intersect_key($b['totale_spese'], array_flip(['preventivo', 'deliberato', 'consuntivo'])),
                'totale_entrate' => array_intersect_key($b['totale_entrate'], array_flip(['preventivo', 'deliberato', 'incassato'])),
            ];
        }

        return ['gestioni' => $gestioni, 'fuori_capitoli' => array_map(fn ($f) => (int) ($f['consuntivo'] ?? 0),
            array_intersect_key($r['fuori_capitoli'], array_flip(['commissioni_senza_voce', 'contestate', 'non_classificati'])))];
    }

    /** I totali della situazione patrimoniale e il movimento dei fondi. */
    private static function situazione(array $p): array
    {
        return [
            'data' => $p['data'],
            'attivo' => (int) $p['situazione']['attivo']['totale'],
            'passivo' => (int) $p['situazione']['passivo']['totale'],
            'costi' => (int) $p['situazione']['costi'],
            'ricavi' => (int) $p['situazione']['ricavi'],
            'liquidita' => (int) $p['sintesi']['liquidita'],
            'in_fondi' => (int) $p['sintesi']['in_fondi'],
            'crediti_condomini' => (int) $p['sintesi']['crediti_condomini'],
            'debiti_fornitori' => (int) $p['sintesi']['debiti_fornitori'],
            'debiti_erario' => (int) $p['sintesi']['debiti_erario'],
            'fondi' => array_map(fn (array $f) => ['cassa_id' => (int) $f['cassa_id'], 'cassa' => (string) $f['cassa'], 'iniziale' => (int) $f['iniziale'],
                'accantonato' => (int) $f['accantonato'], 'utilizzato' => (int) $f['utilizzato'], 'finale' => (int) $f['finale']], $p['movimento_fondi']['righe']),
        ];
    }

    /**
     * Il conguaglio per persona, unità e gestione: le cifre dal consuntivo per persona, i giorni dalle righe del conguaglio. Il nome della persona e
     * quello dell'unità di quel giorno, per chi confronta la versione con oggi.
     */
    private function conguaglio(Esercizio $esercizio, array $letto): array
    {
        $aids = [];
        $iids = [];
        foreach ([...array_values($letto['persone']), $letto['da_altre_gestioni']['persone'] ?? []] as $perPersona) {
            foreach ($perPersona as $aid => $perUnita) {
                $aids[(int) $aid] = true;
                foreach (array_keys($perUnita) as $iid) {
                    $iids[(int) $iid] = true;
                }
            }
        }
        $nomi = [];
        foreach (array_chunk(array_keys($aids), 1000) as $ids) {
            $nomi += Anagrafica::whereIn('id', $ids)->pluck('nome', 'id')->all();
        }
        $unita = [];
        foreach (array_chunk(array_keys($iids), 1000) as $ids) {
            foreach (Immobile::whereIn('id', $ids)->get() as $i) {
                $unita[(int) $i->id] = (string) $i->etichetta;
            }
        }
        return self::conguaglioDallaLettura($letto, $this->conguaglio->giorniDelleRighe($esercizio, array_keys($iids)), $nomi, $unita);
    }

    /**
     * La parte `conguaglio` delle cifre, dalla lettura e dai giorni delle righe: si copia e basta, nessuna cifra nuova.
     *
     * @param  array<int, array<int, array{giorni:?int, giorni_per_ruolo:list<array{ruolo:string, giorni:int}>}>>  $giorni  persona => unità => giorni della riga
     * @param  array<int,string>  $nomi  persona => nome
     * @param  array<int,string>  $unita  unità => etichetta
     */
    public static function conguaglioDallaLettura(array $letto, array $giorni, array $nomi, array $unita): array
    {
        $gestioni = [];
        $righe = [];
        foreach ($letto['gestioni'] as $gid => $g) {
            $tabella = [];
            $giaVersatoUnita = 0;
            foreach ($letto['persone'][$gid] ?? [] as $aid => $perUnita) {
                foreach ($perUnita as $iid => $x) {
                    foreach ($x['voci'] ?? [] as $conto => $centesimi) {
                        $tabella[(int) $conto]['persone'] = ($tabella[(int) $conto]['persone'] ?? 0) + (int) $centesimi;
                    }
                    $giaVersatoUnita += (int) ($x['gia_versato_unita'] ?? 0);
                    $b = $x['b'] ?? [];
                    $gg = $giorni[$aid][$iid] ?? ['giorni' => null, 'giorni_per_ruolo' => []];
                    $righe[] = [
                        'gestione_id' => (int) $gid, 'anagrafica_id' => (int) $aid, 'nome' => (string) ($nomi[$aid] ?? ''), 'immobile_id' => (int) $iid,
                        'unita' => $unita[$iid] ?? null,
                        'a' => (int) $x['a'], 'personali' => (int) $x['personali']['non_chieste'],
                        'b' => ['quote' => (int) ($b['quote'] ?? 0), 'di_cui_non_emesse' => (int) ($b['di_cui_non_emesse'] ?? 0), 'coppie' => (int) ($b['coppie'] ?? 0),
                            'regolato' => (int) ($b['regolato'] ?? 0), 'gia_versato_persona' => (int) ($b['gia_versato_persona'] ?? 0), 'totale' => $b['totale'] ?? null],
                        'conguaglio' => $x['conguaglio'], 'posizione' => (int) $x['posizione'], 'fuori_dalle_rate' => (int) $x['fuori_dalle_rate'], 'saldo' => $x['saldo'],
                        'non_disponibile' => $x['non_disponibile'],
                        'gia_versato_non_usato' => ['questo_esercizio' => (int) $x['gia_versato_non_usato']['questo_esercizio'],
                            'tutti_gli_esercizi' => (int) $x['gia_versato_non_usato']['tutti_gli_esercizi']],
                        'gia_versato_da_decidere' => (int) $x['gia_versato_da_decidere'], 'gia_versato_da_decidere_perche' => $x['gia_versato_da_decidere_perche'] ?? null,
                        'giorni' => $gg['giorni'], 'giorni_per_ruolo' => $gg['giorni_per_ruolo'],
                    ];
                }
            }
            foreach (['uscite', 'voci_ferme', 'non_attribuite'] as $campo) {
                foreach ($g[$campo] ?? [] as $conto => $centesimi) {
                    $tabella[(int) $conto][$campo] = (int) $centesimi;
                }
            }
            ksort($tabella);
            $perVoce = [];
            foreach ($tabella as $conto => $t) {
                $perVoce[] = ['conto_id' => $conto, 'uscite' => $t['uscite'] ?? 0, 'voci_ferme' => $t['voci_ferme'] ?? 0, 'persone' => $t['persone'] ?? 0,
                    'non_attribuite' => $t['non_attribuite'] ?? 0];
            }
            $gestioni[] = [
                'gestione_id' => (int) $gid, 'nome' => (string) $g['nome'], 'tipo' => (string) $g['tipo'], 'non_calcolabile' => $g['non_calcolabile'] ?? null,
                'in_corso' => $g['in_corso'] ?? null, 'opera_finita' => $g['opera_finita'] ?? null,
                'uscite' => self::perId($g['uscite'] ?? []), 'voci_ferme' => self::perId($g['voci_ferme'] ?? []),
                'non_attribuite' => self::perId($g['non_attribuite'] ?? []), 'periodi_diversi' => self::perId($g['periodi_diversi'] ?? [], 'piani'),
                'periodi_diversi_perche' => self::perId($g['periodi_diversi_perche'] ?? [], 'perche'),
                'gia_versato_da_decidere' => self::perId($g['gia_versato_da_decidere'] ?? []),
                'gia_versato_unita' => $giaVersatoUnita,
                'per_voce' => $perVoce,
                'invariante' => self::invariante($perVoce, $giaVersatoUnita),
                'totali' => array_map('intval', array_intersect_key($g['totali'] ?? [], array_flip(['a', 'personali', 'b', 'conguaglio', 'posizione', 'fuori_dalle_rate',
                    'saldo', 'non_disponibili']))),
            ];
        }

        $altre = [];
        foreach ($letto['da_altre_gestioni']['persone'] ?? [] as $aid => $perUnita) {
            foreach ($perUnita as $iid => $x) {
                $altre[] = ['anagrafica_id' => (int) $aid, 'nome' => (string) ($nomi[$aid] ?? ''), 'immobile_id' => (int) $iid, 'unita' => $unita[$iid] ?? null,
                    'posizione' => (int) $x['posizione'], 'personali' => (int) $x['personali']['non_chieste'], 'conguaglio' => (int) $x['conguaglio'], 'saldo' => (int) $x['saldo']];
            }
        }

        return ['gestioni' => $gestioni, 'righe' => $righe, 'da_altre_gestioni' => $altre,
            'condominio' => array_map('intval', ConguaglioDelRendiconto::totaliDelCondominio($letto))];
    }

    /**
     * Le voci per cui «uscite + voci ferme = persone + non attribuite» non torna al centesimo. La spesa delle persone è dopo il già versato dell'unità,
     * che la lettura toglie voce per voce e conserva solo per riga: senza già versato dell'unità l'uguaglianza si guarda voce per voce, con il già
     * versato sulla gestione intera (le voci della gestione insieme, `*`).
     *
     * @param  list<array{conto_id:int, uscite:int, voci_ferme:int, persone:int, non_attribuite:int}>  $perVoce
     * @return list<int|string>
     */
    public static function invariante(array $perVoce, int $giaVersatoUnita): array
    {
        if ($giaVersatoUnita === 0) {
            return array_values(array_map(fn ($t) => $t['conto_id'],
                array_filter($perVoce, fn ($t) => $t['uscite'] + $t['voci_ferme'] !== $t['persone'] + $t['non_attribuite'])));
        }
        $somma = fn (string $k) => (int) array_sum(array_column($perVoce, $k));

        return $somma('uscite') + $somma('voci_ferme') === $somma('persone') + $giaVersatoUnita + $somma('non_attribuite') ? [] : ['*'];
    }

    /** Una mappa id => valore (una voce, un'unità) come elenco, così le chiavi della forma non sono gli id. */
    private static function perId(array $mappa, string $chiave = 'importo'): array
    {
        $elenco = [];
        foreach ($mappa as $id => $valore) {
            $elenco[] = ['id' => (int) $id, $chiave => is_int($valore) || is_numeric($valore) ? (int) $valore : $valore];
        }

        return $elenco;
    }

    /** Le scritture del registro di contabilità nell'ordine dei numeri di oggi, con un'impronta breve di ciò che le identifica. */
    private function registro(Esercizio $esercizio): array
    {
        return array_map(fn (array $r) => [
            'numero' => (int) $r['numero'], 'scrittura_id' => (int) $r['scrittura_id'], 'riga_id' => (int) $r['id'], 'data' => (string) $r['data'],
            'entrata' => $r['entrata'], 'uscita' => $r['uscita'],
            'impronta' => substr(hash('sha256', implode('|', [$r['scrittura_id'], $r['id'], $r['data'], $r['data_annotazione'], $r['entrata'] ?? '', $r['uscita'] ?? '',
                $r['cassa'] ?? '', $r['tipo_movimento'] ?? '', $r['stato'] ?? '', $r['stornata'] ? 1 : 0])), 0, 16),
        ], $this->registro->registro($esercizio));
    }
}
