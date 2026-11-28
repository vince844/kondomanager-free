<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\LetturaConsuntivo;
use App\Services\RegistroModifiche\Gesto;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Support\Facades\DB;

/**
 * Le letture di fine anno dei sottocontatori di una voce (tabella `letture_consuntivo`).
 *
 * Una voce con più tabelle (l'energia elettrica divisa fra ascensore, impianto termico e luci delle scale) si chiede nel preventivo con i coefficienti
 * della voce; a fine anno i sottocontatori dicono quanto ha consumato ognuna. Per ogni tabella si scrive la **lettura** (kWh o m³), oppure la
 * **percentuale**, oppure si sceglie «**per differenza**» (al più una tabella): il contatore generale meno le altre. Il contatore generale è la riga
 * senza tabella. Dalle letture le percentuali: lettura / generale, a due decimali, con il resto del centesimo di punto sull'ultima tabella (o su
 * quella per differenza); una percentuale scritta vale com'è.
 *
 * Il consuntivo (`CalcoloQuoteService::consuntivoPerGestione`) divide la spesa della voce con queste percentuali al posto dei coefficienti; il
 * preventivo e le rate non cambiano: la differenza va nel conguaglio. Chi legge lo stesso numero passa da lì (il consuntivo per persona, il
 * conguaglio del rendiconto, la stampa del riparto per tabella) o lo chiede qui ({@see ControlliDelConguaglio::pesiDelleUnita()}).
 *
 * Le letture si scrivono per esercizio, mai in un esercizio chiuso.
 */
final class LettureDiFineAnno
{
    public const TABELLA = 'letture_consuntivo';

    public const UNITA = ['kWh', 'm³'];

    public const MODI = ['lettura', 'percentuale', 'differenza'];

    /** Due salvataggi della stessa voce nello stesso momento: il secondo trova le righe del primo sull'indice unico (voce, tabella, esercizio). */
    public const SALVATE_INSIEME = 'Le letture di questa voce sono state salvate anche da un\'altra finestra nello stesso momento: '
        . 'riapri la voce, controlla quelle che vedi e salvale di nuovo se servono.';

    public const ESERCIZIO_CHIUSO = 'L\'esercizio è chiuso: le letture di fine anno non si scrivono né si tolgono, il suo rendiconto è già stato presentato.';

