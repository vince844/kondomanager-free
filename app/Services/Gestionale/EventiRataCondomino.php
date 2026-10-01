<?php

namespace App\Services\Gestionale;

use App\Enums\CategoriaEventoEnum;
use App\Enums\EventoTipo;
use App\Enums\VisibilityStatus;
use App\Models\Anagrafica;
use App\Models\CategoriaEvento;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\PianoRate;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Il promemoria di una rata nel portale del condòmino: un `Evento` per (rata, persona), creato quando il piano è
 * approvato (`SyncScadenziarioWithPianoRate`) e poi aggiornato dagli incassi e dall'emissione.
 *
 * È uno **snapshot** delle quote di quella persona su quella rata. Finché le quote di una rata non cambiavano
 * intestatario non serviva altro; dalla B3a (1.11.0-beta.34, decisione 25) una vendita fa passare le bozze a chi
 * entra, e il promemoria deve seguire la quota: a chi esce non deve più ricordare una rata che non è sua, a chi entra
 * sì. La costruzione del promemoria sta qui, in un posto solo, per il listener e per il passaggio.
 */
final class EventiRataCondomino
{
    /**
     * Crea il promemoria di `$anagrafica` per `$rata`, dalle sue quote su quella rata.
     *
     * @param Collection<int, \App\Models\Gestionale\RataQuote> $quote le quote di quella persona su quella rata
     */
    public function crea(PianoRate $piano, Rata $rata, Anagrafica $anagrafica, Collection $quote, Condominio $condominio, int $creatoDa, string $nomeGestione, int $categoriaId, int $creditoRataZero = 0): Evento
    {
        [$importoVal, $dettaglioQuote] = $this->dettaglio($quote);
        $descUser = $this->descrizione($anagrafica, $rata, $importoVal);

        $eventoUser = Evento::create([
            'title'       => $rata->numero_rata == 0 ? "Saldo Iniziale - {$piano->nome}" : "Scadenza rata {$rata->numero_rata} - {$piano->nome}",
            'start_time'  => $rata->data_scadenza->copy()->setTime(0, 0),
            'end_time'    => $rata->data_scadenza->copy()->setTime(23, 59),
            'created_by'  => $creatoDa,
            'description' => $descUser,
            'category_id' => $categoriaId,
            'visibility'  => VisibilityStatus::PRIVATE->value,
            'is_approved' => true,
            'timezone'    => config('app.timezone'),
            'meta'        => [
                'type'              => EventoTipo::SCADENZA_RATA_CONDOMINO->value,
                'is_emitted'        => false,
                'requires_action'   => false,
                'status'            => $importoVal <= 0 ? 'paid' : 'pending',
                'importo_originale' => $importoVal,
                'importo_pagato'    => 0,
                'importo_restante'  => $importoVal,
                'dettaglio_quote'   => $dettaglioQuote,
                'gestione'          => $nomeGestione,
                'condominio_nome'   => $condominio->nome,
                'numero_rata'       => $rata->numero_rata,
                'piano_nome'        => $piano->nome,
                'credito_rata_zero' => $creditoRataZero, // NUOVO CAMPO WALLET
                'context' => [
                    'piano_rate_id' => $piano->id,
                    'rata_id'       => $rata->id
                ],
            ],
            'tipo' => EventoTipo::SCADENZA_RATA_CONDOMINO,
        ]);

        $eventoUser->anagrafiche()->attach($anagrafica->id);
        $eventoUser->condomini()->attach($condominio->id);

        return $eventoUser;
    }

    /** Il testo del promemoria: un credito non chiede pagamenti, un debito sì; più la nota della rata, se c'è. */
    private function descrizione(Anagrafica $anagrafica, Rata $rata, int $importo): string
    {
        if ($importo < 0) {
            $testo = "Gentile {$anagrafica->nome}, questa voce rappresenta un credito a tuo favore registrato nella rata n. {$rata->numero_rata}.\n\nNon è richiesto alcun pagamento: l'importo verrà utilizzato automaticamente per compensare le rate successive.";
        } else {
            $testo = "Gentile {$anagrafica->nome}, ti ricordiamo la scadenza della rata condominiale n. {$rata->numero_rata}.\n\nVerifica il dettaglio quote e il netto da versare nello scontrino qui sotto. Dopo aver effettuato il versamento, potrai segnalarlo all'amministratore cliccando sul pulsante corrispondente.";
        }

        if (!empty($rata->note)) $testo .= "\n\nNote: {$rata->note}";

        return $testo;
    }

