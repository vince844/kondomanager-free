<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito;

use App\Enums\Assemblee\AmbitoDellaDelega;
use App\Enums\Assemblee\MateriaPunto;
use App\Models\Assemblee\Delibera;
use App\Models\Condominio;
use App\Services\RegistroModifiche\FormatoDelValore;
use Illuminate\Support\Carbon;

/**
 * La delega all'amministratore con il tetto (decisione 622 (3); art. 1131 c. 1 c.c., riletto su Normattiva il 20/11/2026: «i maggiori poteri conferitigli …
 * dall'assemblea»). **La delibera è la delega**: il tetto è l'importo della delibera (in centesimi; senza, quello del punto), l'ambito e la scadenza stanno in
 * `delibere.dati.delega` (o, finché la delibera non li ha, nei dati del punto, scritti in «Prepara l'assemblea»). Una lettura sola, che lo stralcio chiamerà
 * nella sua beta: la delega in vigore per l'ambito, il giorno e la cifra, o il perché non c'è.
 *
 * Due risposte proposte, in attesa di Vincenzo, ognuna in una costante:
 * - **senza il tetto o senza l'ambito la delega non vale** finché non si scrivono ({@see SENZA_TETTO_NON_VALE}): i poteri «conferiti» non si presumono;
 * - **fra due deleghe dello stesso ambito vale l'ultima** ({@see VALE_L_ULTIMA}): l'assemblea che decide dopo ha ridotto il potere, anche se nessuno ha
 *   segnato la revoca della prima; oltre il suo tetto la frase lo dice («la delibera del 15/12/2026 ha un tetto più basso»).
 */
final class DelegheDellAmministratore
{
    /** Una delega senza il tetto o senza l'ambito scritti non vale. Con `false`, senza tetto vale per qualunque cifra e l'ambito di serie è quello dei fornitori. */
    public const SENZA_TETTO_NON_VALE = true;

    /** Fra due deleghe dello stesso ambito vale l'ultima in vigore. Con `false`, vale la più ampia in vigore. */
    public const VALE_L_ULTIMA = true;

    /**
     * La delega in vigore che copre la cifra quel giorno, o il perché no: «oltre il tetto (€ 2.000,00)», «scaduta il 31/12/2027», «revocata il …», «la delega del
     * 16/12/2026 non ha il tetto: scrivilo nel punto».
     *
     * @return array{delibera: ?Delibera, frase: ?string}
     */
    public static function valida(Condominio $condominio, string $ambito, string $giorno, int $centesimi): array
    {
        $perche = null;
        $candidate = Delibera::query()->where('condominio_id', $condominio->id)->whereHas('punto', fn ($q) => $q->where('materia', MateriaPunto::DELEGA->value))
            ->where('deliberata_il', '<=', $giorno)->with('punto')->orderByDesc('deliberata_il')->orderByDesc('id')->get();
        $ultima = null;
        foreach ($candidate as $d) {
            $delega = self::delega($d);
            if ($delega === null || ! $d->eApprovata()) {
                continue;
            }
            $quando = $d->deliberata_il?->format('d/m/Y');
            if ($delega['ambito'] === null) {
                $perche ??= 'la delega del ' . $quando . ' non ha l\'ambito: scrivilo nel punto';
                continue;
            }
            if ($delega['ambito'] !== $ambito) {
                continue;
            }
            if ($d->eRevocata() && $d->revocata_il->format('Y-m-d') <= $giorno) {
                $perche ??= 'revocata il ' . $d->revocata_il->format('d/m/Y');
                continue;
            }
            if ($d->eAnnullata()) {
                $perche ??= 'annullata dal giudice il ' . $d->annullata_il->format('d/m/Y');
                continue;
            }
            // Da qui la delega è in vigore per l'ambito: con la risposta proposta è l'ultima, e decide lei.
            $decide = self::VALE_L_ULTIMA && $ultima === null;
            $ultima ??= $d;
            if ($delega['tetto'] === null && self::SENZA_TETTO_NON_VALE) {
                $perche ??= 'la delega del ' . $quando . ' non ha il tetto: scrivilo nel punto';
                if ($decide) {
                    break;
                }
                continue;
            }
            if ($delega['fino_al'] !== null && $giorno > $delega['fino_al']) {
                $perche ??= 'scaduta il ' . Carbon::parse($delega['fino_al'])->format('d/m/Y');
                if ($decide) {
                    break;
                }
                continue;
            }
            if ($delega['tetto'] !== null && $centesimi > $delega['tetto']) {
                $perche ??= 'oltre il tetto (' . FormatoDelValore::testo($delega['tetto'], 'euro') . ')';
                if ($decide) {
                    $perche .= self::piuAmpia($candidate, $d, $ambito, $giorno, $centesimi);
                    break;
                }
                continue;
            }

            return ['delibera' => $d, 'frase' => null];
        }

        return ['delibera' => null, 'frase' => $perche ?? 'nessuna delega dell\'assemblea per ' . mb_strtolower(AmbitoDellaDelega::parolaDi($ambito) ?? $ambito)];
    }

