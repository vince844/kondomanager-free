<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Enums\Assemblee\RuoloCollegamento;
use App\Enums\Assemblee\StatoMemo;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\MemoDellAssemblea;
use App\Models\Condominio;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\User;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * R12 del progetto della tappa 2 con la decisione 561: **la ratifica si lega alla delibera della riunione**. Il pagamento resta com'è: la spesa urgente si paga
 * subito con la nota di oggi (`approvaSforo`), e si riferisce alla prima assemblea (art. 1135 c. 2 c.c.); quando l'assemblea ratifica, si sceglie la delibera.
 *
 * `ratifica()` lega una delibera a una fattura **in sforo**, **sbloccata per l'urgenza** (`dati_extra.ratifica_assembleare` con la chiave `urgenza`) o
 * **ratificata prima della 1.12 con la nota** («Collega la delibera»), sempre senza un collegamento `ratifica`: la porta ad `approvata`
 * se non lo è; in `dati_extra.ratifica_assembleare` tiene le chiavi di oggi (`note`, `approvato_il`
 * — il giorno della registrazione —, `approvato_da`) e copia `deliberata_il` e `numero_verbale` per la lettura; la delibera sta solo nel collegamento (§13,
 * rilievo 22). Chiude i compiti «Assemblee da convocare» e «Ratifiche in assemblea» con la frase («Chiuso dalla delibera del 15/12/2026, punto 3») e i memo
 * della fattura. Una riga sola sulla fattura.
 */
final class RatificaDelloSforo
{
    public static function ratifica(FatturaPassiva $fattura, Delibera $delibera, ?string $nota, User $utente): FatturaPassiva
    {
        if (! self::aspettaLaRatifica($fattura) && ! self::diPrimaSenzaDelibera($fattura)) {
            throw ValidationException::withMessages(['fattura' => 'Questa fattura non aspetta una ratifica dell\'assemblea.']);
        }
        abort_if((int) $fattura->condominio_id !== (int) $delibera->condominio_id, 404);

        RegistroModifiche::durante(GestiDelRegistro::ratifica($fattura, $delibera), function () use ($fattura, $delibera, $nota, $utente) {
            CollegamentiDelleDelibere::collega($fattura, RuoloCollegamento::RATIFICA->value, $delibera, $nota, $utente);
            $extra = (array) ($fattura->dati_extra ?? []);
            $ratifica = (array) ($extra['ratifica_assembleare'] ?? []);
            $ratifica += ['note' => $nota === null || trim($nota) === '' ? null : trim($nota), 'approvato_il' => now()->toIso8601String(),
                'approvato_da' => $utente->id];
            $ratifica['deliberata_il'] = $delibera->deliberata_il?->format('Y-m-d');
            $ratifica['numero_verbale'] = DeliberaInParole::numeroDelVerbale($delibera);
            $extra['ratifica_assembleare'] = $ratifica;
            $fattura->update(['stato_approvazione' => 'approvata', 'dati_extra' => $extra]);
            $frase = 'Chiuso dalla ' . lcfirst(DeliberaInParole::breve($delibera, scritta: true));
            $chiusi = ChiusuraDeiCompiti::delloSforo($fattura, $frase);
            foreach (MemoDellAssemblea::query()->where('origine_tipo', 'fattura_passiva')->where('origine_id', $fattura->id)
                ->whereIn('stato', [StatoMemo::IN_ATTESA->value, StatoMemo::NEL_PUNTO->value])->get() as $memo) {
                MemoriaDelCondominio::deliberato($memo);
            }

            return ['chiusi' => $chiusi];
        });

        return $fattura->refresh();
    }

    /**
     * Una delibera di un giorno prima della fattura: non si rifiuta (la fattura può arrivare dopo la riunione che ha ratificato i lavori, o portare una
     * data diversa da quella dei lavori), ma si dice, perché è più probabile che sia la delibera sbagliata.
     */
    public static function avvisoDellaData(FatturaPassiva $fattura, Delibera $delibera): ?string
    {
        $documento = $fattura->data_documento === null ? null : \Illuminate\Support\Carbon::parse($fattura->data_documento)->format('Y-m-d');
        $giorno = $delibera->deliberata_il?->format('Y-m-d');
        if ($documento === null || $giorno === null || $giorno >= $documento) {
            return null;
        }

        return 'La delibera è del ' . $delibera->deliberata_il->format('d/m/Y') . ', prima della fattura del '
            . \Illuminate\Support\Carbon::parse($documento)->format('d/m/Y') . ': controlla che sia quella giusta.';
    }

