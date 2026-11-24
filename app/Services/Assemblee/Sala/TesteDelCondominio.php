<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Sala;

use App\Enums\Assemblee\AmministrazionePunto;
use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Assemblee\Assemblea;
use App\Models\Condominio;
use App\Models\ImpostazioniCondominio;
use App\Models\Immobile;
use App\Services\Assemblee\Convocazione\ConvocatiDellAssemblea;
use App\Services\Assemblee\TabellaDiProprieta;
use App\Services\Riparto\RisolutoreTitolari;
use App\Support\PeriodoCompetenza;

/**
 * Le teste del condominio nel caso misto, per la sezione «Le assemblee» delle impostazioni del condominio: la scelta di oggi, se l'interruttore è fermo
 * (un'assemblea in corso conta con la regola fermata all'apertura) e la riga «Adesso: …» per le due posizioni, con un esempio vero del condominio.
 *
 * Il conto è quello della sala ({@see TesteDellaSala::teste()}): tutte le unità del condominio con i titolari di oggi, chi le porta nella costituzione come
 * su un punto di ordinaria amministrazione, i millesimi della tabella di proprietà generale (se c'è). L'esempio è la prima testa che con «una testa» unisce
 * una persona con un'unità sua e un'unità in comproprietà, e con «due teste» si divide.
 */
final class TesteDelCondominio
{
    public function __construct(private readonly RisolutoreTitolari $risolutore) {}

    /** @return array{teste: string, ferma: ?string, adesso: array{una_testa: string, due_teste: string}} */
    public function perLaPagina(Condominio $condominio): array
    {
        $scelta = (string) (ImpostazioniCondominio::delCondominio((int) $condominio->id)->assemblee_teste ?: TesteDellaSala::DUE_TESTE);
        $inCorso = Assemblea::query()->where('condominio_id', $condominio->id)->where('stato', StatoAssemblea::IN_CORSO->value)->exists();
        [$unita, $nomi, $nomiDelleUnita] = $this->unita($condominio);
        $una = TesteDellaSala::teste($unita, TesteDellaSala::UNA_TESTA, $nomi);
        $due = TesteDellaSala::teste($unita, TesteDellaSala::DUE_TESTE, $nomi);

        return ['teste' => $scelta, 'ferma' => $inCorso ? ImpostazioniDellaSala::FRASE_FERMA : null,
            'adesso' => self::adesso($una, $due, $nomiDelleUnita, TabellaDiProprieta::di($condominio) !== null)];
    }

    /**
     * Le teste del condominio prima e dopo un passaggio di titolarità, come le conta la sala (Coda 758 (1): il pannello «Cosa cambierà» della vendita dice
     * lo stesso numero che poi trova l'assemblea). Il conto è quello di {@see perLaPagina()}: le unità di oggi con la scelta del condominio; «dopo» sostituisce
     * chi porta ogni unità del passaggio (l'unità e le pertinenze che passano con lei) con chi la porterebbe sui titolari dati, come su un punto di
     * ordinaria amministrazione.
     *
     * @param array<int, list<array{anagrafica_id: int, ruolo: string}>> $titolariDopo i titolari dopo il passaggio, per ogni unità che passa (id dell'unità)
     * @return array{scelta: string, prima: int, dopo: int}
     */
    public function primaEDopo(Condominio $condominio, array $titolariDopo): array
    {
        $scelta = (string) (ImpostazioniCondominio::delCondominio((int) $condominio->id)->assemblee_teste ?: TesteDellaSala::DUE_TESTE);
        [$unita, $nomi] = $this->unita($condominio);
        $prima = count(TesteDellaSala::teste($unita, $scelta, $nomi));
        foreach ($titolariDopo as $unitaId => $titolari) {
            $unita[(int) $unitaId] = ['persone' => TesteDellaSala::chiCostituisce($titolari, [AmministrazionePunto::ORDINARIA->value]),
                'millesimi' => (int) ($unita[(int) $unitaId]['millesimi'] ?? 0)];
        }

        return ['scelta' => $scelta, 'prima' => $prima, 'dopo' => count(TesteDellaSala::teste($unita, $scelta, $nomi))];
    }

