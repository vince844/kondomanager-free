<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Models\Gestionale\FatturaPassiva;
use Illuminate\Support\Facades\DB;

/**
 * Il carrello della scheda «Spese fuori preventivo» del nuovo piano rate, e ciò che non mostra.
 *
 * - **Il carrello** (prima in `FetchFattureStraordinarieController`): le fatture con una riga «Fuori preventivo», addebitata a una sola unità o (Coda 742) su
 *   una voce che nel preventivo vale € 0 ({@see VoceFuoriDalPreventivo}), e le pregresse
 *   con una copertura per sopravvenienza, con quanto resta da mettere a rate (al netto delle note collegate e di quello che altri piani ne chiedono già).
 *   **Coda 686 (d), D198 (c):** solo le fatture della gestione scelta. Fino alla 1.12.0-beta.11 il carrello diceva «questa gestione» e offriva le fatture di
 *   tutto l'esercizio; la gestione è quella della fattura (Coda 687: la colonna, o la scrittura di competenza, o le voci: `FatturaPassiva::dellaGestione`).
 * - **Ciò che non mostra** (Coda 686 (a), decisioni 506 e 510): le fatture della gestione su una voce del piano dei conti (si mettono a rate con «Dalle voci
 *   del preventivo»), quelle «Fuori preventivo» registrate in un altro esercizio (il carrello è per esercizio: la prova B2), e quelle già per intero in un
 *   altro piano, con il nome del piano (B3). Un numero fisso di letture, qualunque sia il numero delle fatture.
 */
final class CarrelloDelloStraordinario
{
    /** Quante fatture si nominano una per una in ogni elenco di ciò che non si vede: le altre si contano. */
    public const QUANTE_SI_NOMINANO = 3;