    /**
     * Il totale e le righe «scontrino» delle quote, dalle regole congelate di ciascuna.
     *
     * @param Collection<int, \App\Models\Gestionale\RataQuote> $quote
     * @return array{0: int, 1: list<array{descrizione: string, importo: int, componente_spesa: int, componente_saldo: int}>}
     */
    public function dettaglio(Collection $quote): array
    {
        $importoVal = 0;

        $dettaglioQuote = $quote->map(function ($q) use (&$importoVal) {
            $immobile = $q->immobile;
            // `etichetta`: senza interno restava «Int.  (Posto auto 3)», con la parentesi orfana.
            // `etichettaEstesa`: qui prima si mostravano interno **e** nome, e applicare la sola
            // `etichetta` avrebbe tolto il nome a ogni installazione esistente (ripasso .58).
            $desc = $immobile ? $immobile->etichettaEstesa : "Unità";

            $componenteSpesa = $q->importo;
            $componenteSaldo = 0;
            $totaleCalcolato = $q->importo;

            // FIX: regole_calcolo è già un array grazie al cast nel modello
            $meta = is_string($q->regole_calcolo) ? json_decode($q->regole_calcolo, true) : $q->regole_calcolo;

            if (is_array($meta)) {
                $componenteSpesa = $meta['importi']['quota_pura_gestione'] ?? $meta['audit']['quota_pura'] ?? $q->importo;
                $componenteSaldo = $meta['importi']['saldo_usato'] ?? $meta['audit']['saldo_usato'] ?? 0;
                $totaleCalcolato = $meta['importi']['totale_calcolato'] ?? ($componenteSpesa + $componenteSaldo);
            }

            $importoVal += $totaleCalcolato;

            return [
                'descrizione' => $desc,
                'importo' => $totaleCalcolato,
                'componente_spesa' => $componenteSpesa,
                'componente_saldo' => $componenteSaldo,
            ];
        })->values()->toArray();

        return [(int) $importoVal, $dettaglioQuote];
    }

    /**
     * Dopo che le quote di alcune rate hanno cambiato intestatario (decisione 25), i promemoria delle persone
     * coinvolte si rimettono in pari con le quote, rata per rata:
     *
     * - chi non ha più quote su quella rata perde il promemoria;
     * - chi le ha e aveva già un promemoria lo ritrova con i nuovi importi (il pagato resta quello registrato);
     * - chi le ha e non lo aveva lo riceve, **solo se la rata ha già promemoria nel portale** — cioè il piano è stato
     *   approvato con il listener. Altrimenti li creerà l'approvazione, come per tutti.
     *
     * @param list<int> $rataIds
     * @param list<int> $anagraficaIds
     */
    public function seguonoLeQuote(array $rataIds, array $anagraficaIds, User $utente): void
    {
        $rate = Rata::with(['pianoRate.gestione', 'pianoRate.condominio', 'rateQuote.anagrafica', 'rateQuote.immobile'])->findMany($rataIds);
        $categoria = null;

        foreach ($rate as $rata) {
            $eventi = Evento::where('tipo', EventoTipo::SCADENZA_RATA_CONDOMINO->value)
                ->whereJsonContains('meta->context->rata_id', $rata->id)
                ->with('anagrafiche:id')
                ->get();
            if ($eventi->isEmpty()) {
                continue; // il piano non ha (ancora) promemoria nel portale: li crea l'approvazione
            }
            $piano = $rata->pianoRate;

            foreach ($anagraficaIds as $anagraficaId) {
                $quote = $rata->rateQuote->where('anagrafica_id', $anagraficaId)->where('stato', '!=', 'annullata')->values();
                $suo = $eventi->first(fn (Evento $e) => $e->anagrafiche->contains('id', $anagraficaId));

                if ($quote->isEmpty()) {
                    $suo?->delete();
                    continue;
                }

                if ($suo !== null) {
                    [$importo, $dettaglio] = $this->dettaglio($quote);
                    $meta = $suo->meta ?? [];
                    $pagato = (int) ($meta['importo_pagato'] ?? 0);
                    // Fase 1-bis R9: se credito e debito si scambiano, il testo va riscritto — «non è richiesto alcun
                    // pagamento» su una rata da pagare, o l'invito a pagare su un credito. Se il verso resta, il testo
                    // (magari ritoccato a mano) non si tocca.
                    if (((int) ($meta['importo_originale'] ?? 0) < 0) !== ($importo < 0) && $quote->first()->anagrafica !== null) {
                        $suo->description = $this->descrizione($quote->first()->anagrafica, $rata, $importo);
                    }
                    $meta['importo_originale'] = $importo;
                    $meta['importo_restante'] = $importo - $pagato;
                    $meta['dettaglio_quote'] = $dettaglio;
                    $meta['status'] = $importo <= 0 || $importo - $pagato <= 0 ? 'paid' : ($pagato > 0 ? 'partial' : 'pending');
                    $suo->meta = $meta;
                    $suo->save();
                    continue;
                }

                $anagrafica = $quote->first()->anagrafica;
                if ($anagrafica === null || $piano === null) {
                    continue;
                }
                $categoria ??= CategoriaEvento::firstOrCreate(['name' => CategoriaEventoEnum::SCADENZE_RATE_CONDOMINIALI->value], ['description' => 'Auto']);
                $this->crea($piano, $rata, $anagrafica, $quote, $piano->condominio, $utente->id, $piano->gestione->nome ?? 'Gestione', $categoria->id);
            }
        }

        InboxService::clearAdminCache();
    }

