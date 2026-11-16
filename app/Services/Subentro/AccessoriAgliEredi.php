<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Subentro;

use App\Enums\TipoMovimentoContabile;
use App\Helpers\MoneyHelper;
use App\Models\Esercizio;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestionale\Subentro;
use App\Services\Gestionale\Addebiti\AddebitiIndividuali;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gli interessi e le spese addebitati al defunto senza una fattura, non versati, nella successione con l'arretrato agli eredi: passano agli eredi per
 * quota come interessi e spese, non come un saldo. Restano su «Accessori da incassare» finché qualcuno li versa, e il versamento di un erede va alla loro
 * voce come quello del defunto; fuori dalla base degli interessi degli eredi, come erano fuori da quella del defunto.
 *
 * Per ogni addebito del defunto, la parte aperta: una scrittura sulla sua rata, AVERE «Crediti verso condomini» a nome del defunto, DARE a nome di ogni
 * erede per la sua quota; la quota del defunto scende al versato, e ogni erede ha una quota sua sulla stessa rata, con i dati dell'addebito. Il
 * registro del passaggio tiene com'era, e l'annullamento del passaggio lo rimette, finché nessun erede ha versato.
 *
 * Gli addebiti con la fattura dello studio e i contributi passano con le righe dell'arretrato, come prima: sono spese del condominio già fatte.
 */
final class AccessoriAgliEredi
{
    /**
     * Le quote aperte del defunto sulle unità, degli interessi e delle spese senza fattura.
     *
     * @param list<int> $immobileIds
     * @return Collection<int, RataQuote>
     */
    public static function aperti(int $defuntoId, array $immobileIds): Collection
    {
        return RataQuote::query()->where('anagrafica_id', $defuntoId)->whereIn('immobile_id', $immobileIds)->where('tipo', RataQuote::TIPO_ACCESSORIO)
            ->whereColumn('importo_pagato', '<', 'importo')
            ->whereHas('rata.pianoRate', fn ($p) => $p->where('natura', PianoRate::NATURA_ADDEBITI))
            ->with('rata.pianoRate')->orderBy('id')->get()
            ->filter(fn (RataQuote $q) => self::senzaFattura($q))->values();
    }

    /** Un interesse o una spesa senza la fattura dello studio: su «Accessori da incassare». */
    public static function senzaFattura(RataQuote $q): bool
    {
        $dati = AddebitiIndividuali::dati($q);

        return $q->tipo === RataQuote::TIPO_ACCESSORIO && ($dati['riga_fattura_id'] ?? null) === null && isset($dati['conto_id']);
    }

    /**
     * La parte aperta di ogni quota, divisa fra gli eredi per quota.
     *
     * @param Collection<int, RataQuote> $quote
     * @param array<int, float> $pesi erede => quota
     * @return list<array{quota_id: int, rata_id: int, immobile_id: int, causale: string, aperto: int, per_erede: array<int, int>}>
     */
    public static function divisione(Collection $quote, array $pesi): array
    {
        return $quote->map(fn (RataQuote $q) => [
            'quota_id' => (int) $q->id,
            'rata_id' => (int) $q->rata_id,
            'immobile_id' => (int) $q->immobile_id,
            'causale' => trim((string) ($q->rata?->descrizione ?? 'Addebito')),
            'aperto' => (int) $q->importo - (int) $q->importo_pagato,
            'per_erede' => array_map('intval', MoneyHelper::ripartisciPerQuote((int) $q->importo - (int) $q->importo_pagato, $pesi)),
        ])->values()->all();
    }

