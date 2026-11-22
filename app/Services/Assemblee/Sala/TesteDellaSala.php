<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Sala;

use App\Enums\Assemblee\AmministrazionePunto;
use App\Enums\Assemblee\ConduttorePunto;
use App\Enums\Assemblee\RuoloDiVoto;
use App\Enums\RuoloAnagraficaImmobile;
use App\Models\ImpostazioniCondominio;

/**
 * Le teste della sala (R2): chi vota per un'unità su un punto, chi la porta nella costituzione, e come le persone diventano teste.
 *
 * - **Una persona è una testa** anche con più unità (Cass. 25558/2020).
 * - **L'unità in comproprietà indivisa è una testa**, con un rappresentante designato dai comproprietari (art. 67 c. 2 disp. att.): se è presente uno
 *   solo, rappresenta lui.
 * - **Il caso misto** (Rossi con l'Int. 3 da solo e l'Int. 5 a metà con Bianchi) segue la scelta del condominio: `due_teste`, di serie, conta Rossi per
 *   l'Int. 3 e l'Int. 5 a parte, e la comproprietà con le stesse persone resta una testa (le comunioni nate da titoli diversi restano distinte anche
 *   nell'assemblea); `una_testa` conta Rossi e Bianchi una testa sola con le due unità, per il condominio che ha una regola sua.
 * - **L'usufrutto** (art. 67 c. 6 e 7 disp. att.): sull'ordinaria vota l'usufruttuario, sulla straordinaria il nudo proprietario, salvo i casi che sceglie
 *   il presidente (l'art. 1006 c.c., i lavori degli artt. 985 e 986, un affare di semplice godimento). Nella costituzione l'unità è una testa sola, con chi
 *   vota su almeno un punto: tutti e due con punti dei due tipi, il solo usufruttuario con soli punti ordinari.
 * - **Il conduttore** non è una testa della costituzione; sul punto dove vota (art. 10 L. 392/1978) prende il posto del proprietario con i millesimi
 *   dell'unità. Quello che interviene è presente senza voto.
 *
 * Le chiavi delle teste sono stabili nella seduta: `p-<persone>` per una testa di persone, `u-<unità>` per una comproprietà contata a parte.
 */
final class TesteDellaSala
{
    public const UNA_TESTA = ImpostazioniCondominio::UNA_TESTA;

    public const DUE_TESTE = ImpostazioniCondominio::DUE_TESTE;

    /**
     * Chi vota per un'unità su un punto, e con quale ruolo.
     *
     * @param list<array{anagrafica_id: int, ruolo: string, quota?: ?float}> $titolari i titolari attivi al giorno della seduta
     * @param ?string $usufruttuario il perché dell'art. 67 c. 7 quando il presidente fa votare l'usufruttuario su un punto straordinario
     * @return array{persone: list<int>, ruolo: string}
     */
    public static function chiVota(array $titolari, ?string $amministrazione, ?string $conduttore, ?string $usufruttuario): array
    {
        $di = fn (RuoloAnagraficaImmobile $r) => self::ordinate(array_map(fn ($t) => (int) $t['anagrafica_id'],
            array_filter($titolari, fn ($t) => $t['ruolo'] === $r->value)));
        $inquilini = $di(RuoloAnagraficaImmobile::INQUILINO);
        if ($conduttore === ConduttorePunto::VOTA->value && $inquilini !== []) {
            return ['persone' => $inquilini, 'ruolo' => RuoloDiVoto::CONDUTTORE->value];
        }
        $usufruttuari = $di(RuoloAnagraficaImmobile::USUFRUTTUARIO);
        $nudi = $di(RuoloAnagraficaImmobile::NUDA_PROPRIETA);
        $proprietari = $di(RuoloAnagraficaImmobile::PROPRIETARIO);
        if ($usufruttuari !== []) {
            if ($amministrazione === AmministrazionePunto::STRAORDINARIA->value && $usufruttuario === null && ($nudi !== [] || $proprietari !== [])) {
                return ['persone' => self::ordinate([...$nudi, ...$proprietari]), 'ruolo' => RuoloDiVoto::NUDO_PROPRIETARIO->value];
            }

            return ['persone' => $usufruttuari, 'ruolo' => RuoloDiVoto::USUFRUTTUARIO->value];
        }
        if ($nudi !== []) {
            return ['persone' => self::ordinate([...$nudi, ...$proprietari]), 'ruolo' => RuoloDiVoto::NUDO_PROPRIETARIO->value];
        }

        return ['persone' => $proprietari, 'ruolo' => RuoloDiVoto::PROPRIETARIO->value];
    }

    /**
     * Chi porta l'unità nella costituzione: chi vota su almeno uno dei punti con voto (date le loro amministrazioni), mai il conduttore. Senza punti con
     * voto vale l'ordinaria.
     *
     * @param list<array{anagrafica_id: int, ruolo: string}> $titolari
     * @param list<string> $amministrazioni le amministrazioni dei punti con voto
     * @return list<int>
     */
    public static function chiCostituisce(array $titolari, array $amministrazioni): array
    {
        $amministrazioni = $amministrazioni === [] ? [AmministrazionePunto::ORDINARIA->value] : array_values(array_unique($amministrazioni));
        $persone = [];
        foreach ($amministrazioni as $amministrazione) {
            $persone = [...$persone, ...self::chiVota($titolari, $amministrazione, null, null)['persone']];
        }

        return self::ordinate($persone);
    }