    /**
     * Le coppie (rata, persona) che una scrittura d'incasso ha **davvero** toccato: le quote pagate dalla scrittura e
     * dalle sue figlie (la compensazione del credito), a importo positivo — le quote di credito negative restano fuori.
     *
     * Dalla 1.11.0-beta.40 (Coda 167) un incasso tocca anche la quota di chi ha versato per un altro, che riceve la parte
     * in più: le coppie non si ricavano più dal pagante e dal payload. Coppie esatte, e non il prodotto rate × persone:
     * il prodotto ricalcolava promemoria di persone che l'incasso non aveva toccato, e sovrascriveva uno «reported»
     * segnalato dal portale (reperti R3 e S8 della Fase 1-bis della .40).
     *
     * @return Collection<int, array{0:int, 1:int}>
     */
    public function coppieToccate(\App\Models\Gestionale\ScritturaContabile $scrittura): Collection
    {
        $scrittura->loadMissing(['quotePagate', 'figlie.quotePagate']);

        return $scrittura->quotePagate
            ->merge($scrittura->figlie->flatMap->quotePagate)
            ->filter(fn ($q) => $q->importo > 0)
            ->map(fn ($q) => [(int) $q->rata_id, (int) $q->anagrafica_id])
            ->unique(fn ($c) => $c[0] . '-' . $c[1])
            ->values();
    }

    /**
     * Riallinea pagato, restante e stato dei promemoria delle coppie date, dalle quote come sono adesso. Lo usano
     * l'incasso e il suo storno: la stessa regola nei due versi.
     *
     * @param  Collection<int, array{0:int, 1:int}>  $coppie
     */
    public function allineaPagato(Collection $coppie): void
    {
        foreach ($coppie as [$rataId, $anagraficaId]) {
            $rata = Rata::with('rateQuote')->find($rataId);
            if (! $rata) {
                continue;
            }

            $quote = $rata->rateQuote->where('anagrafica_id', $anagraficaId)->where('importo', '>', 0);
            $dovuto = (int) $quote->sum('importo');
            $pagato = (int) $quote->sum('importo_pagato');
            $restante = $dovuto - $pagato;

            $eventi = Evento::where('meta->type', EventoTipo::SCADENZA_RATA_CONDOMINO->value)
                ->where(fn ($q) => $q->where('meta->context->rata_id', $rataId)->orWhere('meta->context->rata_id', (string) $rataId))
                ->whereHas('anagrafiche', fn ($q) => $q->where('anagrafica_id', $anagraficaId))
                ->get();

            foreach ($eventi as $evento) {
                $meta = $evento->meta;
                $meta['importo_pagato'] = $pagato;
                $meta['importo_restante'] = max(0, $restante);
                $meta['status'] = $restante <= 0 ? 'paid' : ($pagato > 0 ? 'partial' : 'pending');
                $evento->update(['meta' => $meta]);
            }
        }
    }

    /**
     * Una rata è saldata quando **ogni** sua quota a importo positivo è pagata — non quando la somma del pagato raggiunge
     * la somma del dovuto: lo strapagamento di uno compensava nella somma il debito di un altro, e la «verifica incassi»
     * si chiudeva mentre qualcuno doveva ancora (reperto S7 della Fase 1-bis della .40). Le quote di credito, negative,
     * non contano.
     */
    public function rataSaldata(Rata $rata): bool
    {
        return $rata->rateQuote()->where('importo', '>', 0)->get()
            ->every(fn ($q) => $q->importo_pagato >= $q->importo);
    }
}