    /**
     * Le percentuali del consuntivo dalle righe date, o gli errori.
     *
     * @param  float|null  $generale  la lettura del contatore generale
     * @param  list<array{tabella_id:int, nome?:string, modo:string, lettura?:mixed, percentuale?:mixed}>  $righe  una per tabella della voce, nell'ordine della voce
     * @return array{percentuali: array<int,float>, errori: list<string>, differenza_lettura: ?float}
     */
    public static function calcola(?float $generale, array $righe, string $unita = 'kWh'): array
    {
        $errori = [];
        $nome = fn (array $r) => '«' . ($r['nome'] ?? ('tabella ' . $r['tabella_id'])) . '»';
        $perDifferenza = array_values(array_filter($righe, fn (array $r) => $r['modo'] === 'differenza'));
        if (count($perDifferenza) > 1) {
            $errori[] = 'Al più una tabella si calcola «per differenza»: ' . implode(' e ', array_map($nome, $perDifferenza)) . ' lo sono tutte e due.';
        }
        $conLettura = array_values(array_filter($righe, fn (array $r) => $r['modo'] === 'lettura'));
        foreach ($righe as $r) {
            $valore = $r['modo'] === 'lettura' ? ($r['lettura'] ?? null) : ($r['modo'] === 'percentuale' ? ($r['percentuale'] ?? null) : 0);
            if ($valore === null || $valore === '') {
                $errori[] = 'Manca ' . ($r['modo'] === 'lettura' ? 'la lettura' : 'la percentuale') . ' di ' . $nome($r) . ': scrivila, o scegli «per differenza».';
            }
        }
        if ($conLettura !== [] && ($generale === null || $generale <= 0)) {
            $errori[] = 'Manca la lettura del contatore generale: senza, le letture delle tabelle non diventano percentuali.';
        }
        if ($errori !== []) {
            return ['percentuali' => [], 'errori' => $errori, 'differenza_lettura' => null];
        }

        $sommaLetture = array_sum(array_map(fn (array $r) => (float) $r['lettura'], $conLettura));
        if ($conLettura !== [] && $sommaLetture > (float) $generale + 0.0005) {
            return ['percentuali' => [], 'errori' => ['Le letture delle tabelle fanno ' . self::quantita($sommaLetture, $unita) . ' e superano il contatore generale, '
                . self::quantita((float) $generale, $unita) . ': controlla le letture.'], 'differenza_lettura' => null];
        }

        $grezze = [];
        foreach ($righe as $r) {
            if ($r['modo'] === 'lettura') {
                $grezze[(int) $r['tabella_id']] = 100.0 * (float) $r['lettura'] / (float) $generale;
            } elseif ($r['modo'] === 'percentuale') {
                $grezze[(int) $r['tabella_id']] = (float) $r['percentuale'];
            }
        }
        $altre = array_sum($grezze);
        $differenzaLettura = null;
        if ($perDifferenza !== []) {
            $tid = (int) $perDifferenza[0]['tabella_id'];
            if (round($altre, 2) > 100.0) {
                return ['percentuali' => [], 'errori' => [$nome($perDifferenza[0]) . ' per differenza resterebbe sotto zero: le altre tabelle fanno già '
                    . self::percento($altre) . '.'], 'differenza_lettura' => null];
            }
            $grezze[$tid] = 100.0 - $altre;
            if (count($conLettura) === count($righe) - 1) {
                $differenzaLettura = round((float) $generale - $sommaLetture, 3);
            }
        } elseif (abs(round($altre, 2) - 100.0) > 0.0001) {
            $errore = $conLettura !== [] && count($conLettura) === count($righe) && $sommaLetture < (float) $generale
                ? 'Le letture delle tabelle fanno ' . self::quantita($sommaLetture, $unita) . ', il contatore generale ' . self::quantita((float) $generale, $unita) . ': '
                    . self::quantita((float) $generale - $sommaLetture, $unita) . ' non sono di nessuna tabella. Scegli una tabella «per differenza», o controlla le letture.'
                : 'Le percentuali fanno ' . self::percento($altre) . ', non 100 %: correggi una tabella, o scegline una «per differenza».';

            return ['percentuali' => [], 'errori' => [$errore], 'differenza_lettura' => null];
        }

        // A due decimali, come i coefficienti della voce; il resto del centesimo di punto sulla tabella per differenza, se c'è, se no sull'ultima.
        $percentuali = array_map(fn (float $p) => round($p, 2), $grezze);
        $suDiLei = $perDifferenza !== [] ? (int) $perDifferenza[0]['tabella_id'] : (int) end($righe)['tabella_id'];
        $percentuali[$suDiLei] = round($percentuali[$suDiLei] + 100.0 - array_sum($percentuali), 2);
        if ($percentuali[$suDiLei] < 0) {
            $percentuali[$suDiLei] = 0.0;
        }
        // Nell'ordine delle righe, come la voce.
        $ordinate = [];
        foreach ($righe as $r) {
            $ordinate[(int) $r['tabella_id']] = $percentuali[(int) $r['tabella_id']];
        }

        return ['percentuali' => $ordinate, 'errori' => [], 'differenza_lettura' => $differenzaLettura];
    }

    /**
     * Le percentuali del consuntivo delle voci che ne hanno per l'esercizio: `conto => tabella => percentuale`. Una voce le cui letture non tornano più (una
     * tabella aggiunta o tolta dopo) non c'è: si divide con i coefficienti della voce, e la pagina della voce lo dice.
     *
     * @param  list<int>  $contoIds
     * @return array<int, array<int, float>>
     */
    public static function coefficienti(array $contoIds, int $esercizioId): array
    {
        static $tabellaCe = null;
        $tabellaCe ??= \Illuminate\Support\Facades\Schema::hasTable(self::TABELLA);
        if ($contoIds === [] || ! $tabellaCe) {
            return [];
        }
        $righe = DB::table('letture_consuntivo')->where('esercizio_id', $esercizioId)->whereIn('conto_id', $contoIds)->get()->groupBy('conto_id');
        if ($righe->isEmpty()) {
            return [];
        }
        $legami = DB::table('conto_tabella_millesimale')->whereIn('conto_id', $righe->keys()->all())->orderBy('id')->get(['conto_id', 'tabella_id'])->groupBy('conto_id');
        $esito = [];
        foreach ($righe as $contoId => $sue) {
            $calcolo = self::calcolaDalleRighe($sue->all(), ($legami[$contoId] ?? collect())->pluck('tabella_id')->map(fn ($id) => (int) $id)->all());
            if ($calcolo !== null && $calcolo['errori'] === []) {
                $esito[(int) $contoId] = $calcolo['percentuali'];
            }
        }

        return $esito;
    }

