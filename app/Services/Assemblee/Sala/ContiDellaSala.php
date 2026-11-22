<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Sala;

use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\Maggioranza;

/**
 * I conti della sala (R3), puri: la costituzione per convocazione, l'esito proposto per maggioranza, il conflitto d'interessi determinante, il quinto delle
 * deleghe. **Lo stesso conto esiste in TypeScript** (`resources/js/lib/assemblee/sala/conti.ts`), perché senza rete la barra conta sul posto; tutti e due
 * leggono lo stesso file di casi (`tests/Fixtures/sala/conti.json`).
 *
 * I millesimi sono **centomillesimi interi** (1.000,00 millesimi = 100.000.000), le teste interi. Le soglie si scrivono con **moltiplicazioni intere**, mai
 * divisioni né decimali (art. 1136 c.c.; art. 1117-ter per i quattro quinti; art. 67 c. 1 disp. att. per il quinto delle deleghe):
 *
 * - prima convocazione costituita: due terzi del valore (`3 × v ≥ 2 × T`) e la maggioranza dei partecipanti (`2 × t > P`);
 * - seconda: un terzo del valore (`3 × v ≥ T`) e un terzo dei partecipanti (`3 × t ≥ P`);
 * - «Ordinaria»: la maggioranza degli intervenuti (`2 × f > i`) e metà del valore in prima (`2 × v ≥ T`), un terzo in seconda (`3 × v ≥ T`);
 * - «Metà del valore» (c. 4): `2 × f > i` e `2 × v ≥ T`; «Due terzi» (c. 5): `2 × f > i` e `3 × v ≥ 2 × T`;
 * - «Quattro quinti» (art. 1117-ter): quattro quinti dei **partecipanti** e del valore, `5 × f ≥ 4 × P` e `5 × v ≥ 4 × T`;
 * - «Unanimità»: tutti i partecipanti e tutto il valore;
 * - «Un'altra maggioranza» e una maggioranza non scelta: nessuna proposta, decide il presidente; «Senza voto»: trattato.
 *
 * Il totale del valore è la somma delle quote del perimetro (anche quando la tabella non fa 1.000), il totale dei partecipanti le teste della fotografia.
 *
 * L'esito per condizione ({@see self::condizioni()}) dice a parole i numeri dell'esito, una riga per le teste e una per il valore: non rifà il conto, e lo
 * stesso file di casi lo tiene uguale a quello della pagina.
 */
final class ContiDellaSala
{
    /**
     * La costituzione: costituita o no, quante teste e quanti millesimi servono, quanti mancano, e le frasi della barra.
     *
     * @return array{costituita: bool, servono_teste: int, servono_valore: int, mancano_teste: int, mancano_valore: int, barra: string, servono: string,
     *     mancano: ?string}
     */
    public static function costituzione(string $convocazione, int $totaleTeste, int $totaleValore, int $teste, int $valore): array
    {
        $prima = $convocazione === 'prima';
        $servonoTeste = $prima ? intdiv($totaleTeste, 2) + 1 : self::perEccesso($totaleTeste, 3);
        $servonoValore = $prima ? self::perEccesso(2 * $totaleValore, 3) : self::perEccesso($totaleValore, 3);
        $testeOk = $prima ? 2 * $teste > $totaleTeste : 3 * $teste >= $totaleTeste;
        $valoreOk = $prima ? 3 * $valore >= 2 * $totaleValore : 3 * $valore >= $totaleValore;
        $mancanoTeste = $testeOk ? 0 : max(0, $servonoTeste - $teste);
        $mancanoValore = $valoreOk ? 0 : max(0, $servonoValore - $valore);

        return [
            'costituita' => $testeOk && $valoreOk,
            'servono_teste' => $servonoTeste,
            'servono_valore' => $servonoValore,
            'mancano_teste' => $mancanoTeste,
            'mancano_valore' => $mancanoValore,
            'barra' => $teste . ' su ' . $totaleTeste . ' · ' . self::millesimi($valore) . ' su ' . self::millesimi($totaleValore),
            'servono' => 'servono ' . $servonoTeste . ' e ' . self::millesimiPerEccesso($servonoValore),
            'mancano' => self::mancano($mancanoTeste, $mancanoValore),
        ];
    }

