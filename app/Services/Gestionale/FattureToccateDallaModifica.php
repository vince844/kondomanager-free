<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Services\RegistroModifiche\Lotto;
use App\Services\RegistroModifiche\Gesto;
use App\Services\RegistroModifiche\RegistroModifiche;
use App\Helpers\MoneyHelper;
use App\Models\Gestionale\FatturaPassiva;
use Illuminate\Support\Facades\DB;

/**
 * Coda 687 (decisione 507): le fatture che una modifica, fino alla 1.12.0-beta.11, ha già toccato. Due difetti, presenti dalla 1.11.0:
 *
 * - **spostate** (prova B4): la pagina di modifica partiva dall'ordinaria e il servizio riscriveva la scrittura con quella. La fattura resta con le righe sulle
 *   voci di una gestione e la scrittura di competenza su un'altra. Si riconoscono così: tutte le voci delle righe in una gestione, la scrittura in un'altra.
 *   Lo stesso stato nasce anche registrando una fattura con la gestione di serie e una voce di un'altra gestione, che il modulo offriva: si elenca uguale.
 * - **spogliate di «Fuori preventivo»** (prova B1): la modifica riscriveva le righe con il segno spento. La riga resta sulla voce tecnica creata alla
 *   registrazione, sotto «Integrazioni Straordinarie (Scudo Legale)», che vale € 0,00: non si mette più a rate da nessuna delle due schede.
 *
 * **Le note di credito** (Fase 1-bis della beta.12, D4): la «Modifica» spostava anche le note, e una nota registrata con la gestione di serie e una voce di
 * un'altra è nello stesso stato. Il controllo le legge, e **una fattura si corregge con le sue note (`fattura_rettificata_id`) o non si corregge**: spostare
 * la sola fattura lascerebbe la nota sull'altra gestione (− € 244,00 di costo dove la voce non c'è, € 244,00 in più dove c'è). Se una nota non si può
 * spostare (compensata, esercizio chiuso, in un piano, righe su un'altra gestione) la fattura resta com'è, con il motivo. Una nota spostata la cui fattura
 * è già sulla gestione giusta, o che non rettifica nessuna fattura, si elenca da sola. Le note dello storno restano fuori: sono la fattura annullata,
 * sulla sua gestione, e una stornata non si corregge.
 *
 * **Sola lettura di serie.** La correzione proposta la fa solo l'amministratore (il pulsante nell'elenco delle fatture, o il comando
 * `kondomanager:verifica-gestione-fatture --correggi`), mai la migrazione né l'apertura di una pagina:
 * - per una spostata, la scrittura di competenza (e la gestione della fattura) passano alla gestione delle voci. Solo se la fattura non ha pagamenti, non è in
 *   un piano rate e il suo esercizio è aperto: con un pagamento anche la sua scrittura sta sull'altra gestione, e il rimedio è lo storno (lo dice il motivo);
 * - per una spogliata, il segno «Fuori preventivo» torna sulla riga. Nessuna scrittura cambia: la riga è già sulla voce tecnica, e il segno dice solo al
 *   carrello del piano rate che la fattura si mette a rate con «Spese fuori preventivo». Anche pagata (il fornitore si paga prima, i condòmini rimborsano
 *   con il piano: è il caso di tutti i giorni). Non in un esercizio chiuso (Fase 1-bis della beta.12, S2): lì le righe non si cambiano più, e il carrello,
 *   che è per esercizio, non la offrirebbe in un piano di un esercizio aperto.
 */
final class FattureToccateDallaModifica
{
    public const CAPITOLO_TECNICO = 'Integrazioni Straordinarie (Scudo Legale)';

    /**
     * @return array{spostate: list<array<string, mixed>>, spogliate: list<array<string, mixed>>}
     */
    public function perCondominio(int $condominioId, ?array $soloFatture = null): array
    {
        return ['spostate' => $this->spostate($condominioId, $soloFatture), 'spogliate' => $this->spogliate($condominioId, $soloFatture)];
    }

