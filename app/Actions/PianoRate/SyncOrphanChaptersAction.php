<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Actions\PianoRate;

use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Conto;

class SyncOrphanChaptersAction
{
    /**
     * Sincronizza capitoli orfani specifici al piano rate.
     * Garantisce che i capitoli non siano già impegnati altrove
     * e congela il loro importo attuale (Snapshot) nella pivot.
     *
     * ARCHITETTURA — "Snapshot Puro":
     * L'importo viene calcolato al momento della sincronizzazione e salvato nella
     * pivot piano_rate_capitoli. Da quel momento è immutabile: anche se il preventivo
     * in conti.importo viene modificato in seguito, il piano rate mantiene il dato
     * storicizzato. Questo è fondamentale per la coerenza dei bilanci consuntivi
     * e per il futuro modulo di Chiusura Esercizio.
     *
     * @param PianoRate $pianoRate
     * @param array $specificIds Array di IDs di capitoli da sincronizzare (opzionale)
     * @return int Numero di capitoli sincronizzati
     */
    public function execute(PianoRate $pianoRate, array $specificIds = []): int
    {
        // 1. Carichiamo i conti orfani CON i loro sottoconti (Eager Loading profondo)
        //    Essenziale per calcolare la somma ricorsiva senza query N+1.
        //    2 livelli (sottoconti.sottoconti) coprono tutta la gerarchia supportata.
        //    Filtriamo anche i conti tecnici (is_tecnico=true) che non devono
        //    comparire nel piano rate ordinario (stessa regola dello scope visibili()).
        $query = Conto::with(['sottoconti' => fn($q) => $q->where('is_tecnico', false)
                                                           ->with(['sottoconti' => fn($q2) => $q2->where('is_tecnico', false)])])
            ->where('piano_conto_id', function($q) use ($pianoRate) {
                $q->select('id')
                  ->from('piani_conti')
                  ->where('gestione_id', $pianoRate->gestione_id);
            })
            ->whereNull('parent_id')
            ->where('is_tecnico', false) // Esclude conti tecnici generati on-the-fly
            ->whereDoesntHave('pianiRate', function($q) {
                $q->where('piani_rate.attivo', true);
            });

        if (!empty($specificIds)) {
            $query->whereIn('id', $specificIds);
        }

        $contiOrfani = $query->get();

        if ($contiOrfani->isEmpty()) {
            return 0;
        }

        // Un conto con una voce a € 0 sotto di sé (o la voce stessa) che un piano delle fatture mette già a rate non prende una cifra qui: la stessa spesa
        // si chiederebbe due volte. Ci si ferma con la frase di «Dalle voci», e il ricalcolo non scrive niente.
        foreach ($contiOrfani as $conto) {
            $piani = \App\Services\Gestionale\VoceFuoriDalPreventivo::pianiDelleFattureSulConto((int) $conto->id);
            if ($piani !== []) {
                throw new \DomainException(\App\Services\Gestionale\VoceFuoriDalPreventivo::fraseDalleVoci((string) $conto->nome, $piani[0]));
            }
        }

        // 2. Prepariamo l'array per il Sync con gli importi congelati (Snapshot Architetturale)
        $syncData = [];
        foreach ($contiOrfani as $conto) {
            $syncData[$conto->id] = [
                'importo' => $this->calcolaImportoSnapshot($conto, $pianoRate->created_at),
                'note'    => 'Snapshot sincronizzazione orfani',
            ];
        }

        // 3. Inserimento sicuro nella pivot con importo valorizzato e congelato
        $pianoRate->capitoli()->syncWithoutDetaching($syncData);

        // 4. Il tetto sulle voci appena aggiunte, dopo la scrittura e nella transazione del ricalcolo: un capitolo libero può avere sotto una sottovoce che
        // un altro piano dello stesso esercizio chiede già per intero. Il rifiuto annulla la scrittura (`PianoRateGenerationController`).
        $this->conIlTettoSulleVoci($pianoRate, array_map('intval', array_keys($syncData)));

        return $contiOrfani->count();
    }

    /**
     * Le voci a cui il piano arriva dalle righe appena aggiunte non vanno oltre il loro tetto (`TettoSulleVoci::primoSforo`, solo da quelle righe: le voci
     * che il piano aveva già non cambiano).
     *
     * @param  list<int>  $righe
     *
     * @throws \DomainException con la frase per la pagina
     */
    private function conIlTettoSulleVoci(PianoRate $pianoRate, array $righe): void
    {
        $esercizio = app(\App\Services\Riparto\CompetenzaDelPiano::class)->esercizioDelPiano($pianoRate);
        if ($esercizio === null) {
            return;
        }
        $sforo = app(\App\Services\Gestionale\TettoSulleVoci::class)->primoSforo($pianoRate, $esercizio, $righe);
        if ($sforo === null) {
            return;
        }
        $euro = fn (int $c) => \App\Services\Gestionale\TettoSulleVoci::euro($c);
        $inizio = \App\Services\Gestionale\TettoSulleVoci::giaChiesta($sforo) . ': restano ' . $euro($sforo['residuo']) . ' da chiedere, e ';
        throw new \DomainException($inizio . ($sforo['capitolo'] !== null
            ? 'il capitolo «' . $sforo['capitolo'] . '», aggiunto con il ricalcolo, gliene passerebbe ' . $euro($sforo['chiede'])
                . '. Il ricalcolo non ha cambiato il piano: per le altre sottovoci del capitolo crea un piano che le sceglie una per una, ognuna con al massimo quello che resta.'
            : 'il ricalcolo la aggiungerebbe a questo piano con ' . $euro($sforo['chiede'])
                . '. Il ricalcolo non ha cambiato il piano: chiesta di nuovo, i condòmini la pagherebbero due volte.'));
    }

    /**
     * Calcola ricorsivamente l'importo totale del conto per lo snapshot.
     * - Mastro (ha figli non tecnici): somma ricorsiva degli importi dei figli.
     * - Foglia (nessun figlio): restituisce il proprio importo.
     *
     * Coda 277 (1.12.0-beta.14): i figli sono quelli che il motore guarda per il piano, i nati fino alla sua creazione o, se nessuno lo è, tutti
     * (`Conto::sottocontiDelPiano`, come `CalcoloQuoteService::ripartoFraIFigli`). Il motore divide la copia del capitolo fra quei figli in proporzione ai
     * loro importi e dà a ognuno il segno del suo tipo (`Conto::importoConSegno`): con la somma dei loro importi ognuno riceve il suo, una spesa come spesa
     * e un'entrata come entrata. Fino alla beta.13 la somma era dei figli di adesso: un'entrata nata dopo il piano entrava nella copia e il motore la
     * dava tutta ai figli del piano, come spesa (€ 600,00 a «Manutenzione antenna» invece di € 100,00, il piano a € 1.800,00 invece di € 1.300,00).
     */
    private function calcolaImportoSnapshot(Conto $conto, ?\Carbon\CarbonInterface $pianoNato): int
    {
        $figli = $conto->sottoconti ?? collect();

        if ($figli->isNotEmpty()) {
            $totale = 0;
            foreach ($conto->sottocontiDelPiano($pianoNato) as $sottoconto) {
                $totale += $this->calcolaImportoSnapshot($sottoconto, $pianoNato);
            }
            return $totale;
        }

        return (int) $conto->importo;
    }
}