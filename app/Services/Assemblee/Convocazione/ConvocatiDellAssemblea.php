<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Convocazione;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Enums\Assemblee\AmministrazionePunto;
use App\Enums\Assemblee\MateriaPunto;
use App\Enums\RuoloAnagraficaImmobile;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Immobile;
use App\Services\Riparto\RisolutoreTitolari;
use App\Support\PeriodoCompetenza;
use Illuminate\Support\Collection;

/**
 * Chi è convocato a un'assemblea a un giorno (§4.1 del progetto della tappa 2): «si convoca chi risulta dall'anagrafe alla data dell'invio». Prima
 * dell'invio l'elenco si calcola e non si scrive; la riga di `assemblee_convocati` nasce nel gesto dell'invio con i dati copiati.
 *
 * Le unità del perimetro (il condominio, o la palazzina, o la scala), per ognuna i titolari attivi quel giorno ({@see RisolutoreTitolari::attiviAlla()}, con
 * la data d'inizio che vale solo con un predecessore chiuso). **Una persona è un convocato**, anche con più unità. I ruoli:
 *
 * - i proprietari, **ogni comproprietario** con il suo avviso;
 * - con l'usufrutto, **l'avviso va a tutti e due appena l'ordine del giorno ha un punto straordinario** (art. 67 c. 7 disp. att., e per prudenza), e al solo
 *   usufruttuario se tutti i punti sono di amministrazione ordinaria; il nudo proprietario non è mai convocato da solo, salvo un'unità senza usufruttuario;
 * - il conduttore solo per i punti dove vota o interviene (art. 10 L. 392/1978; decisione 566: nel suo avviso ci sono solo quei punti).
 */
final class ConvocatiDellAssemblea
{
    public function __construct(private readonly RisolutoreTitolari $risolutore) {}

    /**
     * I convocati al giorno, per nome.
     *
     * @return list<array{chiave: string, anagrafica_id: int, nome: string, indirizzo: string, pec: ?string, email: ?string, mezzo_convocazione: ?string,
     *     pec_comunicata_il: ?string, unita: list<array{immobile_id: int, nome: string, ruolo: string, quota: ?float}>, punti: ?list<int>, ruolo: ?string,
     *     unita_parole: string, quota: ?string}>
     */
    public function alGiorno(Assemblea $assemblea, string $giorno): array
    {
        $punti = $assemblea->relationLoaded('punti') ? $assemblea->punti : $assemblea->punti()->get();
        $straordinaria = $punti->contains(fn (PuntoDellOrdineDelGiorno $p) => $p->amministrazione === AmministrazionePunto::STRAORDINARIA);
        $puntiDelConduttore = $punti->filter(fn (PuntoDellOrdineDelGiorno $p) => $p->conduttore !== null)->pluck('numero')->map(fn ($n) => (int) $n)->values()->all();
        $periodo = PeriodoCompetenza::puntuale($giorno);

        $persone = [];
        foreach ($this->unita($assemblea) as $immobile) {
            $attivi = $this->risolutore->attiviAlla($immobile->anagrafiche, $periodo);
            $perRuolo = $attivi->groupBy(fn ($a) => (string) $a->pivot->tipologia);
            $haUsufrutto = $perRuolo->has(RuoloAnagraficaImmobile::USUFRUTTUARIO->value);
            foreach ($attivi as $a) {
                $ruolo = (string) $a->pivot->tipologia;
                $puntiDellaPersona = null;
                if ($ruolo === RuoloAnagraficaImmobile::NUDA_PROPRIETA->value && $haUsufrutto && ! $straordinaria) {
                    continue;
                }
                if ($ruolo === RuoloAnagraficaImmobile::INQUILINO->value) {
                    if ($puntiDelConduttore === []) {
                        continue;
                    }
                    $puntiDellaPersona = $puntiDelConduttore;
                }
                $id = (int) $a->id;
                $persone[$id] ??= ['anagrafica' => $a, 'unita' => [], 'punti' => [], 'tutti' => false];
                $persone[$id]['unita'][] = ['immobile_id' => (int) $immobile->id, 'nome' => self::nomeDellUnita($immobile), 'ruolo' => $ruolo,
                    'quota' => $a->pivot->quota === null ? null : (float) $a->pivot->quota];
                if ($puntiDellaPersona === null) {
                    $persone[$id]['tutti'] = true;
                } else {
                    $persone[$id]['punti'] = array_values(array_unique([...$persone[$id]['punti'], ...$puntiDellaPersona]));
                }
            }
        }

        // Il mezzo scelto nella riga per questa assemblea (decisione 647) prende il posto di quello della scheda e della proposta.
        $scelti = MezziScelti::dell($assemblea);
        $convocati = [];
        foreach ($persone as $id => $p) {
            $a = $p['anagrafica'];
            sort($p['punti']);
            $convocati[] = [
                'chiave' => 'persona-' . $id,
                'anagrafica_id' => $id,
                'nome' => (string) $a->nome,
                'indirizzo' => trim((string) $a->indirizzo),
                'pec' => filled($a->pec) ? trim((string) $a->pec) : null,
                'email' => filled($a->email) ? trim((string) $a->email) : null,
                'mezzo_convocazione' => $scelti[$id] ?? $a->mezzo_convocazione ?? null,
                'mezzo_della_scheda' => $a->mezzo_convocazione ?? null,
                'mezzo_per_assemblea' => isset($scelti[$id]),
                'pec_comunicata_il' => self::giornoDi($a->pec_comunicata_il ?? null),
                'unita' => $p['unita'],
                'punti' => $p['tutti'] ? null : $p['punti'],
                'ruolo' => self::ruoloDiChi($p['unita'], $p['tutti'] ? null : $p['punti']),
                'unita_parole' => self::unitaInParole($p['unita']),
                'quota' => self::quotaInParole($p['unita']),
            ];
        }
        usort($convocati, fn ($x, $y) => [mb_strtolower($x['nome']), $x['anagrafica_id']] <=> [mb_strtolower($y['nome']), $y['anagrafica_id']]);

        return $convocati;
    }