    /**
     * Le fatture che il carrello può offrire, con i numeri in centesimi. Con `$esercizioId` nullo, di tutti gli esercizi della gestione.
     *
     * @return list<array{id: int, esercizio_id: int, fornitore: string, numero_documento: ?string, data_documento: string, competenza_dal: ?string,
     *   competenza_al: ?string, is_pregresso: bool, totale: int, netto: int, gia: int, residuo: int, note: list<array<string, mixed>>}>
     */
    public function righe(int $condominioId, int $gestioneId, ?int $esercizioId, ?int $pianoCorrente = null): array
    {
        // 1a. Fatture correnti con righe «Fuori preventivo» o di un'unità.
        $correnti = DB::table('fatture_passive')
            ->join('fornitori', 'fatture_passive.fornitore_id', '=', 'fornitori.id')
            ->join('righe_fattura', 'fatture_passive.id', '=', 'righe_fattura.fattura_passiva_id')
            ->where('fatture_passive.condominio_id', $condominioId)
            ->when($esercizioId !== null, fn ($q) => $q->where('fatture_passive.esercizio_id', $esercizioId))
            ->where('fatture_passive.is_pregresso', false)
            // Coda 165 (1.11.0-beta.36): solo fatture. Una nota di credito con una riga ad personam positiva (la «riga in diminuzione» di una nota che
            // rettifica una fattura con uno sconto) entrava come fabbisogno.
            ->where('fatture_passive.tipo_documento', 'fattura')
            ->where('fatture_passive.stato_approvazione', '!=', 'contestata')
            // Una fattura stornata non è più un fabbisogno da finanziare: dopo lo storno di una pregressa da € 610,00 il carrello la offriva ancora, e il
            // piano addebitava ai proprietari un documento annullato (beta.22). Il filtro sta su entrambe le query.
            ->where('fatture_passive.stato_pagamento', '!=', 'stornata')
            // Coda 742: anche le righe su una voce che nel preventivo vale € 0 e che nessun piano chiede dalle voci (`VoceFuoriDalPreventivo`).
            ->where(fn ($q) => $q->where('righe_fattura.is_sopravvenienza', true)->orWhereNotNull('righe_fattura.immobile_id')
                ->orWhere(fn ($z) => VoceFuoriDalPreventivo::righe($z)))
            ->where(fn ($q) => FatturaPassiva::dellaGestione($q, $gestioneId))
            ->select(
                'fatture_passive.id', 'fatture_passive.esercizio_id', 'fatture_passive.numero_documento', 'fatture_passive.data_documento',
                // B2, S6 (verifica R5): il carrello dice se la fattura ha la competenza dichiarata.
                'fatture_passive.competenza_dal', 'fatture_passive.competenza_al', 'fornitori.ragione_sociale as fornitore',
                DB::raw('SUM(righe_fattura.importo_imponibile + righe_fattura.importo_iva) as totale_straordinario')
            )
            ->groupBy('fatture_passive.id', 'fatture_passive.esercizio_id', 'fatture_passive.numero_documento', 'fatture_passive.data_documento',
                'fatture_passive.competenza_dal', 'fatture_passive.competenza_al', 'fornitori.ragione_sociale')
            ->get();

        // 1b. Fatture pregresse con una copertura per sopravvenienza.
        $pregresse = DB::table('fatture_passive')
            ->join('fornitori', 'fatture_passive.fornitore_id', '=', 'fornitori.id')
            ->join('fattura_coperture', 'fatture_passive.id', '=', 'fattura_coperture.fattura_passiva_id')
            ->where('fatture_passive.condominio_id', $condominioId)
            ->when($esercizioId !== null, fn ($q) => $q->where('fatture_passive.esercizio_id', $esercizioId))
            ->where('fatture_passive.is_pregresso', true)
            ->where('fatture_passive.tipo_documento', 'fattura')
            ->where('fatture_passive.stato_approvazione', '!=', 'contestata')
            ->where('fatture_passive.stato_pagamento', '!=', 'stornata')
            ->where('fattura_coperture.tipo_copertura', 'sopravvenienza')
            // Solo la parte con la voce: quella senza voce (un dato delle versioni di prima) il motore non la sa ripartire, e la generazione lo dice.
            ->whereNotNull('fattura_coperture.conto_id')
            ->where(fn ($q) => FatturaPassiva::dellaGestione($q, $gestioneId))
            ->select(
                'fatture_passive.id', 'fatture_passive.esercizio_id', 'fatture_passive.numero_documento', 'fatture_passive.data_documento',
                'fatture_passive.competenza_dal', 'fatture_passive.competenza_al', 'fornitori.ragione_sociale as fornitore',
                DB::raw('SUM(fattura_coperture.importo) as totale_straordinario')
            )
            ->groupBy('fatture_passive.id', 'fatture_passive.esercizio_id', 'fatture_passive.numero_documento', 'fatture_passive.data_documento',
                'fatture_passive.competenza_dal', 'fatture_passive.competenza_al', 'fornitori.ragione_sociale')
            ->get();

        // Decisione 26 (1.11.0-beta.35): ogni voce sa se è una pregressa. Un ciclo e non `each(fn …)`: la freccia restituisce il valore assegnato, e
        // `Collection::each()` si ferma al primo `false` (trovato dopo il commit della beta.35).
        $tutte = [];
        foreach ($correnti as $f) {
            $tutte[] = [$f, false];
        }
        foreach ($pregresse as $f) {
            $tutte[] = [$f, true];
        }
        $ids = array_map(fn ($x) => (int) $x[0]->id, $tutte);
        if ($ids === []) {
            return [];
        }

        // Quanto ne chiedono già gli altri piani (in bozza o approvati).
        $finanziamenti = DB::table('piano_rate_fatture')
            ->join('piani_rate', 'piano_rate_fatture.piano_rate_id', '=', 'piani_rate.id')
            ->whereIn('fattura_passiva_id', $ids)
            ->whereIn('piani_rate.stato', ['bozza', 'approvato'])
            ->when($pianoCorrente, fn ($q) => $q->where('piani_rate.id', '!=', $pianoCorrente))
            ->select('fattura_passiva_id', DB::raw('SUM(importo_collegato) as gia_finanziato'))
            ->groupBy('fattura_passiva_id')
            ->pluck('gia_finanziato', 'fattura_passiva_id')
            ->toArray();

        // Coda 165 (1.11.0-beta.36): la fattura rettificata da una nota collegata si offre al netto, e per lei quanto chiedono gli altri piani lo legge la
        // stessa funzione della creazione del piano (`chiestoDaiPiani`): la somma dei pivot contava zero un piano «tutto» (V4 della verifica).
        $netti = NettoNoteCollegate::perFatture($ids);
        $giaPerNetti = [];
        if ($netti !== []) {
            $modelli = FatturaPassiva::with([
                'pianiRate' => fn ($q) => $pianoCorrente ? $q->where('piani_rate.id', '!=', $pianoCorrente) : $q,
                'righe', 'coperture', 'noteCollegate',
            ])->whereIn('id', array_keys($netti))->get();
            foreach ($modelli as $m) {
                $giaPerNetti[$m->id] = $m->chiestoDaiPiani();
            }
        }

        $avvisi = $this->avvisiDiSostituzione($condominioId, $ids);
        $risultato = [];
        foreach ($tutte as [$f, $pregressa]) {
            $totale = (int) $f->totale_straordinario;
            $netto = isset($netti[$f->id]) ? $netti[$f->id]['netto'] : $totale;
            $gia = (int) ($giaPerNetti[$f->id] ?? $finanziamenti[$f->id] ?? 0);
            $risultato[] = [
                'id' => (int) $f->id,
                'esercizio_id' => (int) $f->esercizio_id,
                'fornitore' => (string) $f->fornitore,
                'numero_documento' => $f->numero_documento,
                'data_documento' => (string) $f->data_documento,
                'competenza_dal' => $f->competenza_dal,
                'competenza_al' => $f->competenza_al,
                'is_pregresso' => $pregressa,
                'totale' => $totale,
                'netto' => $netto,
                'gia' => $gia,
                'residuo' => max(0, $netto - $gia),
                'note' => $netti[$f->id]['note'] ?? [],
                'avviso_sostituzione' => $avvisi[(int) $f->id] ?? null,
            ];
        }

        return $risultato;
    }

