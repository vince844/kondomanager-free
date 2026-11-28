<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Enums\Assemblee\RuoloCollegamento;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\User;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\RegistroModifiche\FormatoDelValore;
use Illuminate\Support\Facades\DB;

/**
 * Coda 689, «Metti a rate questa spesa» (la scena 9 della bozza approvata con la decisione 623): dalla fattura al nuovo piano, **sulla scheda giusta**, con
 * tutto già scelto. Non dopo la registrazione: nel riquadro «Le rate» della pagina della fattura e nel menu della riga dell'elenco.
 *
 * - **Una riga «Fuori preventivo»** (o addebitata a una sola unità, o dalla Coda 742 su una voce che nel preventivo vale € 0, {@see VoceFuoriDalPreventivo}): la
 *   scheda «Spese fuori preventivo», con la fattura nel carrello; resta da mettere a rate la parte che i piani non hanno già collegato. Con le sole righe su
 *   voci a € 0 il riquadro lo dice: «La voce «Manutenzione tetto» non è nel preventivo: € 1.400,00 da mettere a rate». Una fattura mista (righe «Fuori
 *   preventivo» o di un'unità e una riga su una voce a € 0) mette a rate le prime, e il riquadro dice che la riga sulla voce resta al conguaglio del rendiconto.
 * - **Una voce a € 0 coperta dal fondo di riserva** non si mette a rate: il riquadro dice il fondo, senza pulsante.
 * - **Una spesa sulla voce**: la scheda «Dalle voci del preventivo», con la gestione e la voce scelte; resta fuori dai piani quello che la voce prevede e i piani
 *   della gestione non chiedono ancora (l'importo della voce meno i piani in bozza o approvati che la tengono).
 * - Con la delibera della gestione, quando la spesa ne ha una, per chi gestisce le assemblee.
 * - Con la fattura di partenza (`da_fattura`): il nuovo piano ha «Torna alla fattura» (21/11/2026).
 * - **Solo se resta qualcosa da mettere a rate**: altrimenti «Nei piani della voce» con «Apri il piano». Una nota di credito, una fattura stornata o senza
 *   gestione non si mette a rate.
 *
 * Tutto in centesimi interi: le cifre sono quelle già scritte (la voce, la pivot del piano, le righe della fattura); nessuna conversione qui.
 */