    /** Un punto «Modifica della destinazione d'uso»: allora valgono solo raccomandata e PEC, e venti giorni (art. 1117-ter). */
    public static function conDestinazioneDUso(Assemblea $assemblea): bool
    {
        return self::puntiDellaDestinazioneDUso($assemblea) !== [];
    }

    /** I numeri dei punti «Modifica della destinazione d'uso». @return list<int> */
    public static function puntiDellaDestinazioneDUso(Assemblea $assemblea): array
    {
        $punti = $assemblea->relationLoaded('punti') ? $assemblea->punti : $assemblea->punti()->get();

        return $punti->filter(fn (PuntoDellOrdineDelGiorno $p) => $p->materia === MateriaPunto::DESTINAZIONE_USO)->map(fn ($p) => (int) $p->numero)->values()->all();
    }

    /**
     * La persona riceve un punto sulla destinazione d'uso: allora per lei valgono solo raccomandata e PEC. Chi riceve tutti i punti (`$punti` null) sì; il
     * conduttore solo se fra i suoi punti c'è quello.
     *
     * @param ?list<int> $punti
     * @param list<int> $dellaDestinazione
     */
    public static function riceveLaDestinazioneDUso(?array $punti, array $dellaDestinazione): bool
    {
        return $dellaDestinazione !== [] && ($punti === null || $punti === [] || array_intersect(array_map('intval', $punti), $dellaDestinazione) !== []);
    }

    /**
     * «26 condòmini, più l'usufruttuario e il conduttore che il punto 5 chiama. I due comproprietari dell'Int. 5 ricevono un avviso ciascuno.»
     *
     * @param list<array<string, mixed>> $convocati
     */
    public static function descrizione(array $convocati): string
    {
        $condomini = 0;
        $usufruttuari = 0;
        $conduttori = 0;
        $puntiDeiConduttori = [];
        $perUnita = [];
        foreach ($convocati as $c) {
            $ruoli = array_unique(array_map(fn ($u) => $u['ruolo'], (array) ($c['unita'] ?? [])));
            if (array_intersect($ruoli, [RuoloAnagraficaImmobile::PROPRIETARIO->value, RuoloAnagraficaImmobile::NUDA_PROPRIETA->value]) !== []) {
                $condomini++;
            } elseif (in_array(RuoloAnagraficaImmobile::USUFRUTTUARIO->value, $ruoli, true)) {
                $usufruttuari++;
            } else {
                $conduttori++;
                $puntiDeiConduttori = array_values(array_unique([...$puntiDeiConduttori, ...((array) ($c['punti'] ?? []))]));
            }
            foreach ((array) ($c['unita'] ?? []) as $u) {
                if ($u['ruolo'] === RuoloAnagraficaImmobile::PROPRIETARIO->value) {
                    $perUnita[$u['nome']] = ($perUnita[$u['nome']] ?? 0) + 1;
                }
            }
        }
        $frase = $condomini === 1 ? '1 condòmino' : $condomini . ' condòmini';
        $piu = [];
        if ($usufruttuari > 0) {
            $piu[] = $usufruttuari === 1 ? 'l\'usufruttuario' : $usufruttuari . ' usufruttuari';
        }
        if ($conduttori > 0) {
            sort($puntiDeiConduttori);
            $punti = count($puntiDeiConduttori) === 1 ? 'il punto ' . $puntiDeiConduttori[0] : 'i punti ' . self::elenco(array_map('strval', $puntiDeiConduttori));
            $piu[] = ($conduttori === 1 ? 'il conduttore che ' . $punti . ' chiama' : $conduttori . ' conduttori che ' . $punti
                . (count($puntiDeiConduttori) === 1 ? ' chiama' : ' chiamano'));
        }
        if ($piu !== []) {
            $frase .= ', più ' . self::elenco($piu);
        }
        $frase .= '.';
        $comproprieta = array_filter($perUnita, fn ($n) => $n > 1);
        if (count($comproprieta) === 1) {
            $nome = array_key_first($comproprieta);
            $quanti = [2 => 'I due', 3 => 'I tre', 4 => 'I quattro'][$comproprieta[$nome]] ?? 'I ' . $comproprieta[$nome];
            $frase .= ' ' . $quanti . ' comproprietari dell\'' . $nome . ' ricevono un avviso ciascuno.';
        } elseif (count($comproprieta) > 1) {
            $frase .= ' I comproprietari di ' . count($comproprieta) . ' unità ricevono un avviso ciascuno.';
        }

        return $frase;
    }