    /**
     * In sforo, o sbloccata per l'urgenza (la nota con la chiave `urgenza`, scritta da `approva-sforo` dalla 1.12) e senza il collegamento della ratifica
     * (decisione 561). Una ratifica fatta prima della 1.12 con la nota, senza la chiave, è ratificata e non aspetta niente.
     */
    public static function aspettaLaRatifica(FatturaPassiva $fattura): bool
    {
        if ($fattura->stato_approvazione === 'sforo_motivato') {
            return true;
        }

        return self::sbloccataPerLUrgenza($fattura) && ! self::haLaDelibera($fattura);
    }

    /** La nota dello sblocco per l'urgenza: quella che `approva-sforo` scrive dalla 1.12, con la chiave `urgenza`. */
    public static function sbloccataPerLUrgenza(FatturaPassiva $fattura): bool
    {
        $nota = ((array) ($fattura->dati_extra ?? []))['ratifica_assembleare'] ?? null;

        return is_array($nota) && ($nota['urgenza'] ?? false) === true;
    }

    /**
     * La fattura è pagata, anche in parte: lo sblocco per l'urgenza si dice «Sbloccata per l'urgenza» finché non è pagata, «Pagata in parte per l'urgenza»
     * fino al saldo, «Pagata per l'urgenza» dopo. Una regola sola per la pagina della fattura e per il punto proposto.
     */
    public static function pagata(FatturaPassiva $fattura): bool
    {
        return in_array(self::statoDelPagamento($fattura), ['pagata', 'parziale'], true);
    }

    /** La fattura è pagata solo in parte. */
    public static function pagataInParte(FatturaPassiva $fattura): bool
    {
        return self::statoDelPagamento($fattura) === 'parziale';
    }

    /** «Sbloccata», «Pagata in parte», «Pagata»: la parola davanti a «per l'urgenza». */
    public static function parolaDelPagamento(FatturaPassiva $fattura): string
    {
        return match (true) {
            self::pagataInParte($fattura) => 'Pagata in parte',
            self::pagata($fattura) => 'Pagata',
            default => 'Sbloccata',
        };
    }

    /** «Sbloccata per l'urgenza» finché la fattura non è pagata, «Pagata in parte per l'urgenza» fino al saldo, «Pagata per l'urgenza» dopo. */
    public static function parolaDellUrgenza(FatturaPassiva $fattura): string
    {
        return self::parolaDelPagamento($fattura) . ' per l\'urgenza';
    }

    private static function statoDelPagamento(FatturaPassiva $fattura): string
    {
        return $fattura->stato_pagamento instanceof \BackedEnum ? $fattura->stato_pagamento->value : (string) $fattura->stato_pagamento;
    }

    /** Una ratifica fatta prima della 1.12 con la nota (senza la chiave `urgenza`) e non ancora legata a una delibera: si offre «Collega la delibera». */
    public static function diPrimaSenzaDelibera(FatturaPassiva $fattura): bool
    {
        $nota = ((array) ($fattura->dati_extra ?? []))['ratifica_assembleare'] ?? null;

        return is_array($nota) && ! self::sbloccataPerLUrgenza($fattura) && $fattura->stato_approvazione === 'approvata' && ! self::haLaDelibera($fattura);
    }

    private static function haLaDelibera(FatturaPassiva $fattura): bool
    {
        return DB::table('delibere_collegamenti')->where('oggetto_tipo', 'fattura_passiva')->where('oggetto_id', $fattura->id)
            ->where('ruolo', RuoloCollegamento::RATIFICA->value)->exists();
    }

    /** Le fatture che aspettano la ratifica, per il sospeso del §5 e per le pagine. @return Collection<int, FatturaPassiva> */
    public static function daRatificare(Condominio $condominio): Collection
    {
        return FatturaPassiva::query()->where('condominio_id', $condominio->id)
            ->where(fn ($q) => $q->where('stato_approvazione', 'sforo_motivato')
                ->orWhere(fn ($w) => $w->where('stato_approvazione', 'approvata')->whereNotNull('dati_extra->ratifica_assembleare')))
            ->orderBy('id')->get()->filter(fn (FatturaPassiva $f) => self::aspettaLaRatifica($f))->values();
    }
}
