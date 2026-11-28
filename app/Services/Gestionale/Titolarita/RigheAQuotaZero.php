<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Titolarita;

use App\Services\Riparto\RisolutoreTitolari;
use App\Support\PeriodoCompetenza;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Le righe di proprietà chiuse a quota 0 che un altro proprietario segue: dove, nei loro giorni, nessuno paga per l'unità.
 *
 * Fino alla 1.11.0-beta.30 il programma non accettava due proprietari al 100 % sulla stessa unità, anche in periodi diversi, e per registrare chi era
 * subentrato si abbassava a 0 % chi aveva venduto. Quella riga resta a quota 0: il riparto la salta (`RisolutoreTitolari::giorniScoperti`), e le spese del suo
 * periodo non toccano nessuno. Nessuna interfaccia le elencava; qui si trovano, in sola lettura, e la correzione resta della persona che le legge: ogni riga
 * porta allo storico dell'unità, dove «Correggi questa riga» apre la pagina di modifica. Mai una correzione automatica: la quota giusta la sa l'amministratore
 * (un regolamento può esonerare il costruttore, e lo 0 allora è voluto).
 *
 * La regola, per ogni unità e per ognuna delle due tipologie di proprietà (la coppia unità-tipologia è quella che il riparto legge): una riga attiva, con la
 * data di fine e la quota a zero, e un'altra riga della stessa coppia, di un'altra persona, con quota maggiore di zero, che comincia entro il giorno dopo la sua
 * fine. Una riga a quota 0 senza nessuno dopo, o ancora in corso, non è questo caso. I giorni sono quelli del periodo della riga in cui nessuna riga della coppia
 * con quota maggiore di zero è in vigore, come li conta il riparto: se qualcun altro copre già quei giorni la riga non si elenca.
 */
class RigheAQuotaZero
{
    /** Le tipologie di proprietà: la coppia con cui il riparto cerca chi c'era prima e chi viene dopo. */
    private const TIPOLOGIE_DI_PROPRIETA = ['proprietario', 'nuda_proprietario'];

    public function __construct(private readonly RisolutoreTitolari $titolari = new RisolutoreTitolari())
    {
    }

    /**
     * Le righe a quota 0 di un condominio, una per riga, per unità e per data d'inizio.
     *
     * @return list<array{riga_id: int, immobile_id: int, unita: string, anagrafica_id: int, persona: string, tipologia: string, dal: string|null, al: string, periodo: string, giorni: int, url_storico: string}>
     */
    public function perCondominio(int $condominioId): array
    {
        $righe = DB::table('anagrafica_immobile as ai')
            ->join('immobili as i', 'i.id', '=', 'ai.immobile_id')
            ->where('i.condominio_id', $condominioId)
            ->whereIn('ai.tipologia', self::TIPOLOGIE_DI_PROPRIETA)
            ->where('ai.attivo', true)
            ->get(['ai.id', 'ai.immobile_id', 'ai.anagrafica_id', 'ai.tipologia', 'ai.quota', 'ai.attivo', 'ai.data_inizio', 'ai.data_fine', 'i.nome as unita']);

        $trovate = [];
        foreach ($righe->groupBy(fn ($r) => $r->immobile_id . '|' . $r->tipologia) as $coppia) {
            foreach ($coppia as $riga) {
                if (! $this->aQuotaZeroChiusa($riga) || ! $this->haChiLaSegue($riga, $coppia)) {
                    continue;
                }
                $giorni = $this->giorniSenzaChiPaga($riga, $coppia, CarbonImmutable::parse(substr((string) $riga->data_fine, 0, 10)));
                if ($giorni > 0) {
                    $trovate[] = ['riga' => $riga, 'giorni' => $giorni];
                }
            }
        }
        if ($trovate === []) {
            return [];
        }

        $nomi = DB::table('anagrafiche')->whereIn('id', array_map(fn ($t) => (int) $t['riga']->anagrafica_id, $trovate))->pluck('nome', 'id');
        usort($trovate, fn ($a, $b) => strnatcasecmp((string) $a['riga']->unita, (string) $b['riga']->unita) ?: strcmp((string) $a['riga']->data_inizio, (string) $b['riga']->data_inizio));

        return array_map(function (array $t) use ($condominioId, $nomi): array {
            $r = $t['riga'];
            $dal = $r->data_inizio === null ? null : substr((string) $r->data_inizio, 0, 10);
            $al = substr((string) $r->data_fine, 0, 10);

            return [
                'riga_id' => (int) $r->id,
                'immobile_id' => (int) $r->immobile_id,
                'unita' => (string) $r->unita,
                'anagrafica_id' => (int) $r->anagrafica_id,
                'persona' => (string) ($nomi[$r->anagrafica_id] ?? '—'),
                'tipologia' => (string) $r->tipologia,
                'dal' => $dal,
                'al' => $al,
                'periodo' => $this->periodo($dal, $al),
                'giorni' => $t['giorni'],
                // Lo storico dell'unità sta nella pagina dei suoi titolari («Chi ha avuto questa unità»).
                'url_storico' => route('admin.gestionale.immobili.anagrafiche.index', ['condominio' => $condominioId, 'immobile' => (int) $r->immobile_id]) . '?storico=1',
            ];
        }, $trovate);
    }