    /** «Int. 3»; una pertinenza con il suo nome: «box 21». */
    public static function nomeDellUnita(Immobile $immobile): string
    {
        $nome = trim((string) $immobile->nome);
        if ($immobile->pertinenza_di_immobile_id !== null && $nome !== '') {
            return mb_strtolower(mb_substr($nome, 0, 1)) . mb_substr($nome, 1);
        }

        return $immobile->etichetta;
    }

    /** «Int. 3, box 21». @param list<array{nome: string}> $unita */
    public static function unitaInParole(array $unita): string
    {
        return implode(', ', array_values(array_unique(array_map(fn ($u) => (string) $u['nome'], $unita))));
    }

    /** «½» per i comproprietari a metà, «⅓», «60 %»; null per chi ha tutta l'unità. @param list<array{quota: ?float}> $unita */
    public static function quotaInParole(array $unita): ?string
    {
        $quote = array_values(array_unique(array_map(fn ($u) => $u['quota'] === null ? 100.0 : round((float) $u['quota'], 2), $unita)));
        if (count($quote) !== 1 || $quote[0] >= 100.0) {
            return null;
        }
        $q = $quote[0];
        $frazioni = ['50' => '½', '33.33' => '⅓', '66.67' => '⅔', '25' => '¼', '75' => '¾'];
        $chiave = rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.');

        return $frazioni[$chiave] ?? str_replace('.', ',', $chiave) . ' %';
    }

    /**
     * Il ruolo accanto al nome, se non è proprietario: «usufruttuario», «nudo proprietario», «conduttore, per il punto 5».
     *
     * @param list<array{ruolo: string}> $unita
     * @param ?list<int> $punti
     */
    public static function ruoloDiChi(array $unita, ?array $punti): ?string
    {
        $ruoli = array_values(array_unique(array_map(fn ($u) => $u['ruolo'], $unita)));
        if (in_array(RuoloAnagraficaImmobile::PROPRIETARIO->value, $ruoli, true) || count($ruoli) !== 1) {
            return null;
        }

        return match ($ruoli[0]) {
            RuoloAnagraficaImmobile::USUFRUTTUARIO->value => 'usufruttuario',
            RuoloAnagraficaImmobile::NUDA_PROPRIETA->value => 'nudo proprietario',
            RuoloAnagraficaImmobile::INQUILINO->value => 'conduttore' . ($punti === null || $punti === [] ? ''
                : ', per ' . (count($punti) === 1 ? 'il punto ' . $punti[0] : 'i punti ' . self::elenco(array_map('strval', $punti)))),
            default => null,
        };
    }

    /** «5», «5 e 6», «4, 5 e 6». @param list<string> $voci */
    public static function elenco(array $voci): string
    {
        if (count($voci) <= 1) {
            return (string) ($voci[0] ?? '');
        }
        $ultima = array_pop($voci);

        return implode(', ', $voci) . ' e ' . $ultima;
    }

    /** Le unità del perimetro, con le persone e la pivot. @return Collection<int, Immobile> */
    private function unita(Assemblea $assemblea): Collection
    {
        return Immobile::query()->where('condominio_id', $assemblea->condominio_id)
            ->when($assemblea->ambito === AmbitoAssemblea::PALAZZINA && $assemblea->palazzina_id !== null, fn ($q) => $q->where('palazzina_id', $assemblea->palazzina_id))
            ->when($assemblea->ambito === AmbitoAssemblea::SCALA && $assemblea->scala_id !== null, fn ($q) => $q->where('scala_id', $assemblea->scala_id))
            ->with('anagrafiche')->orderBy('id')->get();
    }

    private static function giornoDi(mixed $valore): ?string
    {
        if ($valore === null || $valore === '') {
            return null;
        }

        return $valore instanceof \DateTimeInterface ? $valore->format('Y-m-d') : substr((string) $valore, 0, 10);
    }
}
