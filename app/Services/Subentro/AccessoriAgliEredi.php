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
use App\Models\User;
use App\Services\Gestionale\Addebiti\AddebitiIndividuali;
use App\Services\Gestionale\EventiRataCondomino;
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
 * registro del passaggio tiene com'era, e l'annullamento del passaggio lo rimette, finché nessun erede ha versato e nessuno ha un pagamento segnalato.
 *
 * I promemoria del portale seguono le quote nello stesso passo (Coda 652), con la regola delle bozze che passano a chi entra
 * ({@see EventiRataCondomino::seguonoLeQuote()}): quello del defunto si riallinea alla sua quota chiusa e dice «pagato»; ogni erede ha il suo, nato come
 * quello di un addebito. L'annullamento li rimette com'erano: via quelli degli eredi, quello del defunto di nuovo da versare.
 *
 * Un accessorio con un pagamento segnalato dal portale e non ancora verificato sul promemoria del defunto resta al defunto, come la bozza segnalata (R17,
 * {@see ConguaglioPassaggio}): la segnalazione e il suo compito restano com'erano. L'amministratore può sceglierlo nell'anteprima e passarlo comunque agli
 * eredi (decisione 448): allora il promemoria del defunto resta «segnalato», con la segnalazione da verificare, anche dopo un annullamento. La scrittura
 * rilegge la segnalazione sotto lock: una arrivata dopo l'anteprima ferma il passaggio di quell'accessorio.
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
     * La parte aperta di ogni quota, divisa fra gli eredi per quota. Con `segnalato` la quota ha un pagamento segnalato dal portale e l'amministratore la
     * passa comunque agli eredi (decisione 448): la scrittura la lascia passare anche se la segnalazione c'è ancora.
     *
     * @param Collection<int, RataQuote> $quote
     * @param array<int, float> $pesi erede => quota
     * @param array<int, true> $segnalate le quote con la segnalazione ({@see segnalate()})
     * @return list<array{quota_id: int, rata_id: int, immobile_id: int, causale: string, aperto: int, per_erede: array<int, int>, segnalato?: bool}>
     */
    public static function divisione(Collection $quote, array $pesi, array $segnalate = []): array
    {
        return $quote->map(fn (RataQuote $q) => [
            'quota_id' => (int) $q->id,
            'rata_id' => (int) $q->rata_id,
            'immobile_id' => (int) $q->immobile_id,
            'causale' => trim((string) ($q->rata?->descrizione ?? 'Addebito')),
            'aperto' => (int) $q->importo - (int) $q->importo_pagato,
            'per_erede' => array_map('intval', MoneyHelper::ripartisciPerQuote((int) $q->importo - (int) $q->importo_pagato, $pesi)),
        ] + (isset($segnalate[(int) $q->id]) ? ['segnalato' => true] : []))->values()->all();
    }

    /**
     * Le quote date con un pagamento segnalato dal portale e non ancora verificato sul promemoria del defunto (`reported`): quota => true. Con `$sottoLock`
     * le righe ponte del defunto e i suoi promemoria si leggono bloccati, nello stesso ordine del portale e dell'annullamento (ponte, poi promemoria): una
     * segnalazione in volo aspetta, e la lettura vede quella appena arrivata.
     *
     * @param Collection<int, RataQuote> $quote
     * @return array<int, true>
     */
    public static function segnalate(Collection $quote, int $defuntoId, bool $sottoLock = false): array
    {
        if ($quote->isEmpty()) {
            return [];
        }
        $eventi = $sottoLock ? self::promemoriaBloccati($defuntoId) : \App\Models\Evento::where('tipo', \App\Enums\EventoTipo::SCADENZA_RATA_CONDOMINO->value)
            ->whereHas('anagrafiche', fn ($w) => $w->where('anagrafica_id', $defuntoId))->get();
        $rate = $eventi->filter(fn ($e) => ($e->meta['status'] ?? null) === 'reported')->map(fn ($e) => (int) ($e->meta['context']['rata_id'] ?? 0))->filter()->flip()->all();

        return $quote->filter(fn (RataQuote $q) => isset($rate[(int) $q->rata_id]))->mapWithKeys(fn (RataQuote $q) => [(int) $q->id => true])->all();
    }

    /** I promemoria della persona, letti con le righe ponte e poi i promemoria bloccati, per chiave. */
    private static function promemoriaBloccati(int $persona): Collection
    {
        $ponte = DB::table('anagrafica_evento')->where('anagrafica_id', $persona)->lockForUpdate()->pluck('id')->all();
        $ids = DB::table('anagrafica_evento')->whereIn('id', $ponte ?: [0])->lockForUpdate()->pluck('evento_id')->all();

        return \App\Models\Evento::whereIn('id', $ids ?: [0])->where('tipo', \App\Enums\EventoTipo::SCADENZA_RATA_CONDOMINO->value)->lockForUpdate()->get();
    }

    /**
     * La frase dell'anteprima per un accessorio con un pagamento segnalato (decisione 448): di serie resta al defunto; passato comunque per scelta
     * dell'amministratore, la segnalazione resta da verificare.
     */
    public static function fraseSegnalato(string $causale, int $aperto, string $defunto, bool $passa): string
    {
        $testa = sprintf('«%s» (%s) ha un pagamento segnalato dal portale e non ancora verificato: ', $causale, MoneyHelper::format($aperto));

        return $passa
            ? $testa . 'come hai scelto, passa comunque agli eredi per quota, e la segnalazione resta da verificare. ' . self::fraseDelPagamentoVero($defunto)
            : $testa . sprintf('resta a nome di %s e non passa agli eredi, e la segnalazione resta da verificare. Puoi passarlo comunque agli eredi con la casella '
                . 'in «L\'arretrato del defunto».', $defunto);
    }

    /**
     * Con l'accessorio passato comunque agli eredi, la strada del pagamento vero del defunto: dal compito della segnalazione, sulla quota di ogni erede con
     * «Versato da» il defunto; il primo incasso chiude il compito e riallinea il promemoria del defunto.
     */
    public static function fraseDelPagamentoVero(string $defunto): string
    {
        return sprintf('Se il pagamento risulta arrivato, registralo con «Registra l\'incasso» dal compito del bonifico segnalato, sulla quota di ogni erede con '
            . '«Versato da» %s: il primo incasso chiude il compito.', $defunto);
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
    public function scrivi(Subentro $subentro, array $divisione, int $defuntoId, CarbonImmutable $decorrenza, array &$avvisi, User $utente): array
    {
        $registro = [];
        $crediti = null;
        $rate = [];
        $persone = [$defuntoId];
        // Le quote bloccate, poi le segnalazioni del defunto rilette sotto lock (decisione 448): una arrivata dal portale dopo l'anteprima ferma il
        // passaggio di quell'accessorio, salvo che l'amministratore l'abbia già visto segnalato e scelto di passarlo comunque.
        $bloccate = RataQuote::with('rata.pianoRate.gestione')->whereIn('id', array_map(fn ($d) => (int) $d['quota_id'], $divisione) ?: [0])->lockForUpdate()->get()->keyBy('id');
        $segnalate = self::segnalate($bloccate->values(), $defuntoId, sottoLock: true);
        foreach ($divisione as $d) {
            $q = $bloccate->get((int) $d['quota_id']);
            if ($q === null || (int) $q->anagrafica_id !== $defuntoId || (int) $q->importo - (int) $q->importo_pagato !== (int) $d['aperto']) {
                throw ValidationException::withMessages(['passaggio' => sprintf('«%s» è cambiato mentre registravi (un versamento o un annullamento): ricarica il pannello e riprova.', $d['causale'])]);
            }
            if (isset($segnalate[(int) $q->id]) && ! ($d['segnalato'] ?? false)) {
                throw ValidationException::withMessages(['passaggio' => sprintf('«%s» è cambiato mentre registravi (un pagamento segnalato dal portale): ricarica il pannello e riprova.', $d['causale'])]);
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
            // Coda 494: il giorno dell'amministratore, non quello di Greenwich (alle 00:30 di Roma del 01/01 era ancora il 31/12).
            $scrittura = ScritturaContabile::create([
                'condominio_id' => $subentro->condominio_id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $gestioneId,
                'data_registrazione' => \App\Helpers\DateHelper::oggiUtente(), 'data_competenza' => $giorno,
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
                $persone[] = (int) $eredeId;
            }
            // Passato agli eredi con un pagamento segnalato, per scelta dell'amministratore: il registro lo tiene (decisione 448).
            $registro[] = ['quota_id' => (int) $q->id, 'importo' => (int) $q->importo, 'stato' => $q->stato, 'scrittura_id' => (int) $scrittura->id, 'nuove' => $nuove]
                + (($d['segnalato'] ?? false) ? ['segnalato' => true, 'aperto' => (int) $d['aperto']] : []);
            // La quota del defunto resta con il suo versato: chiusa, come la legge lo stato di una quota.
            $q->forceFill(['importo' => (int) $q->importo_pagato, 'stato' => 'pagata'])->save();
            $rate[] = (int) $q->rata_id;
        }
        // I promemoria seguono le quote, nello stesso passo: il defunto non deve più vedersi ricordare un debito che è degli eredi.
        if ($rate !== []) {
            self::riallineaPromemoria(array_values(array_unique($rate)), array_values(array_unique($persone)), $defuntoId, $utente);
        }

        return $registro;
    }

    /**
     * I promemoria seguono le quote ({@see EventiRataCondomino::seguonoLeQuote()}), alla scrittura e all'annullamento; quello del defunto con un pagamento
     * segnalato dal portale e non verificato resta «segnalato» (decisione 448): la segnalazione resta da verificare, e l'annullamento la rimette com'era.
     *
     * @param list<int> $rate
     * @param list<int> $persone
     */
    public static function riallineaPromemoria(array $rate, array $persone, int $defuntoId, User $utente): void
    {
        $diChi = fn () => \App\Models\Evento::where('tipo', \App\Enums\EventoTipo::SCADENZA_RATA_CONDOMINO->value)
            ->whereHas('anagrafiche', fn ($w) => $w->where('anagrafica_id', $defuntoId))->get()
            ->filter(fn ($e) => in_array((int) ($e->meta['context']['rata_id'] ?? 0), $rate, true));
        $segnalati = $diChi()->filter(fn ($e) => ($e->meta['status'] ?? null) === 'reported')->pluck('id')->all();
        app(EventiRataCondomino::class)->seguonoLeQuote($rate, $persone, $utente);
        foreach (\App\Models\Evento::whereIn('id', $segnalati ?: [0])->get() as $e) {
            if (($e->meta['status'] ?? null) !== 'reported') {
                $meta = $e->meta;
                $meta['status'] = 'reported';
                $e->update(['meta' => $meta]);
            }
        }
    }

    /**
     * Perché l'annullamento del passaggio non rimette gli interessi e le spese passati agli eredi: un erede ha già versato; oppure un pagamento segnalato
     * dal portale e non verificato sul promemoria di un erede, che l'annullamento toglie: la regola dell'annullamento per le rate in bozza
     * ({@see \App\Actions\Subentro\AnnullaPassaggioAction}). Il promemoria segnalato del defunto no: l'annullamento lo lascia «segnalato»
     * ({@see riallineaPromemoria()}). Null se si rimettono.
     */
    public static function motivo(array $registro): ?string
    {
        $nuove = collect($registro)->flatMap(fn ($r) => $r['nuove'] ?? [])->all();
        if ($nuove === []) {
            return null;
        }
        $pagata = RataQuote::with('rata')->whereIn('id', $nuove)->where('importo_pagato', '!=', 0)->first();
        if ($pagata !== null) {
            return sprintf('Un erede ha già versato su «%s», passato agli eredi con la successione. Storna prima l\'incasso, poi torna qui.',
                trim((string) ($pagata->rata?->descrizione ?? 'un addebito')));
        }
        $rate = self::promemoriaDaRiscrivere($registro)['rate'];
        $persone = RataQuote::whereIn('id', $nuove)->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $segnalata = \App\Models\Evento::where('tipo', \App\Enums\EventoTipo::SCADENZA_RATA_CONDOMINO->value)->where('meta->status', 'reported')
            ->whereHas('anagrafiche', fn ($w) => $w->whereIn('anagrafica_id', $persone ?: [0]))->with('anagrafiche:id,nome')->get()
            ->first(fn ($e) => in_array((int) ($e->meta['context']['rata_id'] ?? 0), $rate, true));
        if ($segnalata === null) {
            return null;
        }
        $rata = \App\Models\Gestionale\Rata::find((int) $segnalata->meta['context']['rata_id']);
        $chi = $segnalata->anagrafiche->first(fn ($a) => in_array((int) $a->id, $persone, true));

        return sprintf('Su «%s», passato agli eredi con la successione, c\'è un pagamento segnalato dal portale da %s, non ancora verificato. Verificalo o rifiutalo, poi torna qui.',
            trim((string) ($rata?->descrizione ?? 'un addebito')), $chi?->nome ?? 'un condòmino');
    }

    /**
     * Le rate degli interessi e delle spese passati agli eredi e le persone che vi hanno una quota del passaggio (il defunto e gli eredi): i promemoria che
     * l'annullamento riscrive. Si legge prima dell'annullamento, finché le quote degli eredi ci sono.
     *
     * @return array{rate: list<int>, persone: list<int>}
     */
    public static function promemoriaDaRiscrivere(array $registro): array
    {
        $ids = collect($registro)->flatMap(fn ($r) => [(int) ($r['quota_id'] ?? 0), ...array_map('intval', $r['nuove'] ?? [])])->filter()->values()->all();
        $quote = $ids === [] ? collect() : RataQuote::whereIn('id', $ids)->get(['id', 'rata_id', 'anagrafica_id']);

        return ['rate' => $quote->pluck('rata_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'persone' => $quote->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->unique()->values()->all()];
    }

    /**
     * Rimette com'erano gli interessi e le spese passati agli eredi: via le quote degli eredi e la scrittura, la quota del defunto con l'importo di prima.
     * Lo stato non si rilegge dal registro: si rifà dall'importo e dal versato di oggi, perché dopo il passaggio un versamento di prima può essere stato
     * stornato (allora la quota non è più «parzialmente pagata»).
     */
    public static function annulla(array $registro): void
    {
        foreach ($registro as $r) {
            RataQuote::whereIn('id', $r['nuove'] ?? [])->delete();
            DB::table('righe_scritture')->where('scrittura_id', (int) $r['scrittura_id'])->delete();
            ScritturaContabile::whereKey((int) $r['scrittura_id'])->forceDelete();
            $pagato = (int) RataQuote::whereKey((int) $r['quota_id'])->value('importo_pagato');
            $importo = (int) $r['importo'];
            RataQuote::whereKey((int) $r['quota_id'])->update(['importo' => $importo,
                'stato' => $pagato >= $importo ? 'pagata' : ($pagato > 0 ? 'parzialmente_pagata' : 'da_pagare')]);
        }
    }

    /** L'esercizio della scrittura: quello del piano degli addebiti, se è aperto; altrimenti quello aperto del condominio (con due, il più recente: `HasEsercizio`). */
    private function esercizio(PianoRate $piano, int $condominioId): ?Esercizio
    {
        $delPiano = $piano->esercizio_id === null ? null : Esercizio::find($piano->esercizio_id);
        if ($delPiano !== null && ($delPiano->stato ?? 'aperto') === 'aperto') {
            return $delPiano;
        }

        return Esercizio::apertoPiuRecente($condominioId);
    }
}
