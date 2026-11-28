<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\PianoRate;
use App\Services\CalcoloQuoteService;
use App\Services\Riparto\CompetenzaDelPiano;
use Illuminate\Support\Facades\DB;

/**
 * Il tetto sulle voci: la somma che i piani attivi di un esercizio chiedono su una voce di spesa non supera il maggiore fra il suo preventivo e il suo
 * speso. Vale dopo ogni scrittura che cambia che cosa chiede un piano o di quale esercizio è: la creazione (`PianoRateController::store`), «Ricalcola»
 * con le voci da aggiungere (`SyncOrphanChaptersAction`) e «Esercizio · Cambia» (`PianoRateController::updateEsercizio`). Si legge dopo la scrittura,
 * nella sua transazione: chi chiama rifiuta, e la transazione toglie quello che ha scritto.
 *
 * Fuori dal conto, come il motore li tiene fuori: i piani straordinari, quello degli addebiti e quelli di soli saldi; le voci con un già versato, che hanno
 * la loro guardia (`CalcoloQuoteService::rifiutoDelGiaVersato`), e le spese scritte con il meno, che hanno il loro fermo. Quello che un piano ha ricevuto su una voce con «Sposta spesa» non conta né per lui né per
 * gli altri: lo spostamento è un gesto dell'amministratore che cambia quanto il piano finanzia, e non ferma le altre strade.
 */
class TettoSulleVoci
{
    /** Le note delle righe che il piano prende da sé (la selezione rapida e l'inclusione automatica): non sono cifre scritte a mano. */
    public const NOTE_DEL_PROGRAMMA = ['Selezione rapida (Smart Budget)', 'Inclusione automatica orfani'];

    public function __construct(private CalcoloQuoteService $motore) {}

    /**
     * Quanto chiedono già le voci della gestione gli altri piani attivi dello stesso esercizio del piano (`CalcoloQuoteService::chiestoSulleVociDagliAltriPiani`),
     * senza quello che ognuno ha ricevuto con «Sposta spesa»: `conto_id => ['chiesto' => centesimi, 'piani' => [nome => centesimi]]`.
     *
     * @return array<int, array{chiesto: int, piani: array<string, int>}>
     */
    public function chiestoDagliAltri(PianoRate $piano): array
    {
        $gestione = $piano->gestione;
        if ($gestione === null) {
            return [];
        }
        $altri = $this->motore->chiestoSulleVociDagliAltriPiani($piano, $gestione);
        if ($altri === []) {
            return [];
        }
        $competenza = app(CompetenzaDelPiano::class);
        $mio = $competenza->esercizioDelPiano($piano)?->id;
        $conSpostamenti = PianoRate::query()->where('gestione_id', $gestione->id)->where('attivo', true)->where('id', '!=', $piano->id)
            ->whereIn('id', DB::table('budget_movements')->select('piano_rate_id'))->get()
            ->filter(fn (PianoRate $p) => $competenza->esercizioDelPiano($p)?->id === $mio);
        foreach ($conSpostamenti as $altro) {
            foreach ($this->ricevutoConSpostaSpesa((int) $altro->id) as $contoId => $ricevuto) {
                $nome = (string) $altro->nome;
                if (! isset($altri[$contoId]['piani'][$nome])) {
                    continue;
                }
                $via = min($ricevuto, $altri[$contoId]['piani'][$nome]);
                $altri[$contoId]['piani'][$nome] -= $via;
                $altri[$contoId]['chiesto'] -= $via;
                if ($altri[$contoId]['piani'][$nome] === 0) {
                    unset($altri[$contoId]['piani'][$nome]);
                }
            }
        }

        return $altri;
    }

