<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Services\Riparto\CompetenzaDelPiano;

/**
 * La delibera che ha approvato l'opera (decisione 322), in un posto solo: il rendiconto per persona (`ConsuntivoPerPersona`) e gli addebiti la chiamano.
 *
 * Il primo piano dell'opera è quello con la delibera **più vecchia**, a parità il numero più basso (non quello scritto per primo), fra i piani **attivi**
 * con la data. Tre esiti per un esercizio (P17 del progetto del registro delle modifiche): una sola delibera nell'esercizio del primo piano, quella; nessuna,
 * la prima dell'opera; **più delibere diverse, nessuna scelta** (`null`, con il motivo): il programma non sceglie per l'amministratore.
 */
final class DeliberaDellOpera
{
    public const FRASE_DELIBERE_DIVERSE = 'Nell\'esercizio del primo piano dell\'opera ci sono delibere con date diverse: il programma non sceglie a chi addebitare. '
        . 'Scegli la persona, o scrivi la competenza sulla fattura.';

    public function __construct(private readonly CompetenzaDelPiano $competenza) {}

    /**
     * Le delibere dei piani attivi, per esercizio del piano; la prima (del primo piano); l'esercizio del primo piano.
     *
     * @param iterable<PianoRate> $piani
     * @return array{0: array<int, list<string>>, 1: ?string, 2: ?int}
     */
    public function delibere(iterable $piani): array
    {
        $perEsercizio = [];
        $ordine = [];
        $piani = collect($piani);
        // Coda 688 (decisione 623 (4)): il piano dei lavori urgenti sulle voci non ha una delibera, ha il giorno dei lavori: vale come la delibera dell'opera,
        // così la fattura pagata senza periodo si divide come le rate.
        $urgenti = \App\Services\Riparto\GiornoDellUrgenza::perPiani($piani->filter(fn ($p) => $p->data_delibera_assemblea === null
            && $p->tipo_autorizzazione === 'urgenza')->pluck('id')->map(fn ($id) => (int) $id)->values()->all());
        foreach ($piani as $p) {
            $giorno = $p->data_delibera_assemblea ?? ($urgenti[(int) $p->id] ?? null);
            if (! (bool) $p->attivo || $giorno === null) {
                continue;
            }
            $e = $this->competenza->esercizioDelPiano($p);
            $d = substr((string) $giorno, 0, 10);
            $perEsercizio[(int) ($e?->id ?? 0)][$d] = $d;
            $ordine[] = [$d, (int) $p->id, (int) ($e?->id ?? 0)];
        }
        sort($ordine);

        return [array_map('array_values', $perEsercizio), $ordine === [] ? null : $ordine[0][0], $ordine === [] ? null : $ordine[0][2]];
    }

    /**
     * Coda 688: la frase del conguaglio quando il giorno dell'opera è quello dei lavori urgenti di un piano, non una delibera: «Le spese senza periodo sulla
     * fattura vanno a chi era titolare il 02/03/2027, il giorno dei lavori urgenti scritto nel piano «…», come le rate.». Null altrimenti.
     *
     * @param iterable<PianoRate> $piani
     */
    public function fraseDellUrgenza(iterable $piani, ?string $giorno): ?string
    {
        if ($giorno === null) {
            return null;
        }
        $piani = collect($piani)->filter(fn ($p) => (bool) $p->attivo && $p->data_delibera_assemblea === null && $p->tipo_autorizzazione === 'urgenza');
        $urgenti = \App\Services\Riparto\GiornoDellUrgenza::perPiani($piani->pluck('id')->map(fn ($id) => (int) $id)->values()->all());
        $piano = $piani->first(fn ($p) => ($urgenti[(int) $p->id] ?? null) === substr($giorno, 0, 10));

        return $piano === null ? null : 'Le spese senza periodo sulla fattura vanno a chi era titolare il '
            . \Illuminate\Support\Carbon::parse($giorno)->format('d/m/Y') . ', il giorno dei lavori urgenti scritto nel piano «' . $piano->nome . '», come le rate.';
    }

    /** La delibera dell'opera di una gestione per un esercizio: la data, o `null` con il motivo. @return array{delibera: ?string, motivo: ?string} */
    public function perEsercizio(Gestione $gestione, ?int $esercizioId): array
    {
        [$delibereDi, $prima, $esercizioDelPrimo] = $this->delibere(PianoRate::where('gestione_id', $gestione->id)->delRiparto()->orderBy('id')->get());
        $sue = $esercizioId !== null && $esercizioId === $esercizioDelPrimo ? ($delibereDi[$esercizioId] ?? []) : [];
        if (count($sue) > 1) {
            return ['delibera' => null, 'motivo' => self::FRASE_DELIBERE_DIVERSE];
        }

        return ['delibera' => count($sue) === 1 ? $sue[0] : $prima, 'motivo' => null];
    }
}