    /** @return list<array<string, mixed>> */
    private function spostate(int $condominioId, ?array $soloFatture): array
    {
        // Le righe con una voce, con la gestione della voce e quella della scrittura di competenza: una lettura. Le fatture e le note di credito (D4).
        $righe = DB::table('righe_fattura as r')
            ->join('fatture_passive as fp', 'fp.id', '=', 'r.fattura_passiva_id')
            ->join('fornitori as fo', 'fo.id', '=', 'fp.fornitore_id')
            ->join('conti as c', 'c.id', '=', 'r.conto_id')
            ->join('piani_conti as pc', 'pc.id', '=', 'c.piano_conto_id')
            ->join('fattura_scrittura as fs', fn ($j) => $j->on('fs.fattura_passiva_id', '=', 'fp.id')->where('fs.tipo', '=', 'competenza'))
            ->join('scritture_contabili as sc', 'sc.id', '=', 'fs.scrittura_contabile_id')
            ->leftJoin('esercizi as e', 'e.id', '=', 'fp.esercizio_id')
            ->where('fp.condominio_id', $condominioId)
            ->whereIn('fp.tipo_documento', ['fattura', 'nota_credito'])
            ->where('fp.stato_pagamento', '!=', 'stornata')
            ->whereNull('sc.deleted_at')
            ->whereNotNull('sc.gestione_id')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('righe_fattura as r2')->join('conti as c2', 'c2.id', '=', 'r2.conto_id')
                ->join('piani_conti as pc2', 'pc2.id', '=', 'c2.piano_conto_id')->whereColumn('r2.fattura_passiva_id', 'fp.id')
                ->whereColumn('pc2.gestione_id', '!=', 'sc.gestione_id'))
            ->orderBy('fp.data_documento')->orderBy('fp.id')
            ->get(['fp.id', 'fp.tipo_documento', 'fp.fattura_rettificata_id', 'fp.numero_documento', 'fp.data_documento', 'fp.totale_documento', 'fp.stato_pagamento',
                'fo.ragione_sociale as fornitore', 'e.nome as esercizio', 'e.stato as stato_esercizio', 'sc.id as scrittura_id', 'sc.gestione_id as gestione_scrittura',
                'pc.gestione_id as gestione_voce', 'c.nome as voce']);

        // Le note dello storno: sono la fattura annullata, sulla sua gestione, e si muovono solo con lei; una stornata non si corregge.
        $noteDelloStorno = DB::table('fatture_passive')->where('condominio_id', $condominioId)->where('stato_pagamento', 'stornata')->pluck('dati_extra')
            ->map(fn ($x) => (int) ((is_string($x) ? json_decode($x, true) : (array) $x)['stornata_da_id'] ?? 0))->filter()->all();
        $righe = $righe->reject(fn ($x) => in_array((int) $x->id, $noteDelloStorno, true))->values();
        if ($righe->isEmpty()) {
            return [];
        }

        $ids = $righe->pluck('id')->unique()->values()->all();
        // Le note che rettificano le fatture elencate (spostate anche loro o no), con la gestione della loro scrittura di competenza.
        $fattureIds = $righe->where('tipo_documento', 'fattura')->pluck('id')->unique()->values()->all();
        $noteDelleFatture = $fattureIds === [] ? collect() : DB::table('fatture_passive as n')
            ->leftJoin('fattura_scrittura as fs', fn ($j) => $j->on('fs.fattura_passiva_id', '=', 'n.id')->where('fs.tipo', '=', 'competenza'))
            ->leftJoin('scritture_contabili as sc', fn ($j) => $j->on('sc.id', '=', 'fs.scrittura_contabile_id')->whereNull('sc.deleted_at'))
            ->where('n.condominio_id', $condominioId)->where('n.tipo_documento', 'nota_credito')->where('n.stato_pagamento', '!=', 'stornata')
            ->whereIn('n.fattura_rettificata_id', $fattureIds)->whereNotIn('n.id', $noteDelloStorno === [] ? [0] : $noteDelloStorno)
            ->orderBy('n.id')->get(['n.id', 'n.fattura_rettificata_id', 'n.numero_documento', 'sc.gestione_id as gestione_scrittura'])
            ->unique('id')->groupBy('fattura_rettificata_id');
        // Per una nota elencata da sola, dove sta la fattura che rettifica.
        $rettificate = $righe->where('tipo_documento', 'nota_credito')->pluck('fattura_rettificata_id')->filter()->unique()->values()->all();
        $gestioneDelleRettificate = [];
        foreach (FatturaPassiva::whereIn('id', $rettificate)->get() as $rettificata) {
            $gestioneDelleRettificate[(int) $rettificata->id] = ['gestione' => $rettificata->gestioneDellaFattura(), 'numero' => $rettificata->numero_documento];
        }

