<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Verbale;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Enums\Assemblee\EsitoSeduta;
use App\Enums\Assemblee\StatoVerbale;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\User;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * I verbali di un'assemblea: **un verbale per riunione** (decisione 595 (1)). Una riunione è una seduta che si è aperta e non è stata segnata deserta: la
 * tenuta, la sospesa (il verbale dei punti di quel giorno; la seduta che prosegue ha il suo) e quella aperta e poi rinviata dalla sala (la riunione c'è
 * stata). Le sedute deserte non hanno un verbale: sono annotate nel registro come «Mancata costituzione».
 *
 * Il verbale di una riunione è l'ultima riga non annullata della seduta; nasce `in_sala` con la prima discussione scritta (o alla prima apertura della pagina
 * del verbale), e una registrazione annullata ne lascia una nuova in bozza con lo stesso testo.
 */
final class VerbaliDellAssemblea
{
    /** Il registro del perimetro dell'assemblea: `condominio`, `scala:<id>`, `palazzina:<id>` (decisione 598: la scala e la palazzina hanno il loro). */
    public static function registroDi(Assemblea $assemblea): string
    {
        return match ($assemblea->ambito) {
            AmbitoAssemblea::SCALA => $assemblea->scala_id !== null ? 'scala:' . $assemblea->scala_id : 'condominio',
            AmbitoAssemblea::PALAZZINA => $assemblea->palazzina_id !== null ? 'palazzina:' . $assemblea->palazzina_id : 'condominio',
            default => 'condominio',
        };
    }

    /** È una riunione: aperta, e non segnata deserta. */
    public static function eUnaRiunione(SedutaDellAssemblea $seduta): bool
    {
        return $seduta->aperta_il !== null && $seduta->esito !== EsitoSeduta::DESERTA;
    }

    /** Le riunioni dell'assemblea, nell'ordine. @return Collection<int, SedutaDellAssemblea> */
    public static function riunioni(Assemblea $assemblea): Collection
    {
        return $assemblea->sedute()->get()->filter(fn (SedutaDellAssemblea $s) => self::eUnaRiunione($s))->values();
    }

    /** Il verbale di adesso della riunione: l'ultimo non annullato, o null. */
    public static function dellaSeduta(SedutaDellAssemblea $seduta): ?VerbaleDellAssemblea
    {
        return VerbaleDellAssemblea::query()->where('seduta_id', $seduta->id)->where('stato', '!=', StatoVerbale::ANNULLATO->value)->orderByDesc('id')->first();
    }

    /** Il verbale registrato della riunione, o null. */
    public static function registratoDellaSeduta(int $sedutaId): ?VerbaleDellAssemblea
    {
        return VerbaleDellAssemblea::query()->where('seduta_id', $sedutaId)->where('stato', StatoVerbale::REGISTRATO->value)->orderByDesc('id')->first();
    }

    /**
     * Il verbale della riunione, creato se manca (`in_sala`): la prima discussione scritta, o la pagina del verbale aperta. Una seduta che non è una riunione
     * (non ancora aperta, deserta) non ha un verbale. Due prime discussioni nello stesso istante (il tablet in sala e il portatile del segretario) non fanno due
     * verbali della stessa riunione: la riga della seduta si blocca prima di cercare il verbale, così la seconda trova quello della prima.
     */
    public static function perLaSeduta(SedutaDellAssemblea $seduta, ?User $utente): VerbaleDellAssemblea
    {
        if (! self::eUnaRiunione($seduta)) {
            throw ValidationException::withMessages(['verbale' => $seduta->esito === EsitoSeduta::DESERTA
                ? 'La seduta è deserta: non ha un verbale, ma un\'annotazione nel registro («Mancata costituzione»).'
                : 'La seduta non è ancora aperta: il verbale si scrive dall\'apertura della sala.']);
        }

        return DB::transaction(function () use ($seduta, $utente) {
            SedutaDellAssemblea::query()->whereKey($seduta->id)->lockForUpdate()->first();
            $esistente = self::dellaSeduta($seduta);
            if ($esistente !== null) {
                return $esistente;
            }
            $assemblea = $seduta->assemblea;

            return RegistroModifiche::durante(GestiDelRegistro::dellaSalaSenzaRiga($assemblea), fn () => VerbaleDellAssemblea::create([
                'assemblea_id' => $assemblea->id, 'condominio_id' => $assemblea->condominio_id, 'registro' => self::registroDi($assemblea),
                'seduta_id' => $seduta->id, 'stato' => StatoVerbale::IN_SALA->value, 'created_by' => $utente?->id,
            ]));
        });
    }

    /** Il verbale dell'assemblea dato dal suo numero: di questa assemblea, o il gesto si rifiuta. */
    public static function diQuestaAssemblea(Assemblea $assemblea, mixed $id): VerbaleDellAssemblea
    {
        return (is_numeric($id) ? VerbaleDellAssemblea::query()->where('assemblea_id', $assemblea->id)->whereKey((int) $id)->first() : null)
            ?? throw ValidationException::withMessages(['verbale' => 'Il verbale non è di questa assemblea.']);
    }

    /** I numeri dei verbali registrati dell'assemblea, nell'ordine: «42», «42 e 43» (una riunione sospesa e la sua prosecuzione). */
    public static function numeriRegistrati(Assemblea $assemblea): string
    {
        $numeri = VerbaleDellAssemblea::query()->where('assemblea_id', $assemblea->id)->where('stato', StatoVerbale::REGISTRATO->value)->orderBy('numero')
            ->pluck('numero')->map(fn ($n) => (string) $n)->all();

        return \App\Services\Assemblee\Convocazione\ConvocatiDellAssemblea::elenco($numeri);
    }

    /** Il numero del verbale registrato della riunione di quel giorno, per la frase di una delibera: null se non c'è. */
    public static function numeroDelGiorno(int $assembleaId, ?string $giorno): ?int
    {
        if ($giorno === null) {
            return null;
        }
        $sedute = SedutaDellAssemblea::query()->where('assemblea_id', $assembleaId)->whereDate('fissata_il', $giorno)->pluck('id');
        if ($sedute->isEmpty()) {
            return null;
        }
        $numero = VerbaleDellAssemblea::query()->whereIn('seduta_id', $sedute)->where('stato', StatoVerbale::REGISTRATO->value)->orderByDesc('id')->value('numero');

        return $numero === null ? null : (int) $numero;
    }
}