final class SpesaDaMettereARate
{
    /**
     * @return ?array{fuori: int, frase: string, url: ?string, scheda: string, piano: ?array{nome: string, url: string}}
     */
    public static function perLaFattura(FatturaPassiva $fattura, ?User $utente = null): ?array
    {
        if ($fattura->tipo_documento !== 'fattura' || (string) ($fattura->stato_pagamento?->value ?? $fattura->stato_pagamento) === 'stornata'
            || $fattura->esercizio_id === null) {
            return null;
        }
        $gestioneId = $fattura->gestioneDellaFattura();
        $gestione = $gestioneId === null ? null : Gestione::query()->where('condominio_id', $fattura->condominio_id)->find($gestioneId);
        if ($gestione === null) {
            return null;
        }
        $righe = DB::table('righe_fattura')->where('fattura_passiva_id', $fattura->id)->get(['id', 'conto_id', 'is_sopravvenienza', 'immobile_id', 'importo_imponibile', 'importo_iva']);
        if ($righe->isEmpty()) {
            return null;
        }
        $delibera = AccessoAlleAssemblee::gestisce($utente) ? CollegamentiDelleDelibere::delibera($gestione, RuoloCollegamento::DELIBERA->value) : null;
        $base = ['condominio' => $fattura->condominio_id, 'esercizio' => $fattura->esercizio_id, 'gestione_id' => $gestione->id];
        $euro = fn (int $c) => (string) FormatoDelValore::testo($c, 'euro');

        // Coda 742: le righe su una voce che nel preventivo vale € 0 (e che nessun piano chiede dalle voci) si mettono a rate come quelle «Fuori preventivo».
        $voceAZero = VoceFuoriDalPreventivo::idDellaFattura((int) $fattura->id);
        $fuoriPreventivo = $righe->filter(fn ($r) => (bool) $r->is_sopravvenienza || $r->immobile_id !== null || in_array((int) $r->id, $voceAZero, true));
        if ($fuoriPreventivo->isNotEmpty()) {
            $daRate = (int) $fuoriPreventivo->sum(fn ($r) => abs((int) $r->importo_imponibile + (int) $r->importo_iva));
            $collegato = (int) DB::table('piano_rate_fatture')->join('piani_rate', 'piani_rate.id', '=', 'piano_rate_fatture.piano_rate_id')
                ->where('piano_rate_fatture.fattura_passiva_id', $fattura->id)->whereIn('piani_rate.stato', ['bozza', 'approvato'])->sum('importo_collegato');
            $fuori = max(0, $daRate - $collegato);
            $piano = $fuori > 0 ? null : self::ultimoPiano(PianoRate::query()->whereHas('fattureStraordinarie', fn ($q) => $q->whereKey($fattura->id)));
            // Il riquadro dice il vero: con le sole righe su voci a € 0, «La voce «Manutenzione tetto» non è nel preventivo: € 1.400,00 da mettere a rate».
            $soloVociAZero = $fuoriPreventivo->every(fn ($r) => in_array((int) $r->id, $voceAZero, true));
            $nomi = $soloVociAZero ? Conto::query()->whereIn('id', $fuoriPreventivo->pluck('conto_id')->unique()->all())->orderBy('nome')->pluck('nome')->all() : [];
            // La fattura mista: la riga sulla voce a € 0 non entra nel piano delle fatture, e resta al conguaglio (`VoceFuoriDalPreventivo`).
            $restano = array_column(array_filter(VoceFuoriDalPreventivo::fuoriDalCarrello((int) $fattura->id), fn ($r) => $r['perche'] === 'mista'), 'voce');
            $coda = $restano === [] ? '' : '; ' . self::rigaAlConguaglio($restano);

            return ['fuori' => $fuori, 'scheda' => 'fuori_preventivo',
                'frase' => ($fuori === 0 ? ($restano === [] ? 'Nei piani: la fattura è già tutta in un piano rate' : 'Nei piani: le righe da mettere a rate sono già in un piano rate')
                    : ($nomi !== [] ? self::voceFuoriDalPreventivo($nomi) . ': ' . $euro($fuori) . ' da mettere a rate' : $euro($fuori) . ' ancora fuori dai piani')) . $coda,
                'url' => $fuori > 0 ? route('admin.gestionale.esercizi.piani-rate.create', $base + ['tipo' => 'straordinario', 'fatture' => [$fattura->id], 'da_fattura' => $fattura->id]
                    + ($delibera !== null ? ['delibera_id' => $delibera->id] : []), false) : null,
                'piano' => $piano];
        }

        $voci = $righe->pluck('conto_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $conti = Conto::query()->whereIn('id', $voci)->get()->keyBy('id');
        $fuori = 0;
        $voceDaProporre = null;
        foreach ($voci as $id) {
            $conto = $conti->get($id);
            if ($conto === null) {
                continue;
            }
            $residuo = self::fuoriDaiPianiDellaVoce($conto, (int) $gestione->id);
            if ($residuo > 0 && $voceDaProporre === null) {
                $voceDaProporre = $conto;
            }
            $fuori += $residuo;
        }
        $piano = $fuori > 0 ? null : self::ultimoPiano(PianoRate::query()->where('gestione_id', $gestione->id)
            ->whereHas('capitoli', fn ($q) => $q->whereIn('conti.id', $voci)));
        // Una voce che nel preventivo vale € 0 e che nessun piano chiede: la spesa non è «nei piani della voce», non è in nessun piano. Il riquadro lo dice, senza
        // pulsante: oggi il carrello dello straordinario non prende le righe su una voce a € 0 (rilievo C24, Coda 742).
        $aZero = $conti->first(fn (Conto $c) => (int) $c->importo === 0);
        // Coda 742 e tappa 6: la voce a € 0 coperta dal fondo di riserva non si mette a rate (il fondo la paga già): il riquadro dice il fondo.
        $dalFondo = collect(VoceFuoriDalPreventivo::fuoriDalCarrello((int) $fattura->id))->firstWhere('perche', 'fondo');
        if ($fuori === 0 && $dalFondo !== null && $piano === null) {
            // La cassa del fondo eliminata con le versioni di prima: nessun fondo paga la spesa, e la copertura si rifà registrando di nuovo la fattura.
            return ['fuori' => 0, 'scheda' => 'dalle_voci', 'frase' => 'La voce «' . $dalFondo['voce'] . '» non è nel preventivo: ' . ($dalFondo['sparito']
                ? 'la copertura è di un fondo che non c\'è più (la sua cassa è stata eliminata), e nessun fondo paga la spesa. Storna la fattura e registrala di nuovo, '
                    . 'con un fondo che c\'è o senza'
                : 'la spesa la copre ' . ($dalFondo['fondo'] !== null ? 'il fondo «' . $dalFondo['fondo'] . '»' : 'il fondo di riserva') . ', non si mette a rate'),
                'url' => null, 'piano' => null];
        }
        if ($fuori === 0 && $aZero !== null && $piano === null) {
            return ['fuori' => 0, 'scheda' => 'dalle_voci', 'frase' => 'La voce «' . $aZero->nome . '» non è nel preventivo: questa spesa non è in nessun piano rate',
                'url' => null, 'piano' => null];
        }

        return ['fuori' => $fuori, 'scheda' => 'dalle_voci',
            'frase' => $fuori > 0 ? $euro($fuori) . ' ancora fuori dai piani della voce' : 'Nei piani della voce',
            'url' => $voceDaProporre === null ? null : route('admin.gestionale.esercizi.piani-rate.create', $base + ['conto_id' => $voceDaProporre->id, 'da_fattura' => $fattura->id]
                + ($delibera !== null ? ['delibera_id' => $delibera->id] : []), false),
            'piano' => $piano];
    }

    /** «la riga sulla voce «Manutenzione tetto», che non è nel preventivo, resta al conguaglio del rendiconto». @param list<string> $nomi */
    private static function rigaAlConguaglio(array $nomi): string
    {
        $tra = array_map(fn ($n) => '«' . $n . '»', array_values(array_unique($nomi)));
        if (count($tra) === 1) {
            return 'la riga sulla voce ' . $tra[0] . ', che non è nel preventivo, resta al conguaglio del rendiconto';
        }
        $ultima = array_pop($tra);

        return 'le righe sulle voci ' . implode(', ', $tra) . ' e ' . $ultima . ', che non sono nel preventivo, restano al conguaglio del rendiconto';
    }

    /** «La voce «Manutenzione tetto» non è nel preventivo», «Le voci «A» e «B» non sono nel preventivo». @param list<string> $nomi */
    private static function voceFuoriDalPreventivo(array $nomi): string
    {
        $tra = array_map(fn ($n) => '«' . $n . '»', $nomi);
        if (count($tra) === 1) {
            return 'La voce ' . $tra[0] . ' non è nel preventivo';
        }
        $ultima = array_pop($tra);

        return 'Le voci ' . implode(', ', $tra) . ' e ' . $ultima . ' non sono nel preventivo';
    }

    /**
     * Quanto la voce prevede e i piani della gestione non chiedono ancora: l'importo della voce (il valore assoluto, come lo conta il preventivo) meno ciò che
     * le righe dei piani in bozza o approvati tengono (la riga vuota vale l'importo della voce). Mai sotto zero.
     */
    public static function fuoriDaiPianiDellaVoce(Conto $voce, int $gestioneId): int
    {
        $previsto = abs((int) $voce->importo);
        $chiesto = 0;
        $righe = DB::table('piano_rate_capitoli')->join('piani_rate', 'piani_rate.id', '=', 'piano_rate_capitoli.piano_rate_id')
            ->where('piani_rate.gestione_id', $gestioneId)->whereIn('piani_rate.stato', ['bozza', 'approvato'])
            ->where('piano_rate_capitoli.conto_id', $voce->id)->pluck('piano_rate_capitoli.importo');
        foreach ($righe as $importo) {
            $chiesto += $importo === null ? $previsto : abs((int) $importo);
        }

        return max(0, $previsto - $chiesto);
    }

    /** L'ultimo piano che tiene già la spesa, per «Apri il piano». @param \Illuminate\Database\Eloquent\Builder<PianoRate> $query */
    private static function ultimoPiano($query): ?array
    {
        $piano = $query->whereIn('stato', ['bozza', 'approvato'])->orderByDesc('id')->first();
        if ($piano === null || $piano->esercizio_id === null) {
            return null;
        }

        return ['nome' => (string) $piano->nome, 'url' => route('admin.gestionale.esercizi.piani-rate.show', ['condominio' => $piano->condominio_id,
            'esercizio' => $piano->esercizio_id, 'pianoRate' => $piano->id], false)];
    }
}