        $nomiGestioni = DB::table('gestioni')->whereIn('id', $righe->pluck('gestione_scrittura')->merge($righe->pluck('gestione_voce'))
            ->merge($noteDelleFatture->flatten(1)->pluck('gestione_scrittura'))->merge(array_column($gestioneDelleRettificate, 'gestione'))->filter()->unique()->all())->pluck('nome', 'id');
        $inPiani = DB::table('piano_rate_fatture')->join('piani_rate', 'piani_rate.id', '=', 'piano_rate_fatture.piano_rate_id')
            ->whereIn('piano_rate_fatture.fattura_passiva_id', $ids)->get(['piano_rate_fatture.fattura_passiva_id', 'piani_rate.nome'])->groupBy('fattura_passiva_id');

        // Ogni documento spostato, con la gestione delle sue voci e il motivo per cui non si sposta (null se si sposta).
        $documenti = [];
        foreach ($righe->groupBy('id') as $id => $delDocumento) {
            $d = $delDocumento->first();
            $gestioniVoci = $delDocumento->pluck('gestione_voce')->unique()->values();
            $documenti[(int) $id] = [
                'riga' => $d,
                'righe' => $delDocumento,
                'nota' => $d->tipo_documento === 'nota_credito',
                'da' => (int) $d->gestione_scrittura,
                'verso' => $gestioniVoci->count() === 1 ? (int) $gestioniVoci->first() : null,
                'piani' => ($inPiani[$id] ?? collect())->pluck('nome')->unique()->values()->all(),
            ];
        }

