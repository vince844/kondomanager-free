<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Immobile;
use App\Models\Tabella;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Le unità di un punto dell'ordine del giorno (Coda 734, il condominio parziale; art. 1123 c. 3 c.c.), lette in un posto solo: la fotografia della seduta,
 * i convocati e le pagine passano da qui, perché il quorum letto in un posto e non in un altro sarebbe un difetto.
 *
 * - **Le unità del punto** sono quelle del suo perimetro fra le unità dell'assemblea: tutte, o quelle della palazzina o della scala del punto.
 * - **Il gruppo**: se la tabella del punto (`assemblee_punti.tabella_id`, non quella dell'assemblea) è segnata «di un gruppo» (`tabelle.di_un_gruppo`),
 *   restano solo le unità con una quota **maggiore di zero** in quella tabella, lo stesso criterio delle scelte delle pagine. Il segno lo mette
 *   l'amministratore: senza il segno una tabella con degli zeri non cambia niente (le unità fuori contano come teste a zero millesimi).
 * - **Le parole della parte** (la sola scala o palazzina, il gruppo, o tutte e due), le stesse per la fotografia, la sala, l'avviso, la bacheca e il
 *   verbale: {@see perimetro()}, {@see chiVota()}, {@see condomini()}, {@see soliCondomini()}, {@see nomeBreve()}.
 * - **Le unità dell'assemblea** ({@see dellAssemblea()}, §1.1-bis del progetto della tappa 3): il condominio, la palazzina o la scala, e per l'assemblea dei
 *   condòmini di un gruppo le unità con una quota maggiore di zero nella tabella dell'assemblea, che è il gruppo. Le leggono la fotografia e i convocati.
 *   In quell'assemblea un punto con la tabella del gruppo non ha un gruppo suo: sono le stesse unità ({@see gruppoDellAssemblea()}).
 */
final class UnitaDelPunto
{
    /**
     * Le quote maggiori di zero delle tabelle date: tabella => immobile => valore (il testo del database, a cinque decimali). Le stesse che contano le
     * scelte delle pagine ({@see \App\Http\Controllers\Assemblee\PerLePagine::scelte()}).
     *
     * @param list<int> $tabelle
     * @return array<int, array<int, string>>
     */
    public static function quoteMaggioriDiZero(array $tabelle): array
    {
        $quote = [];
        if ($tabelle === []) {
            return $quote;
        }
        foreach (DB::table('quote_tabella')->whereIn('tabella_id', $tabelle)->where('valore', '>', 0)->get(['tabella_id', 'immobile_id', 'valore']) as $q) {
            $quote[(int) $q->tabella_id][(int) $q->immobile_id] = (string) $q->valore;
        }

        return $quote;
    }

    /**
     * Le unità dell'assemblea, con le persone e la pivot: tutte quelle del condominio, o della palazzina, o della scala; per i condòmini di un gruppo quelle
     * con una quota maggiore di zero nella tabella dell'assemblea (senza la tabella, nessuna).
     *
     * @return Collection<int, Immobile>
     */
    public static function dellAssemblea(Assemblea $assemblea): Collection
    {
        $gruppo = self::gruppoDellAssemblea($assemblea);

        return Immobile::query()->where('condominio_id', $assemblea->condominio_id)
            ->when($assemblea->ambito === AmbitoAssemblea::PALAZZINA && $assemblea->palazzina_id !== null, fn ($q) => $q->where('palazzina_id', $assemblea->palazzina_id))
            ->when($assemblea->ambito === AmbitoAssemblea::SCALA && $assemblea->scala_id !== null, fn ($q) => $q->where('scala_id', $assemblea->scala_id))
            ->when($assemblea->ambito === AmbitoAssemblea::GRUPPO, fn ($q) => $q->whereIn('id', DB::table('quote_tabella')->where('tabella_id', (int) $gruppo)
                ->where('valore', '>', 0)->select('immobile_id')))
            ->with('anagrafiche')->orderBy('id')->get();
    }

    /** La tabella del gruppo dell'assemblea dei condòmini di un gruppo (la sua tabella del valore); null per le altre. */
    public static function gruppoDellAssemblea(?Assemblea $assemblea): ?int
    {
        return $assemblea !== null && $assemblea->ambito === AmbitoAssemblea::GRUPPO && $assemblea->tabella_id !== null ? (int) $assemblea->tabella_id : null;
    }

    /**
     * Il nome del gruppo del punto nell'avviso e in bacheca: la tabella del punto segnata «di un gruppo», ma non quella dell'assemblea di quel gruppo (i suoi
     * punti sono di tutta l'assemblea). Null per il punto senza gruppo.
     */
    public static function gruppoProprio(PuntoDellOrdineDelGiorno $p, ?Assemblea $assemblea): ?string
    {
        if ($p->tabella === null || ! $p->tabella->di_un_gruppo || (int) $p->tabella_id === self::gruppoDellAssemblea($assemblea)) {
            return null;
        }

        return (string) $p->tabella->nome;
    }

    /**
     * Il controllo della tabella del gruppo, nella forma di {@see TabellaDiProprieta::controllo()}: le sue unità sono quelle con la quota, quindi le copre
     * sempre; la somma dice se fa 1.000.
     *
     * @return array{frase: string, avvisi: list<string>, unita: int, con_quota: int, somma: string, somma_millesimi: float}
     */
    public static function controlloDelGruppo(Tabella $tabella): array
    {
        $quote = self::quoteMaggioriDiZero([(int) $tabella->id])[(int) $tabella->id] ?? [];
        $somma = 0;
        foreach ($quote as $valore) {
            $somma += \App\Services\Assemblee\Sala\FotografiaDellaSeduta::centomillesimi($valore);
        }
        $testo = number_format($somma / 100000, 2, ',', '.');

        return ['frase' => count($quote) . ' unità su ' . count($quote) . ' · ' . $testo . ' millesimi',
            'avvisi' => $quote !== [] && abs($somma - 100000000) > 1 ? ['la somma è ' . $testo] : [], 'unita' => count($quote), 'con_quota' => count($quote),
            'somma' => $testo, 'somma_millesimi' => $somma / 100000];
    }

    /**
     * I gruppi dei punti: per ogni tabella segnata «di un gruppo» fra le tabelle dei punti, il nome e le unità con la quota. `$tranne`: la tabella del gruppo
     * dell'assemblea ({@see gruppoDellAssemblea()}), che sui suoi punti non fa un gruppo in più.
     *
     * @param iterable<PuntoDellOrdineDelGiorno> $punti
     * @return array<int, array{nome: string, unita: list<int>}>
     */
    public static function gruppi(iterable $punti, ?int $tranne = null): array
    {
        $ids = [];
        foreach ($punti as $p) {
            if ($p->tabella_id !== null && (int) $p->tabella_id !== $tranne) {
                $ids[(int) $p->tabella_id] = true;
            }
        }
        $segnate = $ids === [] ? collect() : Tabella::query()->whereIn('id', array_keys($ids))->where('di_un_gruppo', true)->get(['id', 'nome']);
        $quote = self::quoteMaggioriDiZero($segnate->pluck('id')->map(fn ($id) => (int) $id)->all());
        $gruppi = [];
        foreach ($segnate as $t) {
            $gruppi[(int) $t->id] = ['nome' => (string) $t->nome, 'unita' => array_map('intval', array_keys($quote[(int) $t->id] ?? []))];
        }

        return $gruppi;
    }

    /**
     * Le unità di un punto fra le unità dell'assemblea.
     *
     * @param array<int, array{scala_id: ?int, palazzina_id: ?int}> $unita immobile => dove sta
     * @param array<int, array{nome: string, unita: list<int>}> $gruppi da {@see gruppi()}
     * @return list<int>
     */
    public static function delPunto(PuntoDellOrdineDelGiorno $p, array $unita, array $gruppi): array
    {
        $gruppo = self::gruppoDel($p, $gruppi);
        $dentro = $gruppo === null ? null : array_flip($gruppo['unita']);
        $ids = [];
        foreach ($unita as $id => $u) {
            $nelPerimetro = match ($p->ambito) {
                AmbitoAssemblea::SCALA => ($u['scala_id'] ?? null) === $p->scala_id,
                AmbitoAssemblea::PALAZZINA => ($u['palazzina_id'] ?? null) === $p->palazzina_id,
                default => true,
            };
            if ($nelPerimetro && ($dentro === null || isset($dentro[(int) $id]))) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Le unità di ogni punto: punto => unità.
     *
     * @param iterable<PuntoDellOrdineDelGiorno> $punti
     * @param array<int, array{scala_id: ?int, palazzina_id: ?int}> $unita immobile => dove sta
     * @param ?int $tranne la tabella del gruppo dell'assemblea ({@see gruppi()})
     * @return array<int, list<int>>
     */
    public static function diOgniPunto(iterable $punti, array $unita, ?int $tranne = null): array
    {
        $gruppi = self::gruppi($punti, $tranne);
        $diOgni = [];
        foreach ($punti as $p) {
            $diOgni[(int) $p->id] = self::delPunto($p, $unita, $gruppi);
        }

        return $diOgni;
    }

    /** Il gruppo del punto, se la sua tabella ha il segno: `{nome, unita}`, altrimenti null. @param array<int, array{nome: string, unita: list<int>}> $gruppi */
    public static function gruppoDel(PuntoDellOrdineDelGiorno $p, array $gruppi): ?array
    {
        return $p->tabella_id === null ? null : ($gruppi[(int) $p->tabella_id] ?? null);
    }

    /** La parte del punto: «la scala B», «la palazzina A», o null per il punto di tutta l'assemblea. */
    public static function parteDi(PuntoDellOrdineDelGiorno $p): ?string
    {
        return match ($p->ambito) {
            AmbitoAssemblea::SCALA => 'la scala ' . ($p->scala?->name ?? ''),
            AmbitoAssemblea::PALAZZINA => 'la palazzina ' . ($p->palazzina?->name ?? ''),
            default => null,
        };
    }

    /**
     * Il perimetro del punto, nella fotografia e nella barra: «Solo la scala B», «Solo i condòmini della tabella «Box»», «Solo la scala B, i condòmini
     * della tabella «Ascensore B»»; null per il punto di tutta l'assemblea.
     */
    public static function perimetro(?string $parte, ?string $gruppo): ?string
    {
        return match (true) {
            $parte !== null && $gruppo !== null => 'Solo ' . $parte . ', i condòmini della tabella «' . $gruppo . '»',
            $parte !== null => 'Solo ' . $parte,
            $gruppo !== null => 'Solo i condòmini della tabella «' . $gruppo . '»',
            default => null,
        };
    }

    /** La riga della sala sotto la regola: «Votano solo i condòmini della tabella «Box»», «Votano solo i condòmini della scala B». */
    public static function chiVota(?string $parte, ?string $gruppo): ?string
    {
        $condomini = self::condomini($parte, $gruppo);

        return $condomini === null ? null : 'Votano solo ' . $condomini;
    }

    /**
     * Chi decide il punto, nella riga della sala e in bacheca: «i condòmini della tabella «Box»», «i condòmini della scala B», «i condòmini della scala B che
     * hanno una quota nella tabella «Ascensore B»»; null per il punto di tutta l'assemblea.
     */
    public static function condomini(?string $parte, ?string $gruppo): ?string
    {
        if ($parte === null && $gruppo === null) {
            return null;
        }

        return 'i condòmini ' . ($parte !== null ? self::della($parte) . ($gruppo !== null ? ' che hanno una quota nella tabella «' . $gruppo . '»' : '')
            : 'della tabella «' . $gruppo . '»');
    }

    /**
     * «i soli condòmini delle unità della tabella «Box»», «i soli condòmini della scala B», «i soli condòmini della scala B che hanno una quota nella tabella
     * «Ascensore B»»; con l'articolo (il verbale) quando c'è il gruppo: «… (art. 1123 c. 3 c.c.)».
     */
    public static function soliCondomini(?string $parte, ?string $gruppo, bool $conLArticolo = false): ?string
    {
        if ($parte === null && $gruppo === null) {
            return null;
        }
        $articolo = $gruppo !== null && $conLArticolo ? ' (art. 1123 c. 3 c.c.)' : '';
        if ($parte === null) {
            return 'i soli condòmini delle unità della tabella «' . $gruppo . '»' . $articolo;
        }

        return 'i soli condòmini ' . self::della($parte) . ($gruppo !== null ? ' che hanno una quota nella tabella «' . $gruppo . '»' : '') . $articolo;
    }

    /** Il nome breve della parte nel messaggio di «Si vota»: «il gruppo della tabella «Box»», «la scala B», «il gruppo della tabella «Ascensore B» nella scala B». */
    public static function nomeBreve(?string $parte, ?string $gruppo): ?string
    {
        return match (true) {
            $parte !== null && $gruppo !== null => 'il gruppo della tabella «' . $gruppo . '» nel' . $parte,
            $parte !== null => $parte,
            $gruppo !== null => 'il gruppo della tabella «' . $gruppo . '»',
            default => null,
        };
    }

    /** «la scala B» → «della scala B». */
    private static function della(string $parte): string
    {
        return str_starts_with($parte, 'la ') ? 'del' . $parte : 'di ' . $parte;
    }
}