    /**
     * I titolari di oggi di un'unità, con la regola della sala (i titolari attivi al giorno di oggi).
     *
     * @return list<array{anagrafica_id: int, ruolo: string, quota: float}>
     */
    public function titolariDiOggi(Immobile $immobile): array
    {
        $periodo = PeriodoCompetenza::puntuale(\App\Helpers\DateHelper::oggiUtente());
        $titolari = [];
        foreach ($this->risolutore->attiviAlla($immobile->anagrafiche()->get(), $periodo) as $a) {
            $titolari[] = ['anagrafica_id' => (int) $a->id, 'ruolo' => (string) $a->pivot->tipologia, 'quota' => (float) $a->pivot->quota];
        }

        return $titolari;
    }

    /**
     * Le due righe «Adesso: …».
     *
     * @param list<array<string, mixed>> $una
     * @param list<array<string, mixed>> $due
     * @param array<int, string> $nomiDelleUnita
     * @return array{una_testa: string, due_teste: string}
     */
    public static function adesso(array $una, array $due, array $nomiDelleUnita, bool $conIMillesimi): array
    {
        $quanti = fn (array $teste) => count($teste) === 1 ? '1 condòmino' : count($teste) . ' condòmini';
        $unitaDi = fn (array $ids) => TesteDellaSala::elenco(array_map(fn ($u) => $nomiDelleUnita[(int) $u] ?? ('unità ' . $u), $ids));
        foreach ($una as $testa) {
            $dentro = array_values(array_filter($due, fn ($d) => array_diff($d['unita'], $testa['unita']) === []));
            $sola = collect($dentro)->first(fn ($d) => count($d['persone']) === 1);
            $insieme = collect($dentro)->first(fn ($d) => count($d['persone']) > 1);
            if (count($dentro) < 2 || $sola === null || $insieme === null) {
                continue;
            }
            $millesimi = $conIMillesimi ? ', con ' . ContiDellaSala::millesimi((int) $testa['millesimi']) . ' millesimi' : '';

            return [
                'una_testa' => 'Adesso: una testa. ' . $testa['nome'] . ' contano una volta' . $millesimi . ' (' . $unitaDi($testa['unita']) . '); il condominio ha '
                    . $quanti($una) . '.',
                'due_teste' => 'Adesso: due teste. ' . $sola['nome'] . ' per l\'' . $unitaDi($sola['unita']) . ', e l\'' . $unitaDi($insieme['unita'])
                    . ' a parte con il suo rappresentante; il condominio ha ' . $quanti($due) . '. Se a rappresentare l\'' . $unitaDi($insieme['unita']) . ' è '
                    . $sola['nome'] . ', conta per due.',
            ];
        }
        $uguale = 'In questo condominio nessuno ha un\'unità da solo e un\'altra in comproprietà: le due scelte contano allo stesso modo, ' . $quanti($una) . '.';

        return ['una_testa' => 'Adesso: una testa. ' . $uguale, 'due_teste' => 'Adesso: due teste. ' . $uguale];
    }

    /**
     * Le unità del condominio con chi le porta oggi (come su un punto ordinario) e i millesimi della tabella di proprietà generale; i nomi delle persone e
     * delle unità.
     *
     * @return array{0: array<int, array{persone: list<int>, millesimi: int}>, 1: array<int, string>, 2: array<int, string>}
     */
    private function unita(Condominio $condominio): array
    {
        $immobili = Immobile::query()->where('condominio_id', $condominio->id)->with('anagrafiche')->orderBy('id')->get();
        $tabella = TabellaDiProprieta::di($condominio);
        $ids = $immobili->pluck('id')->map(fn ($id) => (int) $id)->all();
        $quote = $tabella === null ? [] : (FotografiaDellaSeduta::quote([(int) $tabella->id], $ids)[(int) $tabella->id] ?? []);
        $periodo = PeriodoCompetenza::puntuale(\App\Helpers\DateHelper::oggiUtente());
        $unita = [];
        $nomi = [];
        $nomiDelleUnita = [];
        foreach ($immobili as $immobile) {
            $titolari = [];
            foreach ($this->risolutore->attiviAlla($immobile->anagrafiche, $periodo) as $a) {
                $titolari[] = ['anagrafica_id' => (int) $a->id, 'ruolo' => (string) $a->pivot->tipologia];
                $nomi[(int) $a->id] = (string) $a->nome;
            }
            $unita[(int) $immobile->id] = ['persone' => TesteDellaSala::chiCostituisce($titolari, [AmministrazionePunto::ORDINARIA->value]),
                'millesimi' => (int) ($quote[(int) $immobile->id] ?? 0)];
            $nomiDelleUnita[(int) $immobile->id] = ConvocatiDellAssemblea::nomeDellUnita($immobile);
        }

        return [$unita, $nomi, $nomiDelleUnita];
    }
}