    /**
     * Il calcolo dalle righe salvate, con le tabelle della voce oggi: `null` se le righe non sono più quelle delle tabelle della voce.
     *
     * @param  list<object>  $salvate
     * @param  list<int>  $tabelleDellaVoce
     */
    private static function calcolaDalleRighe(array $salvate, array $tabelleDellaVoce, array $nomi = []): ?array
    {
        $generale = null;
        $perTabella = [];
        $unita = 'kWh';
        foreach ($salvate as $r) {
            $unita = (string) ($r->unita ?? $unita);
            if ($r->tabella_id === null) {
                $generale = $r->lettura === null ? null : (float) $r->lettura;
            } else {
                $perTabella[(int) $r->tabella_id] = $r;
            }
        }
        $chiavi = array_keys($perTabella);
        sort($chiavi);
        $attese = $tabelleDellaVoce;
        sort($attese);
        if ($chiavi !== $attese || $attese === []) {
            return null;
        }
        $righe = [];
        foreach ($tabelleDellaVoce as $tid) {
            $r = $perTabella[$tid];
            $righe[] = ['tabella_id' => $tid, 'nome' => $nomi[$tid] ?? null, 'modo' => self::modoDi($r),
                'lettura' => $r->lettura === null ? null : (float) $r->lettura, 'percentuale' => $r->percentuale === null ? null : (float) $r->percentuale];
        }

        return self::calcola($generale, $righe, $unita);
    }

    private static function modoDi(object $r): string
    {
        return (bool) $r->per_differenza ? 'differenza' : ($r->lettura !== null ? 'lettura' : 'percentuale');
    }

    /**
     * Quello che la pagina della voce mostra: le tabelle con il coefficiente del preventivo, le letture salvate e le percentuali del consuntivo.
     *
     * @return array<string,mixed>
     */
    public static function perLaVoce(Conto $conto, Esercizio $esercizio): array
    {
        $tabelle = DB::table('conto_tabella_millesimale as ctm')->join('tabelle as t', 't.id', '=', 'ctm.tabella_id')->where('ctm.conto_id', $conto->id)
            ->orderBy('ctm.id')->get(['ctm.tabella_id', 't.nome', 'ctm.coefficiente']);
        $salvate = DB::table('letture_consuntivo')->where('conto_id', $conto->id)->where('esercizio_id', $esercizio->id)->get();
        $nomi = $tabelle->mapWithKeys(fn ($t) => [(int) $t->tabella_id => (string) $t->nome])->all();
        $calcolo = $salvate->isEmpty() ? null : self::calcolaDalleRighe($salvate->all(), array_keys($nomi), $nomi);
        $generale = $salvate->firstWhere('tabella_id', null);
        $perTabella = $salvate->whereNotNull('tabella_id')->keyBy('tabella_id');

        return [
            'esercizio_chiuso' => ($esercizio->stato ?? null) === 'chiuso',
            'data_fine' => $esercizio->data_fine === null ? null : substr((string) $esercizio->data_fine, 0, 10),
            'salvate' => $salvate->isNotEmpty(),
            // Salvate per tabelle che la voce non ha più (o senza una che ha adesso): il consuntivo usa i coefficienti della voce finché non si riscrivono.
            'da_riscrivere' => $salvate->isNotEmpty() && $calcolo === null,
            'unita' => (string) ($generale->unita ?? $salvate->first()->unita ?? 'kWh'),
            'generale' => $generale?->lettura === null ? null : (float) $generale->lettura,
            'differenza_lettura' => $calcolo['differenza_lettura'] ?? null,
            'tabelle' => $tabelle->map(function ($t) use ($perTabella, $calcolo) {
                $r = $perTabella[(int) $t->tabella_id] ?? null;

                return [
                    'tabella_id' => (int) $t->tabella_id,
                    'nome' => (string) $t->nome,
                    'preventivo' => (float) $t->coefficiente,
                    'modo' => $r === null ? 'lettura' : self::modoDi($r),
                    'lettura' => $r?->lettura === null ? null : (float) $r->lettura,
                    'percentuale' => $r?->percentuale === null ? null : (float) $r->percentuale,
                    'consuntivo' => $calcolo['percentuali'][(int) $t->tabella_id] ?? null,
                ];
            })->values()->all(),
        ];
    }