    /**
     * L'esito proposto di un voto: `approvata`, `respinta`, `senza_voto` per il punto senza voto, `null` quando decide il presidente (un'altra maggioranza,
     * o la maggioranza non scelta).
     */
    public static function esito(?string $maggioranza, string $convocazione, int $totaleTeste, int $totaleValore, int $intervenuti, int $favorevoliTeste,
        int $favorevoliValore): ?string
    {
        $m = Maggioranza::tryFrom((string) $maggioranza);
        if ($m === Maggioranza::SENZA_VOTO) {
            return EsitoDelibera::SENZA_VOTO->value;
        }
        if ($m === null || $m === Maggioranza::LIBERA) {
            return null;
        }
        $maggioranzaDegliIntervenuti = 2 * $favorevoliTeste > $intervenuti;
        $passa = match ($m) {
            Maggioranza::ORDINARIA => $maggioranzaDegliIntervenuti && ($convocazione === 'prima'
                ? 2 * $favorevoliValore >= $totaleValore : 3 * $favorevoliValore >= $totaleValore),
            Maggioranza::META_DEL_VALORE => $maggioranzaDegliIntervenuti && 2 * $favorevoliValore >= $totaleValore,
            Maggioranza::DUE_TERZI => $maggioranzaDegliIntervenuti && 3 * $favorevoliValore >= 2 * $totaleValore,
            Maggioranza::QUATTRO_QUINTI => 5 * $favorevoliTeste >= 4 * $totaleTeste && 5 * $favorevoliValore >= 4 * $totaleValore,
            Maggioranza::UNANIMITA => $totaleTeste > 0 && $favorevoliTeste >= $totaleTeste && $favorevoliValore >= $totaleValore,
        };

        return $passa ? EsitoDelibera::APPROVATA->value : EsitoDelibera::RESPINTA->value;
    }