    /**
     * La delega di una delibera: il tetto in centesimi, l'ambito, la scadenza, se è in vigore oggi, e la frase («€ 2.000,00 fino al 31/12/2027»). Null se la
     * delibera non è di un punto di delega. Il tetto o l'ambito che mancano restano null, e la delega non è in vigore finché non si scrivono.
     *
     * @return ?array{tetto: ?int, ambito: ?string, ambito_parola: ?string, fino_al: ?string, in_vigore: bool, frase: string}
     */
    public static function delega(Delibera $delibera): ?array
    {
        $delibera->loadMissing('punto');
        if ($delibera->punto?->materia !== MateriaPunto::DELEGA) {
            return null;
        }
        $dati = (array) (((array) ($delibera->dati ?? []))['delega'] ?? ((array) ($delibera->punto->dati ?? []))['delega'] ?? []);
        $tetto = $delibera->importo ?? $delibera->punto->importo;
        $tetto = $tetto === null ? null : (int) $tetto;
        $fino = isset($dati['fino_al']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $dati['fino_al']) === 1 ? (string) $dati['fino_al'] : null;
        $ambito = isset($dati['ambito']) && (string) $dati['ambito'] !== '' ? (string) $dati['ambito']
            : (self::SENZA_TETTO_NON_VALE ? null : AmbitoDellaDelega::ACCORDI_FORNITORI->value);
        $scritta = ! self::SENZA_TETTO_NON_VALE || ($tetto !== null && $ambito !== null);
        $oggi = \App\Helpers\DateHelper::oggiUtente();
        $inVigore = $scritta && $delibera->eApprovata() && ! $delibera->eRevocata() && ! $delibera->eAnnullata() && ($fino === null || $oggi <= $fino);
        $frase = ($tetto !== null ? FormatoDelValore::testo($tetto, 'euro') : 'senza tetto') . ($fino !== null ? ' fino al ' . Carbon::parse($fino)->format('d/m/Y') : '');
        if (! $scritta) {
            $frase .= ': non vale finché ' . ($tetto === null && $ambito === null ? 'il tetto e l\'ambito non sono scritti' : ($tetto === null
                ? 'il tetto non è scritto' : 'l\'ambito non è scritto')) . ' nel punto';
        }

        return ['tetto' => $tetto, 'ambito' => $ambito, 'ambito_parola' => $ambito === null ? null : AmbitoDellaDelega::parolaDi($ambito), 'fino_al' => $fino,
            'in_vigore' => $inVigore, 'frase' => $frase];
    }

    /** «: la delibera del 15/12/2026 ha un tetto più basso», se una delega precedente dello stesso ambito avrebbe coperto la cifra. @param \Illuminate\Support\Collection<int, Delibera> $candidate */
    private static function piuAmpia(\Illuminate\Support\Collection $candidate, Delibera $ultima, string $ambito, string $giorno, int $centesimi): string
    {
        foreach ($candidate as $d) {
            if ((int) $d->id === (int) $ultima->id || ! $d->eApprovata() || $d->eAnnullata() || ($d->eRevocata() && $d->revocata_il->format('Y-m-d') <= $giorno)) {
                continue;
            }
            $delega = self::delega($d);
            if ($delega !== null && $delega['ambito'] === $ambito && $delega['tetto'] !== null && $centesimi <= $delega['tetto']
                && ($delega['fino_al'] === null || $giorno <= $delega['fino_al'])) {
                return ': la delibera del ' . $ultima->deliberata_il?->format('d/m/Y') . ' ha un tetto più basso';
            }
        }

        return '';
    }
}
