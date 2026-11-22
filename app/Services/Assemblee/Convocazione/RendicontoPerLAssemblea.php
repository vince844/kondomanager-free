<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Convocazione;

use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\OggettoDelPunto;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Esercizio;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\RendicontoVersione;
use App\Models\User;
use App\Services\Assemblee\OrdineDelGiorno;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Validation\ValidationException;

/**
 * «Ferma per l'assemblea» collegato al punto del rendiconto (§3 P16 del progetto della tappa 2): la domanda «per quale assemblea» (quella in preparazione con il
 * punto del rendiconto di quell'esercizio, o nessuna), il collegamento della versione al punto («Parla di», ruolo `documento`, con la riga «Rendiconto 2025,
 * versione 2, allegato al punto 1»), la riga di stato «In assemblea il 15/12 · convocazione spedita il 03/12», e **la versione spedita che non si elimina**:
 * è ciò che i condòmini hanno ricevuto. «Registra l'approvazione» resta com'è (tappa 5).
 */
final class RendicontoPerLAssemblea
{
    /**
     * Le assemblee in preparazione del condominio con il punto del rendiconto di questo esercizio, per la domanda «per quale assemblea».
     *
     * @return list<array{id: int, punto_id: int, nome: string}>
     */
    public static function assemblee(Esercizio $esercizio): array
    {
        $punti = PuntoDellOrdineDelGiorno::query()->where('materia', MateriaPunto::RENDICONTO->value)->where('esercizio_id', $esercizio->id)
            ->whereIn('assemblea_id', Assemblea::query()->where('condominio_id', $esercizio->condominio_id)->where('stato', StatoAssemblea::IN_PREPARAZIONE->value)->select('id'))
            ->with('assemblea')->orderBy('id')->get();

        return $punti->map(fn (PuntoDellOrdineDelGiorno $p) => ['id' => (int) $p->assemblea_id, 'punto_id' => (int) $p->id,
            'nome' => 'Assemblea ' . mb_strtolower($p->assemblea->nome()) . ', punto ' . $p->numero])->values()->all();
    }

    /**
     * Prima di fermare la versione: chi lega la versione a un'assemblea «Gestisce le assemblee», e l'assemblea ha, in preparazione, il punto del rendiconto
     * di questo esercizio. Altrimenti l'errore, e la versione non nasce (una versione fermata e non legata resterebbe a metà).
     */
    public static function puntoPer(Assemblea $assemblea, int $esercizioId, int $condominioId, ?User $utente): PuntoDellOrdineDelGiorno
    {
        if (! \App\Services\Assemblee\AccessoAlleAssemblee::gestisce($utente)) {
            throw ValidationException::withMessages(['assemblea_id' => 'Per legare la versione a un\'assemblea serve «Gestisce le assemblee».']);
        }
        $punto = PuntoDellOrdineDelGiorno::query()->where('assemblea_id', $assemblea->id)->where('materia', MateriaPunto::RENDICONTO->value)
            ->where('esercizio_id', $esercizioId)->orderBy('numero')->first();
        if ($punto === null || $assemblea->stato !== StatoAssemblea::IN_PREPARAZIONE || (int) $assemblea->condominio_id !== $condominioId) {
            throw ValidationException::withMessages(['assemblea_id' => 'Questa assemblea non ha, in preparazione, il punto del rendiconto di questo esercizio.']);
        }

        return $punto;
    }

    /** Collega la versione fermata al punto del rendiconto dell'assemblea scelta: una riga sull'assemblea, con il campo «Parla di» del punto nel lotto. */
    public static function allega(Assemblea $assemblea, RendicontoVersione $versione, User $utente): PuntoDellOrdineDelGiorno
    {
        $punto = self::puntoPer($assemblea, (int) $versione->esercizio_id, (int) $versione->condominio_id, $utente);
        $oggetti = $punto->oggetti()->get()->reject(fn (OggettoDelPunto $o) => $o->oggetto_tipo === 'rendiconto_versione')
            ->map(fn (OggettoDelPunto $o) => [$o->oggetto_tipo, (int) $o->oggetto_id, (string) $o->ruolo?->value])->values()->all();
        $oggetti[] = ['rendiconto_versione', (int) $versione->id, 'documento'];
        $anno = $versione->esercizio?->data_inizio?->format('Y') ?? (string) $versione->esercizio?->nome;
        RegistroModifiche::durante(GestiDelRegistro::rendicontoAllegato($assemblea, $anno, (int) $versione->numero, (int) $punto->numero),
            fn () => OrdineDelGiorno::riscrivi($punto, ['oggetti' => $oggetti], $utente));

        return $punto->refresh();
    }

    /**
     * La riga di stato della versione: in quale assemblea, e se la convocazione è partita. `spedita`: la versione è andata ai condòmini e non si elimina.
     *
     * @return ?array{frase: string, spedita: bool}
     */
    public static function inAssemblea(RendicontoVersione $versione): ?array
    {
        $punto = PuntoDellOrdineDelGiorno::query()->whereIn('id', OggettoDelPunto::query()->where('oggetto_tipo', 'rendiconto_versione')
            ->where('oggetto_id', $versione->id)->select('punto_id'))->with('assemblea')->orderByDesc('id')->first();
        if ($punto === null || $punto->assemblea === null || $punto->assemblea->stato === StatoAssemblea::ANNULLATA) {
            return null;
        }
        $a = $punto->assemblea;
        $prima = self::primaSpedizione($a);
        $frase = 'In assemblea il ' . ($a->data?->format('d/m') ?? '') . ', punto ' . $punto->numero
            . ($prima !== null ? ' · convocazione spedita il ' . $prima->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m')
                : ' · convocazione non ancora spedita');

        return ['frase' => $frase, 'spedita' => $prima !== null];
    }

    /** Il momento in cui la convocazione è partita la prima volta (il primo invio spedito), o null: ciò che è fermato fino ad allora è «lo spedito». */
    public static function primaSpedizione(Assemblea $assemblea): ?\Illuminate\Support\Carbon
    {
        $prima = RegistroInvio::query()->where('documentabile_type', $assemblea->getMorphClass())->where('documentabile_id', $assemblea->id)
            ->where('tipo', RegistroInvio::TIPO_CONVOCAZIONE)->where('stato', '!=', RegistroInvio::STATO_NON_PARTITA)->orderBy('emesso_il')->first();

        return $prima?->emesso_il === null ? null : \Illuminate\Support\Carbon::parse($prima->emesso_il);
    }
}