    /**
     * Le teste dalle unità con chi le porta: ognuna con la chiave, il nome («Russo Anna e Russo Marco»), le persone, le unità e i millesimi (centomillesimi).
     * Un'unità senza nessuno non è una testa. In ordine di nome.
     *
     * @param array<int, array{persone: list<int>, millesimi: int}> $unita immobile => chi la porta e i millesimi
     * @param array<int, string> $nomi persona => nome
     * @return list<array{chiave: string, nome: string, persone: list<int>, unita: list<int>, millesimi: int}>
     */
    public static function teste(array $unita, string $modo, array $nomi): array
    {
        $unita = array_filter($unita, fn ($u) => $u['persone'] !== []);
        $gruppi = $modo === self::DUE_TESTE ? self::dueTeste($unita) : self::unaTesta($unita);
        $teste = [];
        foreach ($gruppi as $chiave => [$persone, $diQuesta]) {
            sort($diQuesta);
            $nomiDelle = array_map(fn ($p) => (string) ($nomi[$p] ?? ('Persona ' . $p)), $persone);
            usort($nomiDelle, fn ($x, $y) => strcmp(mb_strtolower($x), mb_strtolower($y)));
            $teste[] = [
                'chiave' => self::chiave($chiave),
                'nome' => self::elenco($nomiDelle),
                'persone' => $persone,
                'unita' => array_values($diQuesta),
                'millesimi' => array_sum(array_map(fn ($u) => (int) $unita[$u]['millesimi'], $diQuesta)),
            ];
        }
        usort($teste, fn ($x, $y) => [mb_strtolower($x['nome']), $x['chiave']] <=> [mb_strtolower($y['nome']), $y['chiave']]);

        return $teste;
    }

    /** «Una testa»: le persone legate da un'unità in comune sono una testa sola (le componenti connesse). @return array<string, array{0: list<int>, 1: list<int>}> */
    private static function unaTesta(array $unita): array
    {
        $padre = [];
        $trova = function (int $p) use (&$padre, &$trova): int {
            $padre[$p] ??= $p;

            return $padre[$p] === $p ? $p : ($padre[$p] = $trova($padre[$p]));
        };
        foreach ($unita as $u) {
            $prima = $trova((int) $u['persone'][0]);
            foreach ($u['persone'] as $p) {
                $padre[$trova((int) $p)] = $prima;
            }
        }
        $gruppi = [];
        foreach ($unita as $id => $u) {
            $radice = $trova((int) $u['persone'][0]);
            $gruppi[$radice] ??= [[], []];
            $gruppi[$radice][0] = [...$gruppi[$radice][0], ...array_map('intval', $u['persone'])];
            $gruppi[$radice][1][] = (int) $id;
        }
        $perChiave = [];
        foreach ($gruppi as [$persone, $diQuesta]) {
            $persone = self::ordinate($persone);
            $perChiave['p-' . implode('-', $persone)] = [$persone, $diQuesta];
        }

        return $perChiave;
    }

    /**
     * «Due teste»: la persona per le sue unità da sola; ogni comproprietà a parte, una testa per le stesse persone.
     *
     * @return array<string, array{0: list<int>, 1: list<int>}>
     */
    private static function dueTeste(array $unita): array
    {
        $sole = [];
        $insieme = [];
        foreach ($unita as $id => $u) {
            $persone = self::ordinate($u['persone']);
            if (count($persone) === 1) {
                $sole[$persone[0]][] = (int) $id;
            } else {
                $insieme[implode('-', $persone)] ??= [$persone, []];
                $insieme[implode('-', $persone)][1][] = (int) $id;
            }
        }
        $perChiave = [];
        foreach ($sole as $p => $diQuesta) {
            $perChiave['p-' . $p] = [[(int) $p], $diQuesta];
        }
        foreach ($insieme as [$persone, $diQuesta]) {
            sort($diQuesta);
            $perChiave['u-' . implode('-', $diQuesta)] = [$persone, $diQuesta];
        }

        return $perChiave;
    }

    /** La chiave di una testa nei 60 caratteri della colonna: lunga, diventa un'impronta. */
    private static function chiave(string $chiave): string
    {
        return strlen($chiave) <= 60 ? $chiave : 'h-' . substr(hash('sha256', $chiave), 0, 40);
    }

    /** @param array<int, int|string> $persone @return list<int> */
    private static function ordinate(array $persone): array
    {
        $persone = array_values(array_unique(array_map('intval', $persone)));
        sort($persone);

        return $persone;
    }

    /** «Rossi Mario», «Russo Anna e Russo Marco», «Rossi, Russo e Neri». @param list<string> $voci */
    public static function elenco(array $voci): string
    {
        if (count($voci) <= 1) {
            return (string) ($voci[0] ?? '');
        }
        $ultima = array_pop($voci);

        return implode(', ', $voci) . ' e ' . $ultima;
    }
}