        $elenco = [];
        foreach ($documenti as $id => $doc) {
            $f = $doc['riga'];
            $verso = $doc['verso'];
            $daGestione = $doc['da'];
            $note = [];
            if ($doc['nota']) {
                // Una nota con la sua fattura spostata si corregge con lei: la voce è quella della fattura.
                if ($f->fattura_rettificata_id !== null && isset($documenti[(int) $f->fattura_rettificata_id]) && ! $documenti[(int) $f->fattura_rettificata_id]['nota']) {
                    continue;
                }
                $motivo = $this->motivo($doc, $nomiGestioni, (string) $f->numero_documento);
                $rettificata = $f->fattura_rettificata_id !== null ? ($gestioneDelleRettificate[(int) $f->fattura_rettificata_id] ?? null) : null;
                if ($motivo === null && $rettificata !== null && $rettificata['gestione'] !== null && $rettificata['gestione'] !== $verso) {
                    $motivo = 'La fattura n. ' . $rettificata['numero'] . ', che la nota rettifica, è sulla gestione «' . ($nomiGestioni[$rettificata['gestione']] ?? '')
                        . '»: spostando la sola nota le due resterebbero su gestioni diverse. La gestione giusta la sceglie l\'amministratore: apri la nota con «Modifica».';
                }
            } else {
                $motivo = $this->motivo($doc, $nomiGestioni, null);
                foreach ($noteDelleFatture[$id] ?? [] as $n) {
                    $spostata = $documenti[(int) $n->id] ?? null;
                    if ($spostata !== null) {
                        $motivo ??= $this->motivo($spostata, $nomiGestioni, (string) $n->numero_documento);
                        if ($motivo === null && $spostata['verso'] !== $verso) {
                            $motivo = 'La nota di credito n. ' . $n->numero_documento . ', che rettifica la fattura, ha le righe su voci della gestione «'
                                . ($nomiGestioni[$spostata['verso']] ?? '') . '»: la gestione giusta la sceglie l\'amministratore. Apri la nota con «Modifica» e porta '
                                . 'le righe su voci della gestione della fattura.';
                        }
                        $note[] = ['id' => (int) $n->id, 'numero_documento' => $n->numero_documento, 'totale_testo' => MoneyHelper::format(abs((int) $spostata['riga']->totale_documento))];
                    } elseif ($motivo === null && $verso !== null && $n->gestione_scrittura !== null && (int) $n->gestione_scrittura !== $verso) {
                        $motivo = 'La nota di credito n. ' . $n->numero_documento . ', che rettifica la fattura, è sulla gestione «' . ($nomiGestioni[(int) $n->gestione_scrittura] ?? '')
                            . '»: spostando la sola fattura le due resterebbero su gestioni diverse. La gestione giusta la sceglie l\'amministratore: apri la nota con '
                            . '«Modifica» e porta le righe su voci di «' . ($nomiGestioni[$verso] ?? '') . '».';
                    }
                }
            }
            $numeriNote = array_column($note, 'numero_documento');
            $insieme = match (count($numeriNote)) {
                0 => '',
                1 => ', insieme alla nota di credito n. ' . $numeriNote[0] . ' che la rettifica',
                default => ', insieme alle note di credito n. ' . implode(', ', array_slice($numeriNote, 0, -1)) . ' e ' . end($numeriNote) . ' che la rettificano',
            };

            $elenco[] = [
                'id' => (int) $id,
                'tipo_documento' => $doc['nota'] ? 'nota_credito' : 'fattura',
                'numero_documento' => $f->numero_documento,
                'data_documento' => $f->data_documento ? \Carbon\Carbon::parse($f->data_documento)->format('d/m/Y') : null,
                'fornitore' => (string) $f->fornitore,
                'esercizio' => $f->esercizio,
                'totale' => abs((int) $f->totale_documento),
                'totale_testo' => MoneyHelper::format(abs((int) $f->totale_documento)),
                'voci' => $doc['righe']->pluck('voce')->unique()->values()->all(),
                'gestione_attuale' => (string) ($nomiGestioni[$daGestione] ?? ''),
                'gestione_attuale_id' => $daGestione,
                'gestione_delle_voci' => $verso !== null ? (string) ($nomiGestioni[$verso] ?? '') : null,
                'gestione_delle_voci_id' => $verso,
                'note' => $note,
                'proposta' => $verso !== null
                    ? 'Riportare ' . ($doc['nota'] ? 'la nota di credito' : 'la fattura') . ' sulla gestione «' . ($nomiGestioni[$verso] ?? '') . '», quella delle sue voci'
                        . $insieme . ': ' . ($note === [] ? 'la scrittura di competenza passa' : 'le scritture di competenza passano') . ' lì, con le stesse righe e gli stessi importi.'
                    : null,
                'correggibile' => $motivo === null,
                'motivo' => $motivo,
            ];
        }

        // Le voci della fattura (o della nota) indicate, con la fattura anche quando è indicata una sua nota: si correggono insieme.
        if ($soloFatture !== null) {
            $solo = array_map('intval', $soloFatture);
            $elenco = array_values(array_filter($elenco, fn ($v) => in_array($v['id'], $solo, true) || array_intersect(array_column($v['note'], 'id'), $solo) !== []));
        }

