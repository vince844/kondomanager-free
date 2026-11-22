<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito;

use App\Helpers\DateHelper;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\OggettoDelPunto;
use App\Models\User;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\DeliberaInParole;
use App\Services\Assemblee\DelibereDelCondominio;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Validation\ValidationException;

/**
 * I segni sulla delibera (R11; principi 4 e 5): «Segna impugnata» (il giorno e l'atto, {@see \App\Services\Assemblee\Verbale\TerminiDellImpugnazione}),
 * «Segna annullata dal giudice» (il giorno e il provvedimento), «Revocata dalla delibera del …» (una delibera successiva dello stesso condominio). **Nessun
 * servizio fuori dalle pagine li legge**: l'impugnazione non sospende l'esecuzione (art. 1137 c. 3 c.c.), e le schede che usano la delibera lo dicono, con
 * la loro data di oggi ({@see CollegamentiDelleDelibere::posti()}). «Scegli la delibera» spegne l'annullata e la revocata; l'impugnata si sceglie ancora.
 */
final class SegniDellaDelibera
{
    /** «Segna annullata dal giudice»: il giorno e il provvedimento. Una riga sulla delibera, con le schede che la usano. */
    public static function annullata(Delibera $delibera, string $giorno, string $provvedimento, User $utente): Delibera
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $giorno) !== 1 || $giorno > DateHelper::oggiUtente()) {
            throw ValidationException::withMessages(['giorno' => 'Il giorno del provvedimento non può essere dopo oggi.']);
        }
        if ($delibera->deliberata_il !== null && $giorno < $delibera->deliberata_il->format('Y-m-d')) {
            throw ValidationException::withMessages(['giorno' => 'Il provvedimento non può essere prima della delibera.']);
        }
        $provvedimento = trim($provvedimento);
        if ($provvedimento === '') {
            throw ValidationException::withMessages(['provvedimento' => 'Scrivi il provvedimento del giudice: la sentenza, l\'ordinanza, il numero.']);
        }
        $posti = CollegamentiDelleDelibere::frasiDeiPosti(CollegamentiDelleDelibere::posti($delibera));
        RegistroModifiche::durante(GestiDelRegistro::deliberaAnnullata($delibera, $giorno, $provvedimento, $posti),
            fn () => $delibera->update(['annullata_il' => $giorno, 'annullata_nota' => $provvedimento]));

        return $delibera->refresh();
    }

    /**
     * «Segna revocata»: la delibera che revoca è dello stesso condominio, approvata, di un giorno dopo. La revoca vale dal giorno di quella riunione.
     */
    public static function revocata(Delibera $delibera, Delibera $da, User $utente): Delibera
    {
        abort_if((int) $da->condominio_id !== (int) $delibera->condominio_id, 404);
        if ((int) $da->id === (int) $delibera->id || ! $da->eApprovata()) {
            throw ValidationException::withMessages(['revocata_da_delibera_id' => 'La revoca è una delibera approvata dell\'assemblea, un\'altra.']);
        }
        if ($da->deliberata_il === null || $delibera->deliberata_il === null || $da->deliberata_il->format('Y-m-d') <= $delibera->deliberata_il->format('Y-m-d')) {
            throw ValidationException::withMessages(['revocata_da_delibera_id' => 'La delibera che revoca viene dopo quella revocata: scegline una successiva.']);
        }
        $posti = CollegamentiDelleDelibere::frasiDeiPosti(CollegamentiDelleDelibere::posti($delibera));
        RegistroModifiche::durante(GestiDelRegistro::deliberaRevocata($delibera, $da, $posti),
            fn () => $delibera->update(['revocata_il' => $da->deliberata_il->format('Y-m-d'), 'revocata_da_delibera_id' => $da->id]));

        return $delibera->refresh();
    }

    /**
     * Le delibere che possono revocarla: successive, dello stesso condominio, approvate; in testa quelle di un punto che parla di questa delibera.
     *
     * @return list<array<string, mixed>>
     */
    public static function proposteDiRevoca(Delibera $delibera): array
    {
        $parlano = OggettoDelPunto::query()->where('oggetto_tipo', 'delibera')->where('oggetto_id', $delibera->id)->pluck('punto_id')->map(fn ($id) => (int) $id)->all();
        $giorno = $delibera->deliberata_il?->format('Y-m-d') ?? '0000-00-00';

        return Delibera::query()->where('condominio_id', $delibera->condominio_id)->whereKeyNot($delibera->id)->where('deliberata_il', '>', $giorno)
            ->with(['assemblea', 'punto', 'revocataDa.punto'])->orderByDesc('deliberata_il')->orderByDesc('id')->get()
            ->filter(fn (Delibera $d) => $d->eApprovata())
            ->sortBy(fn (Delibera $d) => in_array((int) $d->punto_id, $parlano, true) ? 0 : 1)
            ->map(fn (Delibera $d) => DelibereDelCondominio::riga($d) + ['proposta' => in_array((int) $d->punto_id, $parlano, true)
                ? 'Proposta perché il punto parla di questa delibera' : null])->values()->all();
    }

    /**
     * I segni come le schede li dicono: «Impugnata il 12/01/2027 · non ferma niente», «Annullata dal giudice il 14/03/2028 · sentenza n. 1187/2028»,
     * «Revocata il 20/05/2027 dalla delibera del 20/05/2027, punto 6».
     *
     * @return list<array{tipo: string, frase: string}>
     */
    public static function frasi(Delibera $delibera): array
    {
        $frasi = [];
        if ($delibera->impugnata_il !== null) {
            $frasi[] = ['tipo' => 'impugnata', 'frase' => 'Impugnata il ' . $delibera->impugnata_il->format('d/m/Y') . ' · non ferma niente'];
        }
        if ($delibera->annullata_il !== null) {
            $frasi[] = ['tipo' => 'annullata', 'frase' => 'Annullata dal giudice il ' . $delibera->annullata_il->format('d/m/Y')
                . (trim((string) $delibera->annullata_nota) !== '' ? ' · ' . trim((string) $delibera->annullata_nota) : '')];
        }
        if ($delibera->revocata_il !== null) {
            $frasi[] = ['tipo' => 'revocata', 'frase' => DelibereDelCondominio::fraseDellaRevoca($delibera)];
        }

        return $frasi;
    }

    /** «Delibera del 15/12/2026, punto 4 (verbale n. 42)». */
    public static function nome(Delibera $delibera): string
    {
        return DeliberaInParole::breve($delibera);
    }
}