    /**
     * Coda 159: la fattura annullata (stornata, o rettificata da una nota di credito collegata) che un piano straordinario che ha già incassato chiede ancora,
     * e una fattura nuova dello stesso fornitore nel carrello. Il programma non sa se la nuova sostituisce quella annullata: messa a rate per intero, i
     * condòmini pagherebbero due volte la stessa spesa. L'avviso lo dice sulla fattura nuova, con il piano e il numero; decide l'amministratore.
     *
     * @param  list<int>  $ids  le fatture del carrello
     * @return array<int, string>  fattura del carrello => avviso
     */
    private function avvisiDiSostituzione(int $condominioId, array $ids): array
    {
        $fornitori = DB::table('fatture_passive')->whereIn('id', $ids)->pluck('fornitore_id', 'id')->map(fn ($id) => (int) $id)->all();
        if ($fornitori === []) {
            return [];
        }
        $annullate = FatturaPassiva::query()->with('pianiRate')->where('condominio_id', $condominioId)->whereIn('fornitore_id', array_values(array_unique($fornitori)))
            ->where('tipo_documento', 'fattura')
            ->where(fn ($q) => $q->where('stato_pagamento', 'stornata')->orWhereHas('noteCollegate'))
            ->whereHas('pianiRate', fn ($q) => $q->where('piani_rate.tipo', 'straordinario'))
            ->orderBy('id')->get();
        $perFornitore = [];
        foreach ($annullate as $a) {
            $incassato = $a->pianoAlGrado('incassato');
            if ($incassato === null) {
                continue;
            }
            $come = (is_object($a->stato_pagamento) ? $a->stato_pagamento->value : $a->stato_pagamento) === 'stornata' ? 'annullata' : 'rettificata da una nota di credito';
            $perFornitore[(int) $a->fornitore_id][] = ['id' => (int) $a->id, 'frase' => 'Il piano «' . $incassato['piano']->nome . '» chiede ancora la fattura n. '
                . ($a->numero_documento ?? 'S/N') . ', ' . $come . ': se questa la sostituisce, mettila a rate per la sola differenza, o correggi prima quel piano.'];
        }
        $esito = [];
        foreach ($fornitori as $fatturaId => $fornitoreId) {
            $prima = collect($perFornitore[$fornitoreId] ?? [])->first(fn (array $x) => $x['id'] !== (int) $fatturaId);
            if ($prima !== null) {
                $esito[(int) $fatturaId] = $prima['frase'];
            }
        }

        return $esito;
    }