    /**
     * L'esito per condizione, per la pagina del voto: le teste e il valore, una riga ciascuna, con la soglia e quanto manca («Teste: 9 su 11, più della
     * metà: sì», «Valore: 292,00 millesimi, ne servono 333,34: mancano 41,34»). Dice a parole i numeri dell'esito e non lo rifà: i segni sono le stesse
     * moltiplicazioni intere di {@see self::esito()}, le soglie per eccesso come la regola del verbale. Null quando decide il presidente (un'altra
     * maggioranza, o nessuna) e per il punto senza voto. Il verbale non la legge: dice la regola, non i conti che mancano.
     *
     * @return ?list<array{condizione: 'teste'|'valore', passa: bool, frase: string}>
     */
    public static function condizioni(?string $maggioranza, string $convocazione, int $totaleTeste, int $totaleValore, int $intervenuti, int $favorevoliTeste,
        int $favorevoliValore): ?array
    {
        $f = $favorevoliTeste;
        $v = $favorevoliValore;
        $m = Maggioranza::tryFrom((string) $maggioranza);
        [$teste, $valore] = match ($m) {
            Maggioranza::ORDINARIA, Maggioranza::META_DEL_VALORE, Maggioranza::DUE_TERZI => [
                ['su' => $intervenuti, 'servono' => intdiv($intervenuti, 2) + 1, 'passa' => 2 * $f > $intervenuti, 'regola' => 'più della metà'],
                match (true) {
                    $m === Maggioranza::DUE_TERZI => ['servono' => self::perEccesso(2 * $totaleValore, 3), 'passa' => 3 * $v >= 2 * $totaleValore],
                    $m === Maggioranza::ORDINARIA && $convocazione !== 'prima' => ['servono' => self::perEccesso($totaleValore, 3), 'passa' => 3 * $v >= $totaleValore],
                    default => ['servono' => self::perEccesso($totaleValore, 2), 'passa' => 2 * $v >= $totaleValore],
                },
            ],
            Maggioranza::QUATTRO_QUINTI => [
                ['su' => $totaleTeste, 'servono' => self::perEccesso(4 * $totaleTeste, 5), 'passa' => 5 * $f >= 4 * $totaleTeste, 'regola' => 'quattro quinti dei condòmini'],
                ['servono' => self::perEccesso(4 * $totaleValore, 5), 'passa' => 5 * $v >= 4 * $totaleValore],
            ],
            Maggioranza::UNANIMITA => [
                ['su' => $totaleTeste, 'servono' => $totaleTeste, 'passa' => $totaleTeste > 0 && $f >= $totaleTeste, 'regola' => 'tutti'],
                ['servono' => $totaleValore, 'passa' => $v >= $totaleValore],
            ],
            default => [null, null],
        };
        if ($teste === null) {
            return null;
        }
        $mancanoTeste = max(0, $teste['servono'] - $f);
        $no = $mancanoTeste === 0 ? 'no' : 'no, ' . ($mancanoTeste === 1 ? 'manca 1' : 'mancano ' . $mancanoTeste);

        return [
            ['condizione' => 'teste', 'passa' => $teste['passa'], 'frase' => 'Teste: ' . $f . ' su ' . $teste['su'] . ', ' . $teste['regola'] . ': '
                . ($teste['passa'] ? 'sì' : $no)],
            ['condizione' => 'valore', 'passa' => $valore['passa'], 'frase' => 'Valore: ' . self::millesimi($v) . ' millesimi, ne servono '
                . self::millesimiPerEccesso($valore['servono']) . ': ' . ($valore['passa'] ? 'sì' : 'mancano ' . self::millesimiPerEccesso($valore['servono'] - $v))],
        ];
    }

    /**
     * Il conflitto d'interessi che non toglie nessuno dal conto (Cass. 1853/2018): è **determinante** quando, togliendo dai favorevoli le teste e i
     * millesimi segnati in conflitto, l'esito cambia. «Senza i suoi 39,60 millesimi i favorevoli sarebbero 466,80».
     *
     * @return array{determinante: bool, esito: ?string, esito_senza: ?string, favorevoli_senza_teste: int, favorevoli_senza_valore: int, frase: ?string}
     */
    public static function conflitto(?string $maggioranza, string $convocazione, int $totaleTeste, int $totaleValore, int $intervenuti, int $favorevoliTeste,
        int $favorevoliValore, int $conflittoTeste, int $conflittoValore): array
    {
        $esito = self::esito($maggioranza, $convocazione, $totaleTeste, $totaleValore, $intervenuti, $favorevoliTeste, $favorevoliValore);
        $senzaTeste = max(0, $favorevoliTeste - $conflittoTeste);
        $senzaValore = max(0, $favorevoliValore - $conflittoValore);
        if ($conflittoTeste === 0) {
            return ['determinante' => false, 'esito' => $esito, 'esito_senza' => $esito, 'favorevoli_senza_teste' => $favorevoliTeste,
                'favorevoli_senza_valore' => $favorevoliValore, 'frase' => null];
        }
        $esitoSenza = self::esito($maggioranza, $convocazione, $totaleTeste, $totaleValore, $intervenuti, $senzaTeste, $senzaValore);
        $determinante = $esito !== null && $esitoSenza !== $esito;

        return [
            'determinante' => $determinante,
            'esito' => $esito,
            'esito_senza' => $esitoSenza,
            'favorevoli_senza_teste' => $senzaTeste,
            'favorevoli_senza_valore' => $senzaValore,
            'frase' => 'Senza ' . ($conflittoTeste === 1 ? 'i suoi ' : 'i loro ') . self::millesimi($conflittoValore) . ' millesimi i favorevoli sarebbero '
                . self::millesimi($senzaValore) . ': ' . ($determinante ? 'il voto in conflitto è determinante.' : 'l\'esito non cambia.'),
        ];
    }