        return $elenco;
    }

    /**
     * Perché un documento spostato non si riporta sulla gestione delle sue voci; null se si può. Per una nota (`$nota` è il suo numero) le stesse regole, con
     * le parole della nota: «pagata», su una nota, vuol dire compensata.
     *
     * @param  array<string, mixed>  $doc
     */
    private function motivo(array $doc, \Illuminate\Support\Collection $nomiGestioni, ?string $nota): ?string
    {
        $f = $doc['riga'];
        $stato = is_object($f->stato_pagamento) ? $f->stato_pagamento->value : (string) $f->stato_pagamento;
        $gestioneOggi = (string) ($nomiGestioni[$doc['da']] ?? '');
        if ($nota === null) {
            return match (true) {
                $doc['verso'] === null => 'Le righe sono su voci di gestioni diverse: la gestione giusta la sceglie l\'amministratore. Apri la fattura con «Modifica», '
                    . 'scegli la gestione e porta le righe su voci di quella.',
                $stato !== 'aperta' => 'La fattura ha già un pagamento, che sta anch\'esso sulla gestione «' . $gestioneOggi . '»: si corregge '
                    . 'con lo storno del pagamento e della fattura, e una registrazione nuova sulla gestione giusta.',
                $f->stato_esercizio === 'chiuso' => 'L\'esercizio della fattura è chiuso: la sua contabilità non si riscrive.',
                $doc['piani'] !== [] => 'La fattura è nel piano rate «' . implode('», «', $doc['piani']) . '»: toglila dal piano, poi correggi.',
                default => null,
            };
        }

        return match (true) {
            $doc['verso'] === null => 'Le righe della nota di credito n. ' . $nota . ' sono su voci di gestioni diverse: la gestione giusta la sceglie l\'amministratore. '
                . 'Apri la nota con «Modifica», scegli la gestione e porta le righe su voci di quella.',
            $stato !== 'aperta' => 'La nota di credito n. ' . $nota . ' è già compensata, e la compensazione sta anch\'essa sulla gestione «' . $gestioneOggi . '»: si '
                . 'corregge con lo storno del pagamento che la compensa e della nota, e una registrazione nuova sulla gestione giusta.',
            $f->stato_esercizio === 'chiuso' => 'L\'esercizio della nota di credito n. ' . $nota . ' è chiuso: la sua contabilità non si riscrive.',
            $doc['piani'] !== [] => 'La nota di credito n. ' . $nota . ' è nel piano rate «' . implode('», «', $doc['piani']) . '»: toglila dal piano, poi correggi.',
            default => null,
        };
    }

    /** @return list<array<string, mixed>> */
    private function spogliate(int $condominioId, ?array $soloFatture): array
    {
        $righe = DB::table('righe_fattura as r')
            ->join('fatture_passive as fp', 'fp.id', '=', 'r.fattura_passiva_id')
            ->join('fornitori as fo', 'fo.id', '=', 'fp.fornitore_id')
            ->join('conti as c', 'c.id', '=', 'r.conto_id')
            ->join('conti as padre', 'padre.id', '=', 'c.parent_id')
            ->leftJoin('esercizi as e', 'e.id', '=', 'fp.esercizio_id')
            ->where('fp.condominio_id', $condominioId)
            ->where('fp.tipo_documento', 'fattura')
            ->where('fp.stato_pagamento', '!=', 'stornata')
            ->where('padre.nome', self::CAPITOLO_TECNICO)
            ->where('c.is_tecnico', true)
            ->whereNull('r.immobile_id')
            ->where(fn ($q) => $q->where('r.is_sopravvenienza', false)->orWhereNull('r.is_sopravvenienza'))
            ->when($soloFatture !== null, fn ($q) => $q->whereIn('fp.id', $soloFatture))
            ->orderBy('fp.data_documento')->orderBy('fp.id')
            ->get(['r.id as riga_id', 'fp.id', 'fp.numero_documento', 'fp.data_documento', 'fp.totale_documento', 'fo.ragione_sociale as fornitore', 'c.nome as voce',
                'e.stato as stato_esercizio']);

        $elenco = [];
        foreach ($righe->groupBy('id') as $id => $diFattura) {
            $f = $diFattura->first();
            // S2 (Fase 1-bis della beta.12): pagata sì, è il caso di tutti i giorni; in un esercizio chiuso no. Lì le righe non si cambiano più, e il carrello,
            // che è per esercizio, non la offrirebbe a un piano di un esercizio aperto: il segno rimesso non servirebbe a niente.
            $motivo = $f->stato_esercizio === 'chiuso'
                ? 'L\'esercizio della fattura è chiuso: le sue righe non si cambiano più, nemmeno il segno «Fuori preventivo», e il carrello di un piano di un altro '
                    . 'esercizio non la offre.'
                : null;
            $elenco[] = [
                'id' => (int) $id,
                'numero_documento' => $f->numero_documento,
                'data_documento' => $f->data_documento ? \Carbon\Carbon::parse($f->data_documento)->format('d/m/Y') : null,
                'fornitore' => (string) $f->fornitore,
                'totale' => abs((int) $f->totale_documento),
                'totale_testo' => MoneyHelper::format(abs((int) $f->totale_documento)),
                'voci' => $diFattura->pluck('voce')->unique()->values()->all(),
                'righe' => $diFattura->pluck('riga_id')->map(fn ($r) => (int) $r)->all(),
                'proposta' => 'Rimettere il segno «Fuori preventivo» sulla riga: la fattura torna nel carrello di «Spese fuori preventivo». '
                    . 'Nessuna scrittura cambia.',
                'correggibile' => $motivo === null,
                'motivo' => $motivo,
            ];
        }

        return $elenco;
    }

    /**
     * La correzione proposta, su comando dell'amministratore: solo le fatture elencate e correggibili (tutte, o quelle indicate). Restituisce quante.
     *
     * @return array{spostate: int, spogliate: int}
     */
    public function correggi(int $condominioId, ?array $soloFatture = null): array
    {
        // Decisione 548 del registro delle modifiche: la correzione è un gesto solo, firmato dal contesto (dal terminale «Il programma»).
        $gesto = Gesto::su('contabilita', 'fatture_toccate', null, 'Fatture toccate da una modifica')->nelCondominio($condominioId)
            ->riepilogo(fn (Lotto $l, $esito) => 'Fatture riportate come erano: ' . (int) ($esito['spostate'] ?? 0) . ' sulla loro gestione, '
                . (int) ($esito['spogliate'] ?? 0) . ' di nuovo «Fuori preventivo»')
            ->scriviSe(fn (Lotto $l, $esito) => (int) ($esito['spostate'] ?? 0) + (int) ($esito['spogliate'] ?? 0) > 0);

        return RegistroModifiche::durante($gesto, function () use ($condominioId, $soloFatture) {
            $daCorreggere = $this->perCondominio($condominioId, $soloFatture);
            $spostate = 0;
            foreach ($daCorreggere['spostate'] as $f) {
                if (! $f['correggibile']) {
                    continue;
                }
                // D4: la fattura con le sue note di credito spostate, nella stessa transazione: o tutte, o nessuna (le non correggibili non sono qui).
                $documenti = array_merge([$f['id']], array_column($f['note'], 'id'));
                $competenze = DB::table('fattura_scrittura')->whereIn('fattura_passiva_id', $documenti)->where('tipo', 'competenza')->pluck('scrittura_contabile_id');
                DB::table('scritture_contabili')->whereIn('id', $competenze)->update(['gestione_id' => $f['gestione_delle_voci_id'], 'updated_at' => now()]);
                DB::table('fatture_passive')->whereIn('id', $documenti)->update(['gestione_id' => $f['gestione_delle_voci_id'], 'updated_at' => now()]);
                \Illuminate\Support\Facades\Log::info("Coda 687: fattura #{$f['id']} riportata dalla gestione #{$f['gestione_attuale_id']} alla #{$f['gestione_delle_voci_id']}", [
                    'note' => array_column($f['note'], 'id'), 'scritture' => $competenze->all(), 'utente' => \Illuminate\Support\Facades\Auth::id(),
                ]);
                $spostate++;
            }
            $spogliate = 0;
            foreach ($daCorreggere['spogliate'] as $f) {
                if (! $f['correggibile']) {
                    continue;
                }
                $spogliate += DB::table('righe_fattura')->whereIn('id', $f['righe'])->update(['is_sopravvenienza' => true, 'updated_at' => now()]) > 0 ? 1 : 0;
                \Illuminate\Support\Facades\Log::info("Coda 687: fattura #{$f['id']}, segno «Fuori preventivo» rimesso", ['righe' => $f['righe'], 'utente' => \Illuminate\Support\Facades\Auth::id()]);
            }

            return ['spostate' => $spostate, 'spogliate' => $spogliate];
        });
    }
}