    /**
     * Coda 686 (a): ciò che il carrello vuoto non mostra, per l'esercizio del piano. Gli importi in euro, come il carrello (la pagina li legge così).
     *
     * @return array{gestione: string, sulle_voci: array{totale: int, fatture: list<array<string, mixed>>}, altri_esercizi: list<array{esercizio_id: int,
     *   esercizio: string, quante: int}>, gia_nei_piani: array{totale: int, fatture: list<array<string, mixed>>}}
     */
    public function fuoriDalCarrello(int $condominioId, int $gestioneId, int $esercizioId): array
    {
        $tutte = $this->righe($condominioId, $gestioneId, null);

        // Le «Fuori preventivo» della gestione in un altro esercizio, con qualcosa ancora da mettere a rate (B2).
        $perEsercizio = [];
        foreach ($tutte as $r) {
            if ($r['esercizio_id'] !== $esercizioId && $r['residuo'] > 0) {
                $perEsercizio[$r['esercizio_id']] = ($perEsercizio[$r['esercizio_id']] ?? 0) + 1;
            }
        }
        $nomiEsercizi = $perEsercizio === [] ? collect() : DB::table('esercizi')->whereIn('id', array_keys($perEsercizio))->orderBy('data_inizio')->pluck('nome', 'id');
        $altriEsercizi = [];
        foreach ($nomiEsercizi as $id => $nome) {
            $altriEsercizi[] = ['esercizio_id' => (int) $id, 'esercizio' => (string) $nome, 'quante' => $perEsercizio[$id]];
        }

        // Quelle di questo esercizio già per intero in un altro piano (B3), con il nome dei piani.
        $piene = array_values(array_filter($tutte, fn ($r) => $r['esercizio_id'] === $esercizioId && $r['residuo'] === 0 && $r['gia'] > 0));
        $nominate = array_slice($piene, 0, self::QUANTE_SI_NOMINANO);
        $piani = $nominate === [] ? collect() : DB::table('piano_rate_fatture')->join('piani_rate', 'piani_rate.id', '=', 'piano_rate_fatture.piano_rate_id')
            ->whereIn('piano_rate_fatture.fattura_passiva_id', array_column($nominate, 'id'))->whereIn('piani_rate.stato', ['bozza', 'approvato'])
            ->orderBy('piani_rate.id')->get(['piano_rate_fatture.fattura_passiva_id', 'piani_rate.nome'])->groupBy('fattura_passiva_id');

        // Le fatture della gestione su una voce del suo piano dei conti: le righe comuni, non tecniche, non «Fuori preventivo», di questo esercizio.
        $sulleVoci = DB::table('righe_fattura')
            ->join('fatture_passive', 'fatture_passive.id', '=', 'righe_fattura.fattura_passiva_id')
            ->join('fornitori', 'fornitori.id', '=', 'fatture_passive.fornitore_id')
            ->join('conti', 'conti.id', '=', 'righe_fattura.conto_id')
            ->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')
            ->where('fatture_passive.condominio_id', $condominioId)
            ->where('fatture_passive.esercizio_id', $esercizioId)
            ->where('fatture_passive.is_pregresso', false)
            ->where('fatture_passive.tipo_documento', 'fattura')
            ->where('fatture_passive.stato_approvazione', '!=', 'contestata')
            ->where('fatture_passive.stato_pagamento', '!=', 'stornata')
            ->where('piani_conti.gestione_id', $gestioneId)
            ->where(fn ($q) => $q->where('conti.is_tecnico', false)->orWhereNull('conti.is_tecnico'))
            ->where(fn ($q) => $q->where('righe_fattura.is_sopravvenienza', false)->orWhereNull('righe_fattura.is_sopravvenienza'))
            ->whereNull('righe_fattura.immobile_id')
            // Coda 742: la riga su una voce a € 0 sta nel carrello, non fra quelle «sulle voci».
            ->where(fn ($q) => VoceFuoriDalPreventivo::nonRighe($q))
            ->groupBy('fatture_passive.id', 'fatture_passive.numero_documento', 'fatture_passive.data_documento', 'fornitori.ragione_sociale')
            ->orderByDesc('fatture_passive.data_documento')->orderByDesc('fatture_passive.id')
            ->get([
                'fatture_passive.id', 'fatture_passive.numero_documento', 'fatture_passive.data_documento', 'fornitori.ragione_sociale as fornitore',
                DB::raw('SUM(righe_fattura.importo_imponibile + righe_fattura.importo_iva) as importo'),
                DB::raw('MIN(conti.id) as conto_id'), DB::raw('COUNT(DISTINCT conti.id) as voci'),
            ]);
        $nomiVoci = $sulleVoci->isEmpty() ? collect()
            : DB::table('conti')->whereIn('id', $sulleVoci->take(self::QUANTE_SI_NOMINANO)->pluck('conto_id')->all())->pluck('nome', 'id');

        return [
            'gestione' => (string) DB::table('gestioni')->where('id', $gestioneId)->value('nome'),
            'sulle_voci' => [
                'totale' => $sulleVoci->count(),
                'fatture' => $sulleVoci->take(self::QUANTE_SI_NOMINANO)->map(fn ($f) => [
                    'id' => (int) $f->id,
                    'fornitore' => (string) $f->fornitore,
                    'numero_documento' => $f->numero_documento ?? 'S/N',
                    'importo' => \App\Helpers\MoneyHelper::fromCents((int) $f->importo),
                    'voce' => (string) ($nomiVoci[$f->conto_id] ?? ''),
                    'altre_voci' => max(0, (int) $f->voci - 1),
                    'conto_id' => (int) $f->conto_id,
                ])->values()->all(),
            ],
            'altri_esercizi' => $altriEsercizi,
            'gia_nei_piani' => [
                'totale' => count($piene),
                'fatture' => array_map(fn ($r) => [
                    'id' => $r['id'],
                    'fornitore' => $r['fornitore'],
                    'numero_documento' => $r['numero_documento'] ?? 'S/N',
                    'piani' => ($piani[$r['id']] ?? collect())->pluck('nome')->unique()->values()->all(),
                ], $nominate),
            ],
        ];
    }
}