    /**
     * La prima voce che il piano chiede oltre il tetto, o null. Per ogni voce di spesa che il piano chiede come lo chiederà il motore
     * (`CalcoloQuoteService::chiestoDaQuestoPiano`, con la discesa nelle sottovoci di un capitolo), quanto chiede contro il residuo: il maggiore fra il
     * preventivo e lo speso dell'esercizio, meno quello che ne chiedono gli altri piani (`chiestoDagliAltri`). Un capitolo con un importo suo e delle
     * sottovoci ha il tetto sulla sua parte, quando il piano lo chiede senza una cifra; con una cifra sua il capitolo la divide sulle sue tabelle, e la
     * cifra non ha un preventivo con cui confrontarla. `$soloRighe`: guarda solo le voci a cui il piano arriva da queste righe (le voci appena aggiunte).
     *
     * @param  list<int>|null  $soloRighe
     * @return array{voce: string, tetto: int, residuo: int, chiede: int, piani: array<string, int>, capitolo: ?string, aMano: bool}|null
     */
    public function primoSforo(PianoRate $piano, Esercizio $esercizio, ?array $soloRighe = null): ?array
    {
        $gestione = $piano->gestione;
        if ($gestione === null || $piano->tipo === 'straordinario' || $piano->eDegliAddebiti() || $piano->eDiSoliSaldi()) {
            return null;
        }
        $mie = $this->motore->chiestoDaQuestoPiano($piano, $gestione);
        if ($soloRighe !== null) {
            $mie = array_filter($mie, fn (array $c) => in_array((int) $c[1], $soloRighe, true));
        }
        if ($mie === []) {
            return null;
        }
        $altri = $this->chiestoDagliAltri($piano);
        $mie = array_filter($mie, fn (array $c, int $id) => ($altri[$id]['chiesto'] ?? 0) > 0, ARRAY_FILTER_USE_BOTH);
        if ($mie === []) {
            return null;
        }
        $conti = Conto::query()->whereIn('id', array_values(array_unique([...array_keys($mie), ...array_column($mie, 1)])))->withCount('sottoconti')->get()->keyBy('id');
        $conGiaVersato = ContributoVersato::query()->where('target_type', Conto::class)->whereIn('target_id', array_keys($mie))
            ->distinct()->pluck('target_id')->map(fn ($id) => (int) $id)->all();
        $righe = DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->get(['conto_id', 'importo', 'note'])->keyBy('conto_id');
        $ricevuto = $this->ricevutoConSpostaSpesa((int) $piano->id);
        $speso = app(SpesaPerVoceService::class)->perEsercizio($esercizio, array_keys($mie));
        foreach ($mie as $id => [$chiede, $riga]) {
            $voce = $conti[$id] ?? null;
            if ($voce === null || ! in_array($voce->tipo, Conto::TIPI_DI_SPESA, true) || in_array((int) $id, $conGiaVersato, true)) {
                continue;
            }
            $sua = $righe[$id] ?? null;
            if ($riga === (int) $id && $voce->sottoconti_count > 0 && $sua !== null && $sua->importo !== null) {
                continue;
            }
            // Una spesa scritta con il meno (nella voce o nella riga) ha il suo fermo, che dice quale voce correggere (`SpeseNegativeNelPiano`).
            if ((int) $voce->importo < 0 || ($sua !== null && $sua->importo !== null && (int) $sua->importo < 0)) {
                continue;
            }
            $chiede = max(0, (int) $chiede - ($ricevuto[(int) $id] ?? 0));
            $tetto = max(abs((int) $voce->importo), (int) ($speso[(int) $id] ?? 0));
            $residuo = max(0, $tetto - (int) $altri[$id]['chiesto']);
            if ($chiede <= $residuo) {
                continue;
            }

            return [
                'voce' => (string) $voce->nome, 'tetto' => $tetto, 'residuo' => $residuo, 'chiede' => $chiede, 'piani' => $altri[$id]['piani'],
                'capitolo' => $riga !== (int) $id ? (string) ($conti[$riga]->nome ?? '') : null,
                'aMano' => $sua !== null && $sua->importo !== null && ! in_array($sua->note, self::NOTE_DEL_PROGRAMMA, true),
            ];
        }

        return null;
    }

    /**
     * L'inizio comune delle frasi: «La voce «Pulizie» vale € 1.230,00 ed è già chiesta dal piano «Acconto» (€ 1.230,00)».
     *
     * @param  array{voce: string, tetto: int, piani: array<string, int>}  $sforo
     */
    public static function giaChiesta(array $sforo, bool $maiuscola = true): string
    {
        $piani = collect($sforo['piani'])->map(fn (int $c, string $nome) => '«' . $nome . '» (' . self::euro($c) . ')')->values()->all();
        $elenco = count($piani) > 1 ? implode(', ', array_slice($piani, 0, -1)) . ' e ' . $piani[count($piani) - 1] : ($piani[0] ?? '');

        return ($maiuscola ? 'La' : 'la') . ' voce «' . $sforo['voce'] . '» vale ' . self::euro($sforo['tetto'])
            . (count($piani) > 1 ? ' ed è già chiesta dai piani ' : ' ed è già chiesta dal piano ') . $elenco;
    }

    public static function euro(int $centesimi): string
    {
        return '€ ' . number_format($centesimi / 100, 2, ',', '.');
    }

    /**
     * Quanto il piano ha ricevuto su ogni voce con «Sposta spesa», al netto di quello che ne ha ceduto e degli annullamenti: `conto_id => centesimi`, solo
     * le voci che ne hanno ricevuto più di quanto ne abbiano dato.
     *
     * @return array<int, int>
     */
    private function ricevutoConSpostaSpesa(int $pianoId): array
    {
        $netto = [];
        foreach (DB::table('budget_movements')->where('piano_rate_id', $pianoId)->get(['source_conto_id', 'destination_conto_id', 'amount']) as $m) {
            $netto[(int) $m->destination_conto_id] = ($netto[(int) $m->destination_conto_id] ?? 0) + (int) $m->amount;
            $netto[(int) $m->source_conto_id] = ($netto[(int) $m->source_conto_id] ?? 0) - (int) $m->amount;
        }

        return array_filter($netto, fn (int $c) => $c > 0);
    }
}