    /**
     * I giorni del periodo di una riga in cui nessuna riga della sua coppia, con quota maggiore di zero, è in vigore: per quei giorni nessuno paga per
     * l'unità. Il periodo va dalla data d'inizio della riga alla sua data di fine, o a `$fino` se è ancora in corso; senza la data d'inizio non si conta
     * (zero). Le righe sono quelle attive della stessa unità e tipologia, come le legge il riparto.
     *
     * @param object $riga una riga di `anagrafica_immobile` (una riga del database o il modello)
     * @param Collection<int, object> $righeDellaCoppia
     */
    public function giorniSenzaChiPaga(object $riga, Collection $righeDellaCoppia, CarbonImmutable $fino): int
    {
        $dal = $this->giorno($riga->data_inizio ?? null);
        $al = $this->giorno($riga->data_fine ?? null) ?? $fino;
        if ($dal === null || $al->lt($dal)) {
            return 0;
        }

        return $this->titolari->giorniScoperti($righeDellaCoppia->filter(fn ($r) => (bool) ($r->attivo ?? false))->values(), new PeriodoCompetenza($dal, $al))?->giorni() ?? 0;
    }

    /** Una riga di proprietà attiva, con la data di fine e la quota a zero. */
    private function aQuotaZeroChiusa(object $riga): bool
    {
        return (bool) $riga->attivo && $riga->data_fine !== null && (float) ($riga->quota ?? 0) <= 0.0;
    }

    /** Dopo di lei, entro il giorno successivo alla sua fine, un'altra persona della stessa coppia con quota maggiore di zero. */
    private function haChiLaSegue(object $riga, Collection $coppia): bool
    {
        $limite = $this->giorno($riga->data_fine)->addDay();

        return $coppia->contains(function ($altra) use ($riga, $limite) {
            $inizio = $this->giorno($altra->data_inizio ?? null);

            return (int) $altra->id !== (int) $riga->id
                && (int) $altra->anagrafica_id !== (int) $riga->anagrafica_id
                && (float) ($altra->quota ?? 0) > 0.0
                && $inizio !== null && $inizio->lte($limite);
        });
    }

    private function giorno(mixed $valore): ?CarbonImmutable
    {
        return $valore === null || $valore === '' ? null : CarbonImmutable::parse($valore instanceof \DateTimeInterface ? $valore->format('Y-m-d') : substr((string) $valore, 0, 10));
    }

    /** «1 gen 2026 – 15 set 2026»; senza la data d'inizio, «fino al 15 set 2026». */
    private function periodo(?string $dal, string $al): string
    {
        $in = fn (string $giorno): string => CarbonImmutable::parse($giorno)->locale('it')->translatedFormat('j M Y');

        return $dal === null ? 'fino al ' . $in($al) : $in($dal) . ' – ' . $in($al);
    }
}
