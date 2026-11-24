<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Sala;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Enums\Assemblee\AmministrazionePunto;
use App\Enums\Assemblee\Maggioranza;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\ImpostazioniCondominio;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Services\Assemblee\Convocazione\ConvocatiDellAssemblea;
use App\Services\Assemblee\TabellaDiProprieta;
use App\Services\Assemblee\UnitaDelPunto;
use App\Services\Riparto\RisolutoreTitolari;
use App\Support\PeriodoCompetenza;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * La fotografia della seduta (R1): all'apertura, le unità del perimetro dell'assemblea ({@see UnitaDelPunto::dellAssemblea()}: per i condòmini di un gruppo
 * le unità con la quota nella tabella del gruppo) con i titolari attivi **al giorno della seduta**
 * ({@see RisolutoreTitolari::attiviAlla()}, come i convocati), i millesimi della tabella dell'assemblea e di ogni tabella dei punti in **centomillesimi
 * interi** (la conversione si fa una volta sola, qui), le teste della costituzione e di ogni punto ({@see TesteDellaSala}), i totali, i nomi. Fermata
 * all'apertura nella seduta (`fotografia`, `impronta_fotografia`): i conti e i voti della seduta la leggono, e la seconda convocazione rilegge chi è titolare
 * alla sua data.
 *
 * Prima dell'apertura la stessa lettura serve **provvisoria** (al giorno della prossima seduta): il foglio della sala, il quinto delle deleghe ricevute; si
 * rifà all'apertura, e una differenza la dice il controllo dell'apertura.
 *
 * La forma (le chiavi in ordine nel JSON canonico; le mappe hanno per chiave l'id):
 * - `assemblea_id`, `seduta_id`, `giorno`, `convocazione`, `provvisoria`, `teste_caso_misto`, `impostazioni` (le scelte dello studio sulla sala:
 *   `delega_oltre_il_quinto`, `voto_discorde_comproprietari`, ferme all'apertura come le teste), `tabella` `{id, nome}`, `avvisi`;
 * - `persone`: id => `{nome}`; `unita`: id => `{nome, millesimi, titolari: [{anagrafica_id, ruolo, quota}], scala_id, palazzina_id}`;
 * - `costituzione`: `{totale_teste, totale_valore, teste: [{chiave, nome, persone, unita, millesimi}]}`;
 * - `punti`: `[{punto_id, numero, maggioranza, amministrazione, conduttore, perimetro, parte, gruppo, tabella, millesimi: id => centomillesimi,
 *   totale_teste, totale_valore, teste, usufrutto: [id delle unità con usufruttuario e nudo proprietario]}]`. Le unità del punto sono quelle di
 *   {@see UnitaDelPunto}: il perimetro del punto e, con la tabella segnata «di un gruppo», le sole unità con la quota (Coda 734). `parte` («la scala B»)
 *   e `gruppo` (`{tabella_id, nome}`) mancano nelle fotografie fermate nella beta.13: chi le legge usa `?? null`.
 */
final class FotografiaDellaSeduta
{
    public function __construct(private readonly RisolutoreTitolari $risolutore) {}

    /** La fotografia di una seduta, al suo giorno. @return array<string, mixed> */
    public function leggi(Assemblea $assemblea, SedutaDellAssemblea $seduta, bool $provvisoria = false): array
    {
        $giorno = $seduta->fissata_il->format('Y-m-d');
        $modo = ImpostazioniCondominio::delCondominio((int) $assemblea->condominio_id)->assemblee_teste ?: TesteDellaSala::DUE_TESTE;
        $immobili = $this->unita($assemblea);
        $tabella = $this->tabellaDelValore($assemblea);
        $punti = $assemblea->punti()->get();
        $tabelle = $punti->pluck('tabella_id')->filter()->push($tabella?->id)->filter()->unique()->values();
        $quote = self::quote($tabelle->all(), $immobili->pluck('id')->all());
        $periodo = PeriodoCompetenza::puntuale($giorno);

        $unita = [];
        $persone = [];
        $avvisi = [];
        foreach ($immobili as $immobile) {
            $titolari = [];
            foreach ($this->risolutore->attiviAlla($immobile->anagrafiche, $periodo) as $a) {
                $titolari[] = ['anagrafica_id' => (int) $a->id, 'ruolo' => (string) $a->pivot->tipologia,
                    'quota' => $a->pivot->quota === null ? null : (float) $a->pivot->quota];
                $persone[(int) $a->id] = ['nome' => (string) $a->nome];
            }
            usort($titolari, fn ($x, $y) => [$x['ruolo'], $x['anagrafica_id']] <=> [$y['ruolo'], $y['anagrafica_id']]);
            $nome = ConvocatiDellAssemblea::nomeDellUnita($immobile);
            $unita[(int) $immobile->id] = ['nome' => $nome, 'millesimi' => (int) ($quote[(int) $tabella?->id][(int) $immobile->id] ?? 0), 'titolari' => $titolari,
                'scala_id' => $immobile->scala_id === null ? null : (int) $immobile->scala_id,
                'palazzina_id' => $immobile->palazzina_id === null ? null : (int) $immobile->palazzina_id];
            if (array_filter($titolari, fn ($t) => $t['ruolo'] !== 'inquilino') === []) {
                $avvisi[] = 'L\'' . $nome . ' non ha un titolare al ' . $seduta->fissata_il->format('d/m/Y') . ': i suoi millesimi contano nel totale, e nessuno li porta in sala.';
            }
        }
        ksort($persone);
        $nomi = array_map(fn ($p) => $p['nome'], $persone);

        $conVoto = $punti->filter(fn (PuntoDellOrdineDelGiorno $p) => $p->maggioranza !== Maggioranza::SENZA_VOTO);
        $amministrazioni = $conVoto->map(fn ($p) => $p->amministrazione?->value ?? AmministrazionePunto::ORDINARIA->value)->unique()->values()->all();
        $diChiCostituisce = [];
        foreach ($unita as $id => $u) {
            $diChiCostituisce[$id] = ['persone' => TesteDellaSala::chiCostituisce($u['titolari'], $amministrazioni), 'millesimi' => $u['millesimi']];
        }
        $totale = array_sum(array_column($unita, 'millesimi'));
        if ($tabella === null) {
            $avvisi[] = 'Manca la tabella del valore: sceglila nel passo «Date e ordine del giorno».';
        } elseif ($unita !== [] && $totale !== 100000000) {
            $avvisi[] = 'La tabella del valore fa ' . ContiDellaSala::millesimi($totale) . ' millesimi, non 1.000: i conti usano la somma delle quote.';
        }
        $costituzione = TesteDellaSala::teste($diChiCostituisce, $modo, $nomi);

        $perPunto = [];
        // Le unità di ogni punto (Coda 734): il perimetro del punto e, con la tabella di un gruppo, le sole unità con la quota ({@see UnitaDelPunto}).
        // Nell'assemblea dei condòmini di un gruppo i punti con la tabella del gruppo sono di tutta l'assemblea: niente gruppo in più.
        $gruppi = UnitaDelPunto::gruppi($punti, UnitaDelPunto::gruppoDellAssemblea($assemblea));
        foreach ($punti as $p) {
            $tabellaDelPunto = $p->tabella_id !== null ? Tabella::query()->find($p->tabella_id) : $tabella;
            $diQuesto = UnitaDelPunto::delPunto($p, $unita, $gruppi);
            $millesimi = [];
            foreach ($diQuesto as $id) {
                $millesimi[$id] = (int) ($quote[(int) $tabellaDelPunto?->id][$id] ?? 0);
            }
            $gruppo = UnitaDelPunto::gruppoDel($p, $gruppi);
            $parte = UnitaDelPunto::parteDi($p);
            $voce = [
                'punto_id' => (int) $p->id,
                'numero' => (int) $p->numero,
                'maggioranza' => $p->maggioranza?->value,
                'amministrazione' => $p->amministrazione?->value ?? AmministrazionePunto::ORDINARIA->value,
                'conduttore' => $p->conduttore?->value,
                'perimetro' => UnitaDelPunto::perimetro($parte, $gruppo['nome'] ?? null),
                'parte' => $parte,
                'gruppo' => $gruppo === null ? null : ['tabella_id' => (int) $p->tabella_id, 'nome' => $gruppo['nome']],
                'tabella' => $tabellaDelPunto === null ? null : ['id' => (int) $tabellaDelPunto->id, 'nome' => (string) $tabellaDelPunto->nome],
                'millesimi' => $millesimi,
                'usufrutto' => array_values(array_filter($diQuesto, fn ($id) => self::conUsufrutto($unita[$id]['titolari']))),
            ];
            $perPunto[] = $voce + self::conteggiDelPunto($voce, $unita, $modo, $nomi, []);
        }

        return [
            'assemblea_id' => (int) $assemblea->id,
            'seduta_id' => (int) $seduta->id,
            'giorno' => $giorno,
            'convocazione' => (string) $seduta->convocazione?->value,
            'provvisoria' => $provvisoria,
            'teste_caso_misto' => $modo,
            'impostazioni' => ImpostazioniDellaSala::dellaFotografia(null),
            'tabella' => $tabella === null ? null : ['id' => (int) $tabella->id, 'nome' => (string) $tabella->nome],
            'persone' => $persone,
            'unita' => $unita,
            'costituzione' => ['totale_teste' => count($costituzione), 'totale_valore' => $totale, 'teste' => $costituzione],
            'punti' => $perPunto,
            'avvisi' => $avvisi,
        ];
    }

    /** Provvisoria, prima dell'apertura: al giorno della prossima seduta non ancora tenuta (di serie la prima convocazione). @return array<string, mixed> */
    public function provvisoria(Assemblea $assemblea): array
    {
        $sedute = $assemblea->sedute()->get();
        $seduta = $sedute->first(fn (SedutaDellAssemblea $s) => $s->aperta_il === null && $s->esito === null) ?? $sedute->first();

        return $this->leggi($assemblea, $seduta, true);
    }

    /**
     * Le teste di un punto, con i totali, dalla fotografia: chi vota per ogni unità, con le scelte del presidente (`$usufruttuari`: unità => il perché per
     * cui sul punto straordinario vota l'usufruttuario).
     *
     * @param array<string, mixed> $fotografia
     * @param array<int, string> $usufruttuari
     * @return array{totale_teste: int, totale_valore: int, teste: list<array<string, mixed>>, ruoli: array<int, string>}
     */
    public static function testeDelPunto(array $fotografia, int $puntoId, array $usufruttuari = []): array
    {
        $voce = collect($fotografia['punti'])->firstWhere('punto_id', $puntoId);
        if ($voce === null) {
            return ['totale_teste' => 0, 'totale_valore' => 0, 'teste' => [], 'ruoli' => []];
        }
        $unita = [];
        foreach ((array) $fotografia['unita'] as $id => $u) {
            $unita[(int) $id] = $u;
        }

        return self::conteggiDelPunto($voce, $unita, (string) $fotografia['teste_caso_misto'],
            array_map(fn ($p) => (string) $p['nome'], (array) $fotografia['persone']), $usufruttuari);
    }

    /**
     * @param array<string, mixed> $voce il punto della fotografia
     * @param array<int, array<string, mixed>> $unita
     * @param array<int, string> $nomi
     * @param array<int, string> $usufruttuari
     * @return array{totale_teste: int, totale_valore: int, teste: list<array<string, mixed>>, ruoli: array<int, string>}
     */
    private static function conteggiDelPunto(array $voce, array $unita, string $modo, array $nomi, array $usufruttuari): array
    {
        $chi = [];
        $ruoli = [];
        foreach ((array) $voce['millesimi'] as $id => $millesimi) {
            $id = (int) $id;
            $vota = TesteDellaSala::chiVota((array) ($unita[$id]['titolari'] ?? []), $voce['amministrazione'], $voce['conduttore'], $usufruttuari[$id] ?? null);
            $chi[$id] = ['persone' => $vota['persone'], 'millesimi' => (int) $millesimi];
            $ruoli[$id] = $vota['ruolo'];
        }
        $teste = TesteDellaSala::teste($chi, $modo, $nomi);
        ksort($ruoli);

        return ['totale_teste' => count($teste), 'totale_valore' => array_sum(array_map('intval', (array) $voce['millesimi'])), 'teste' => $teste,
            'ruoli' => $ruoli];
    }

    /** Il JSON canonico: le chiavi delle mappe in ordine, gli elenchi come sono, senza spazi né barre protette. @param array<string, mixed> $fotografia */
    public static function canonico(array $fotografia): string
    {
        return (string) json_encode(self::ordina($fotografia), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** L'impronta della fotografia: SHA-256 del JSON canonico. @param array<string, mixed> $fotografia */
    public static function impronta(array $fotografia): string
    {
        return hash('sha256', self::canonico($fotografia));
    }

    /** Le mappe in ordine di chiave; un numero a virgola intero come intero (il JSON del database non tiene «100.0»: l'impronta riletta torna uguale). */
    private static function ordina(mixed $valore): mixed
    {
        if (is_float($valore) && is_finite($valore) && floor($valore) === $valore && abs($valore) < 1e15) {
            return (int) $valore;
        }
        if (! is_array($valore)) {
            return $valore;
        }
        $ordinato = array_map(fn ($v) => self::ordina($v), $valore);
        if (array_is_list($ordinato)) {
            return $ordinato;
        }
        ksort($ordinato, SORT_STRING);

        return (object) $ordinato;
    }

    /** @param list<array{ruolo: string}> $titolari */
    private static function conUsufrutto(array $titolari): bool
    {
        $ruoli = array_column($titolari, 'ruolo');

        return in_array('usufruttuario', $ruoli, true) && (in_array('nuda_proprietario', $ruoli, true) || in_array('proprietario', $ruoli, true));
    }

    /** La tabella del valore: quella dell'assemblea, altrimenti la tabella di proprietà del perimetro. */
    private function tabellaDelValore(Assemblea $assemblea): ?Tabella
    {
        if ($assemblea->tabella_id !== null) {
            return Tabella::query()->find($assemblea->tabella_id);
        }
        $perimetro = match ($assemblea->ambito) {
            AmbitoAssemblea::SCALA => $assemblea->scala,
            AmbitoAssemblea::PALAZZINA => $assemblea->palazzina,
            // I condòmini di un gruppo: la tabella è il gruppo, e senza non c'è una tabella di proprietà da proporre.
            AmbitoAssemblea::GRUPPO => null,
            AmbitoAssemblea::CONDOMINIO, null => $assemblea->condominio,
        };

        return $perimetro === null ? null : TabellaDiProprieta::di($perimetro);
    }

    /** Le unità del perimetro dell'assemblea, con le persone e la pivot ({@see UnitaDelPunto::dellAssemblea()}). @return Collection<int, Immobile> */
    private function unita(Assemblea $assemblea): Collection
    {
        return UnitaDelPunto::dellAssemblea($assemblea);
    }

    /**
     * Le quote delle tabelle in centomillesimi interi: tabella => unità => centomillesimi. `quote_tabella.valore` è un decimale a cinque cifre: si legge
     * come testo, senza passare dai numeri a virgola.
     *
     * @param list<int> $tabelle
     * @param list<int> $immobili
     * @return array<int, array<int, int>>
     */
    public static function quote(array $tabelle, array $immobili): array
    {
        $quote = [];
        if ($tabelle === [] || $immobili === []) {
            return $quote;
        }
        foreach (DB::table('quote_tabella')->whereIn('tabella_id', $tabelle)->whereIn('immobile_id', $immobili)->get(['tabella_id', 'immobile_id', 'valore']) as $q) {
            $quote[(int) $q->tabella_id][(int) $q->immobile_id] = self::centomillesimi($q->valore);
        }

        return $quote;
    }

    /** Un valore di `quote_tabella` in centomillesimi: «60.00000» → 6.000.000. */
    public static function centomillesimi(mixed $valore): int
    {
        $testo = trim((string) $valore);
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $testo, $m) === 1) {
            $decimali = substr(str_pad($m[3] ?? '', 6, '0'), 0, 6);
            $intero = (int) $m[2] * 100000 + intdiv((int) $decimali + 5, 10);

            return $m[1] === '-' ? -$intero : $intero;
        }

        return (int) round(((float) $valore) * 100000);
    }
}