    /**
     * La frase dell'anteprima: «Gli interessi e le spese addebitati a Neri e non versati, € 1,18, passano agli eredi come interessi e spese, per quota:
     * € 0,59 ad Anna, € 0,59 a Bruno.» Null senza.
     *
     * @param array<int, string> $nomi erede => nome
     */
    public static function frase(array $divisione, string $defunto, array $nomi): ?string
    {
        $totale = (int) array_sum(array_column($divisione, 'aperto'));
        if ($totale === 0) {
            return null;
        }
        $perErede = [];
        foreach ($divisione as $d) {
            foreach ($d['per_erede'] as $id => $c) {
                $perErede[$id] = ($perErede[$id] ?? 0) + $c;
            }
        }
        $parti = [];
        foreach ($perErede as $id => $c) {
            $parti[] = MoneyHelper::format($c) . ' ' . FrasiObbligati::a($nomi[$id] ?? '?');
        }

        return sprintf('Gli interessi e le spese addebitati a %s e non versati, %s, passano agli eredi come interessi e spese, per quota: %s. Restano da '
            . 'incassare finché un erede li versa, e il versamento va alla loro voce.', $defunto, MoneyHelper::format($totale), implode(', ', $parti));
    }

    /**
     * Scrive il passaggio agli eredi di ogni quota della divisione. Rilegge le quote: se una è cambiata dopo l'anteprima (un versamento), si ferma.
     *
     * @param list<string> $avvisi
     * @return list<array<string, mixed>> il registro, per quota
     */
    public function scrivi(Subentro $subentro, array $divisione, int $defuntoId, CarbonImmutable $decorrenza, array &$avvisi): array
    {
        $registro = [];
        $crediti = null;
        foreach ($divisione as $d) {
            $q = RataQuote::with('rata.pianoRate.gestione')->lockForUpdate()->find($d['quota_id']);
            if ($q === null || (int) $q->anagrafica_id !== $defuntoId || (int) $q->importo - (int) $q->importo_pagato !== (int) $d['aperto']) {
                throw ValidationException::withMessages(['passaggio' => sprintf('«%s» è cambiato mentre registravi (un versamento o un annullamento): ricarica il pannello e riprova.', $d['causale'])]);
            }
            $piano = $q->rata->pianoRate;
            $esercizio = $this->esercizio($piano, (int) $subentro->condominio_id);
            if ($esercizio === null) {
                $avvisi[] = sprintf('Nessun esercizio aperto su cui scrivere «%s»: resta a nome del defunto.', $d['causale']);

                continue;
            }
            $crediti ??= ContoContabile::where('condominio_id', $subentro->condominio_id)->where('ruolo', 'crediti_condomini')->whereNull('deleted_at')->firstOrFail();
            $inizio = CarbonImmutable::parse($esercizio->data_inizio)->toDateString();
            $fine = CarbonImmutable::parse($esercizio->data_fine)->toDateString();
            $giorno = min(max($decorrenza->toDateString(), $inizio), $fine);
            // La gestione del piano, se è dell'esercizio; altrimenti una dello stesso tipo in quell'esercizio.
            $gestioni = DB::table('esercizio_gestione as eg')->join('gestioni as g', 'g.id', '=', 'eg.gestione_id')->where('eg.esercizio_id', $esercizio->id)
                ->orderBy('g.id')->get(['g.id', 'g.tipo']);
            $gestioneId = $gestioni->contains('id', $piano->gestione_id) ? (int) $piano->gestione_id
                : (int) ($gestioni->firstWhere('tipo', $piano->gestione?->tipo)?->id ?? $piano->gestione_id);
            $scrittura = ScritturaContabile::create([
                'condominio_id' => $subentro->condominio_id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $gestioneId,
                'data_registrazione' => now()->toDateString(), 'data_competenza' => $giorno,
                'causale' => mb_substr('Agli eredi: ' . $d['causale'], 0, 255), 'tipo_movimento' => TipoMovimentoContabile::EMISSIONE_RATA,
                'stato' => 'registrata', 'created_by' => auth()->id(),
            ]);
            $scrittura->righe()->create(['conto_contabile_id' => $crediti->id, 'anagrafica_id' => $defuntoId, 'immobile_id' => $q->immobile_id, 'rata_id' => $q->rata_id,
                'tipo_riga' => 'avere', 'importo' => $d['aperto'], 'note' => 'Passato agli eredi con la successione']);
            $nuove = [];
            foreach ($d['per_erede'] as $eredeId => $cents) {
                if ($cents <= 0) {
                    continue;
                }
                $scrittura->righe()->create(['conto_contabile_id' => $crediti->id, 'anagrafica_id' => $eredeId, 'immobile_id' => $q->immobile_id, 'rata_id' => $q->rata_id,
                    'tipo_riga' => 'dare', 'importo' => $cents, 'note' => 'Dalla successione']);
                $regole = is_string($q->regole_calcolo) ? (json_decode($q->regole_calcolo, true) ?: []) : (array) $q->regole_calcolo;
                $regole['importi'] = ['quota_pura_gestione' => $cents, 'saldo_usato' => 0, 'totale_calcolato' => $cents, 'addebito_diretto' => 0];
                $regole['dagli_eredi'] = ['da_anagrafica_id' => $defuntoId, 'da_quota_id' => (int) $q->id, 'subentro_id' => (int) $subentro->id];
                $nuova = new RataQuote(['rata_id' => $q->rata_id, 'anagrafica_id' => $eredeId, 'immobile_id' => $q->immobile_id, 'importo' => $cents, 'importo_pagato' => 0,
                    'stato' => 'da_pagare', 'data_scadenza' => $q->data_scadenza, 'regole_calcolo' => $regole]);
                $nuova->forceFill(['tipo' => RataQuote::TIPO_ACCESSORIO, 'scrittura_contabile_id' => $scrittura->id])->save();
                $nuove[] = (int) $nuova->id;
            }
            $registro[] = ['quota_id' => (int) $q->id, 'importo' => (int) $q->importo, 'stato' => $q->stato, 'scrittura_id' => (int) $scrittura->id, 'nuove' => $nuove];
            // La quota del defunto resta con il suo versato: chiusa, come la legge lo stato di una quota.
            $q->forceFill(['importo' => (int) $q->importo_pagato, 'stato' => 'pagata'])->save();
        }

        return $registro;
    }