    /**
     * Scrive le letture della voce per l'esercizio, al posto di quelle che c'erano. `$dati` è già validato dalla richiesta.
     *
     * @param  array{unita:string, generale:mixed, tabelle: list<array{tabella_id:int, modo:string, lettura?:mixed, percentuale?:mixed}>}  $dati
     */
    public static function salva(Conto $conto, Esercizio $esercizio, array $dati): void
    {
        RegistroModifiche::durante(self::gesto($conto, $esercizio, 'salvate'), function () use ($conto, $esercizio, $dati) {
            self::cancella($conto, $esercizio);
            $base = ['conto_id' => $conto->id, 'esercizio_id' => $esercizio->id, 'unita' => $dati['unita']];
            $usaIlGenerale = collect($dati['tabelle'])->contains(fn ($t) => $t['modo'] === 'lettura');
            if ($usaIlGenerale) {
                LetturaConsuntivo::create($base + ['tabella_id' => null, 'lettura' => $dati['generale'], 'percentuale' => null, 'per_differenza' => false]);
            }
            foreach ($dati['tabelle'] as $t) {
                LetturaConsuntivo::create($base + [
                    'tabella_id' => (int) $t['tabella_id'],
                    'lettura' => $t['modo'] === 'lettura' ? $t['lettura'] : null,
                    'percentuale' => $t['modo'] === 'percentuale' ? $t['percentuale'] : null,
                    'per_differenza' => $t['modo'] === 'differenza',
                ]);
            }
        });
    }

    /** Toglie le letture della voce per l'esercizio: il consuntivo torna ai coefficienti della voce. */
    public static function togli(Conto $conto, Esercizio $esercizio): void
    {
        RegistroModifiche::durante(self::gesto($conto, $esercizio, 'tolte'), fn () => self::cancella($conto, $esercizio));
    }

    /** Il gesto del registro delle modifiche: una riga sola sulla voce, con l'esercizio; le righe delle letture si contano nel lotto. */
    private static function gesto(Conto $conto, Esercizio $esercizio, string $fatto): Gesto
    {
        return Gesto::sulModello($conto)->riepilogo('Letture di fine anno della voce «' . $conto->nome . '» ' . $fatto . ', esercizio «' . $esercizio->nome . '»');
    }

    /**
     * Le letture della voce per l'esercizio, tolte una per una (ognuna si conta nel gesto). Prima si blocca la riga della voce: due salvataggi della
     * stessa voce si mettono in fila, e il secondo legge (con il blocco) e toglie le righe che il primo ha scritto, invece di scontrarsi con loro
     * sull'indice unico.
     */
    private static function cancella(Conto $conto, Esercizio $esercizio): void
    {
        Conto::query()->whereKey($conto->id)->lockForUpdate()->first(['id']);
        LetturaConsuntivo::query()->where('conto_id', $conto->id)->where('esercizio_id', $esercizio->id)->lockForUpdate()->get()->each->delete();
    }

    /** «10.000 kWh», all'italiana, senza decimali inutili. */
    public static function quantita(float $v, string $unita = 'kWh'): string
    {
        return rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',') . ' ' . $unita;
    }

    /** «99,99 %». */
    public static function percento(float $v): string
    {
        return rtrim(rtrim(number_format(round($v, 2), 2, ',', '.'), '0'), ',') . ' %';
    }
}
