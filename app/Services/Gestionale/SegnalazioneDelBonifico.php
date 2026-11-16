<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Enums\EventoTipo;
use App\Models\Anagrafica;
use App\Models\Evento;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\ScritturaContabile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Il bonifico segnalato dal portale, quando il suo compito si chiude: la rata segnalata non resta «in verifica» (`reported`) per sempre. Pagata, in parte
 * pagata o di nuovo da versare, come dicono le quote dopo l'incasso; se il versamento è andato, tutto o in parte, su altre rate della persona (l'imputazione
 * dell'art. 1194 c.c. lo mette sulle più vecchie), la frase del portale lo dice, con le rate e le cifre (`meta.esito_segnalazione`). Il condòmino può segnalare
 * di nuovo una rata tornata da versare. Il rifiuto («Non serve») resta quello di sempre ({@see RispostePromemoria::nonServeDelCompito()}).
 *
 * Lo chiamano la chiusura del compito con l'incasso da «Registra l'incasso» (`IncassoRateController::store`), «Fatto» e «Completa»
 * ({@see RispostePromemoria::fattoDelCompito()}), e la correzione una volta sola delle rate rimaste bloccate prima ({@see sbloccaLeChiuse()}).
 */
final class SegnalazioneDelBonifico
{
    public function __construct(private readonly EventiRataCondomino $eventi) {}

    /** La rata segnalata dal compito, se c'è ancora. */
    public static function rataSegnalata(Evento $compito): ?Evento
    {
        $id = $compito->meta['context']['related_event_id'] ?? null;

        return $id ? Evento::find($id) : null;
    }

    /**
     * «Fatto» sul bonifico si accetta quando la segnalazione non è più in verifica, o quando dopo la segnalazione c'è un incasso della persona (registrato
     * anche dalla pagina degli incassi, senza «Registra l'incasso», e andato magari su altre rate). Altrimenti il condòmino resterebbe in verifica senza un incasso.
     */
    public function fattoSpento(Evento $compito): bool
    {
        $rata = self::rataSegnalata($compito);
        if ($rata === null || ($rata->meta['status'] ?? null) !== 'reported') {
            return false;
        }

        return $this->incassoDopoLaSegnalazione($rata) === null;
    }

    /**
     * Il compito della segnalazione si è chiuso (con l'incasso dato, o con «Fatto»): la rata segnalata si riallinea alle quote, e se il versamento è andato su
     * altre rate la frase lo dice. Una rata pagata, respinta o già riallineata prima non si tocca.
     */
    public function chiusa(Evento $compito, ?ScritturaContabile $incasso = null): void
    {
        $rata = self::rataSegnalata($compito);
        if ($rata === null || ! in_array($rata->meta['status'] ?? null, ['reported', 'partial', 'pending'], true)) {
            return;
        }
        $this->riallinea($rata, $incasso ?? $this->incassoDopoLaSegnalazione($rata));
    }

    /**
     * Le rate rimaste in verifica con il compito della segnalazione già chiuso o tolto (prima di questa correzione l'incasso andato su altre rate le lasciava
     * così): si riallineano come alla chiusura, con l'incasso della persona dopo la segnalazione. Rieseguita non trova niente. Rende quante.
     */
    public function sbloccaLeChiuse(): int
    {
        $aperte = Evento::where('tipo', EventoTipo::VERIFICA_PAGAMENTO->value)->where('is_completed', false)->get(['id', 'meta'])
            ->map(fn ($c) => (int) ($c->meta['context']['related_event_id'] ?? 0))->filter()->flip();
        $quante = 0;
        // Solo le rate in verifica, a blocchi: le altre non si leggono.
        Evento::where('tipo', EventoTipo::SCADENZA_RATA_CONDOMINO->value)->where('meta->status', 'reported')->chunkById(200, function ($blocco) use ($aperte, &$quante) {
            foreach ($blocco as $rata) {
                if (($rata->meta['status'] ?? null) !== 'reported' || isset($aperte[(int) $rata->id])) {
                    continue;
                }
                $this->riallinea($rata, $this->incassoDopoLaSegnalazione($rata));
                $quante++;
            }
        });

        return $quante;
    }