    /** Perché l'annullamento del passaggio non rimette gli interessi e le spese passati agli eredi: un erede ha già versato. Null se si rimettono. */
    public static function motivo(array $registro): ?string
    {
        $nuove = collect($registro)->flatMap(fn ($r) => $r['nuove'] ?? [])->all();
        if ($nuove === []) {
            return null;
        }
        $pagata = RataQuote::with('rata')->whereIn('id', $nuove)->where('importo_pagato', '!=', 0)->first();

        return $pagata === null ? null : sprintf('Un erede ha già versato su «%s», passato agli eredi con la successione. Storna prima l\'incasso, poi torna qui.',
            trim((string) ($pagata->rata?->descrizione ?? 'un addebito')));
    }

    /** Rimette com'erano gli interessi e le spese passati agli eredi: via le quote degli eredi e la scrittura, la quota del defunto come prima. */
    public static function annulla(array $registro): void
    {
        foreach ($registro as $r) {
            RataQuote::whereIn('id', $r['nuove'] ?? [])->delete();
            DB::table('righe_scritture')->where('scrittura_id', (int) $r['scrittura_id'])->delete();
            ScritturaContabile::whereKey((int) $r['scrittura_id'])->forceDelete();
            RataQuote::whereKey((int) $r['quota_id'])->update(['importo' => (int) $r['importo'], 'stato' => $r['stato']]);
        }
    }

    /** L'esercizio della scrittura: quello del piano degli addebiti, se è aperto; altrimenti il primo aperto del condominio. */
    private function esercizio(PianoRate $piano, int $condominioId): ?Esercizio
    {
        $delPiano = $piano->esercizio_id === null ? null : Esercizio::find($piano->esercizio_id);
        if ($delPiano !== null && ($delPiano->stato ?? 'aperto') === 'aperto') {
            return $delPiano;
        }

        return Esercizio::where('condominio_id', $condominioId)->where('stato', 'aperto')->orderBy('data_inizio')->first();
    }
}