    /**
     * Il quinto delle deleghe (art. 67 c. 1 disp. att.): con più di venti condòmini un delegato non rappresenta per delega più di un quinto dei condòmini e
     * del valore. Le teste e il valore sono quelli che rappresenta per delega, **senza la sua quota**. «3 condòmini su 5 · 125,70 su 200,00».
     *
     * @return array{si_applica: bool, oltre: bool, oltre_teste: bool, oltre_valore: bool, limite_teste: int, limite_valore: int, frase: ?string}
     */
    public static function quinto(int $totaleTeste, int $totaleValore, int $teste, int $valore): array
    {
        $siApplica = $totaleTeste > 20;
        $oltreTeste = $siApplica && 5 * $teste > $totaleTeste;
        $oltreValore = $siApplica && 5 * $valore > $totaleValore;
        $limiteTeste = intdiv($totaleTeste, 5);
        $limiteValore = intdiv($totaleValore, 5);

        return [
            'si_applica' => $siApplica,
            'oltre' => $oltreTeste || $oltreValore,
            'oltre_teste' => $oltreTeste,
            'oltre_valore' => $oltreValore,
            'limite_teste' => $limiteTeste,
            'limite_valore' => $limiteValore,
            'frase' => $siApplica ? self::condomini($teste) . ' su ' . $limiteTeste . ' · ' . self::millesimi($valore) . ' su '
                . self::millesimi($limiteValore) : null,
        ];
    }

    /** «1 condòmino», «3 condòmini» (rilievo C44: mai «1 condòmini»). */
    public static function condomini(int $quanti): string
    {
        return $quanti === 1 ? '1 condòmino' : $quanti . ' condòmini';
    }

    /** «mancano 3 condòmini e 182,07 millesimi», «manca 1 condòmino», «mancano 0,01 millesimi»; null quando non manca niente. */
    public static function mancano(int $teste, int $valore): ?string
    {
        $pezzi = [];
        if ($teste > 0) {
            $pezzi[] = self::condomini($teste);
        }
        if ($valore > 0) {
            $pezzi[] = self::millesimiPerEccesso($valore) . ' millesimi';
        }
        if ($pezzi === []) {
            return null;
        }
        // Il verbo segue la prima cosa che manca: «manca 1 condòmino» (anche con i millesimi), «mancano 3 condòmini», «mancano 0,01 millesimi».
        $uno = $teste === 1;

        return ($uno ? 'manca ' : 'mancano ') . implode(' e ', $pezzi);
    }

    /** I centomillesimi come si leggono, a due decimali arrotondati: 48.460.000 → «484,60». */
    public static function millesimi(int $centomillesimi): string
    {
        $centesimi = intdiv(abs($centomillesimi) + 500, 1000) * ($centomillesimi < 0 ? -1 : 1);

        return self::dueDecimali($centesimi);
    }

    /** I centomillesimi che servono, a due decimali per eccesso (la soglia si raggiunge): 33.333.334 → «333,34». */
    public static function millesimiPerEccesso(int $centomillesimi): string
    {
        return self::dueDecimali(self::perEccesso(max(0, $centomillesimi), 1000));
    }

    /** Il quoziente per eccesso di due interi positivi. */
    private static function perEccesso(int $a, int $b): int
    {
        return intdiv($a + $b - 1, $b);
    }

    /** Centesimi di millesimo come «1.000,00». */
    private static function dueDecimali(int $centesimi): string
    {
        $segno = $centesimi < 0 ? '-' : '';
        $centesimi = abs($centesimi);

        return $segno . number_format(intdiv($centesimi, 100), 0, ',', '.') . ',' . str_pad((string) ($centesimi % 100), 2, '0', STR_PAD_LEFT);
    }
}