    /** Lo stato della rata dalle quote di oggi, senza tenere la segnalazione; la frase di dove è andato il versamento, se è andato altrove. */
    private function riallinea(Evento $rata, ?ScritturaContabile $incasso): void
    {
        $persona = $rata->anagrafiche()->first();
        $rataId = (int) ($rata->meta['context']['rata_id'] ?? 0);
        $r = $rataId > 0 ? Rata::with('rateQuote.immobile', 'pianoRate')->find($rataId) : null;
        if ($persona === null || $r === null) {
            // Senza le quote non si sa quanto è pagato: una rata in verifica torna da versare, e il condòmino può segnalare di nuovo; le altre restano.
            $meta = $rata->meta ?? [];
            if (($meta['status'] ?? null) === 'reported') {
                $meta['status'] = 'pending';
                $rata->update(['meta' => $meta]);
            }

            return;
        }
        $stato = $this->eventi->stato($r->rateQuote->where('anagrafica_id', $persona->id)->values());
        $this->eventi->applica($rata, $stato, $persona, $r);
        $meta = $rata->meta;
        $frase = $incasso !== null && $stato['status'] !== 'paid' ? $this->doveEAndato($incasso, $persona, $r) : null;
        if ($frase !== null) {
            $meta['esito_segnalazione'] = $frase;
        } else {
            unset($meta['esito_segnalazione']);
        }
        $meta['segnalazione_chiusa_il'] = now()->toIso8601String();
        $rata->meta = $meta;
        $rata->save();
    }

    /**
     * «Il tuo versamento del 10/03/2026, € 150,00, ha pagato € 50,00 di questa rata; il resto è andato su altre rate: rata 1 di «Preventivo 2026», € 100,00.»
     * Oppure, se questa rata non l'ha toccata: «… è andato su altre rate: …. Questa rata resta da versare: quando l'hai pagata, puoi segnalarlo di nuovo.»
     * Null se il versamento è tutto su questa rata.
     */
    private function doveEAndato(ScritturaContabile $incasso, Anagrafica $persona, Rata $questa): ?string
    {
        $incasso->loadMissing(['quotePagate.rata.pianoRate', 'figlie.quotePagate.rata.pianoRate']);
        $righe = $incasso->quotePagate->merge($incasso->figlie->flatMap->quotePagate)
            ->filter(fn ($q) => (int) $q->anagrafica_id === (int) $persona->id && (int) $q->importo > 0 && (int) $q->pivot->importo_pagato > 0);
        if ($righe->isEmpty()) {
            return null;
        }
        $suQuesta = (int) $righe->filter(fn ($q) => (int) $q->rata_id === (int) $questa->id)->sum(fn ($q) => (int) $q->pivot->importo_pagato);
        $altrove = $righe->filter(fn ($q) => (int) $q->rata_id !== (int) $questa->id)->groupBy('rata_id')
            ->map(fn ($g) => ['rata' => $g->first()->rata, 'cifra' => (int) $g->sum(fn ($q) => (int) $q->pivot->importo_pagato)])
            ->sortBy(fn ($x) => [$x['rata']?->data_scadenza?->format('Y-m-d') ?? '', $x['rata']?->numero_rata ?? 0])->values();
        if ($altrove->isEmpty()) {
            return null;
        }
        $totale = $suQuesta + (int) $altrove->sum('cifra');
        $giorno = $righe->map(fn ($q) => $q->pivot->data_pagamento)->filter()->min() ?? $incasso->data_registrazione;
        $elenco = $altrove->map(fn ($x) => 'rata ' . ($x['rata']?->numero_rata ?? '?') . ' di «' . ($x['rata']?->pianoRate?->nome ?? '') . '», ' . self::euro($x['cifra']))->implode('; ');
        $inizio = 'Il tuo versamento del ' . CarbonImmutable::parse((string) $giorno)->format('d/m/Y') . ', ' . self::euro($totale) . ', ';

        return $suQuesta > 0
            ? $inizio . 'ha pagato ' . self::euro($suQuesta) . ' di questa rata; il resto è andato su altre rate: ' . $elenco . '.'
            : $inizio . 'è andato su altre rate: ' . $elenco . '. Questa rata resta da versare: quando l\'hai pagata, puoi segnalarlo di nuovo.';
    }

    /** L'ultimo incasso della persona dopo la segnalazione della rata, se c'è. */
    private function incassoDopoLaSegnalazione(Evento $rata): ?ScritturaContabile
    {
        $persona = $rata->anagrafiche()->first();
        $dal = $rata->meta['reported_at'] ?? null;
        if ($persona === null || $dal === null) {
            return null;
        }
        $id = DB::table('quota_scrittura')
            ->join('rate_quote', 'rate_quote.id', '=', 'quota_scrittura.rate_quota_id')
            ->join('scritture_contabili', 'scritture_contabili.id', '=', 'quota_scrittura.scrittura_contabile_id')
            ->where('rate_quote.anagrafica_id', $persona->id)
            ->where('scritture_contabili.tipo_movimento', 'incasso_rata')
            ->where('scritture_contabili.created_at', '>=', CarbonImmutable::parse((string) $dal)->utc()->format('Y-m-d H:i:s'))
            ->max('scritture_contabili.id');

        return $id === null ? null : ScritturaContabile::find($id);
    }

    private static function euro(int $centesimi): string
    {
        return '€ ' . number_format($centesimi / 100, 2, ',', '.');
    }
}
