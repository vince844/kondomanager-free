<?php

namespace App\Services\Gestionale;

use App\Enums\TipoMovimentoContabile;
use App\Models\Condominio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Riallineamento delle scritture storiche sui fondi (beta.19).
 *
 * Prima della beta.19 la registrazione di una fattura con copertura da fondo
 * scriveva righe direttamente sul conto del fondo, in convenzione passiva
 * (DARE = utilizzo). Con la lettura unificata da attivo quelle righe risultano
 * AUMENTI del fondo: vanno neutralizzate. Il giornale è append-only, quindi la
 * correzione è una scrittura di RETTIFICA per ogni scrittura storica coinvolta:
 *
 *  - ogni riga storica sul conto del fondo viene specchiata (DARE ↔ AVERE);
 *  - le righe gemelle su sopravvenienze passive (la vecchia coppia dello sforo,
 *    stesso importo e segno opposto) vengono specchiate anch'esse;
 *  - l'eventuale sbilancio residuo si chiude su passate gestioni — è il conto
 *    su cui il pregresso con copertura fondo scrive oggi.
 *
 * Nessun automatismo: il rilevamento alimenta una card nella pagina Giroconti
 * (visibile SOLO se ci sono scritture da riallineare) e l'esecuzione parte da
 * una conferma esplicita dell'amministratore — o dal comando artisan
 * kondomanager:riallinea-fondi, che avvolge questo stesso service.
 */
class RiallineaFondiService
{
    private const CAUSALE_MARKER = 'Riallineamento fondi (beta.19)';

    /** I tipi di movimento del ciclo passivo che, nel vecchio regime, toccavano i fondi. */
    private const TIPI_STORICI = ['fattura_acquisto', 'storno_fattura'];

    /**
     * Scritture storiche che toccano i conti dei fondi e non sono ancora state
     * rettificate.
     *
     * @return Collection<int, array{scrittura_id:int,protocollo:?string,data:?string,causale:?string,righe_fondo:array,importo_netto_fondo:int}>
     */
    public function rileva(Condominio $condominio): Collection
    {
        $contiFondo = Cassa::where('condominio_id', $condominio->id)
            ->where('tipo', 'fondo')
            ->whereNotNull('conto_contabile_id')
            ->pluck('conto_contabile_id');

        if ($contiFondo->isEmpty()) {
            return collect();
        }

        $giaRettificate = ScritturaContabile::where('condominio_id', $condominio->id)
            ->where('tipo_movimento', TipoMovimentoContabile::RETTIFICA->value)
            ->where('causale', 'like', self::CAUSALE_MARKER.'%')
            ->whereNotNull('scrittura_padre_id')
            ->pluck('scrittura_padre_id');

        return ScritturaContabile::where('condominio_id', $condominio->id)
            ->whereIn('tipo_movimento', self::TIPI_STORICI)
            ->whereNotIn('id', $giaRettificate)
            ->whereHas('righe', fn ($q) => $q->whereIn('conto_contabile_id', $contiFondo))
            ->with(['righe' => fn ($q) => $q->whereIn('conto_contabile_id', $contiFondo), 'righe.contoContabile:id,nome'])
            ->orderBy('data_competenza')
            ->get()
            ->map(function (ScritturaContabile $s) {
                $netto = $s->righe->sum(fn ($r) => $r->tipo_riga === 'dare' ? $r->importo : -$r->importo);

                return [
                    'scrittura_id' => $s->id,
                    'protocollo' => $s->numero_protocollo,
                    'data' => $s->data_competenza?->format('d/m/Y'),
                    'causale' => $s->causale,
                    'righe_fondo' => $s->righe->map(fn ($r) => [
                        'conto' => $r->contoContabile?->nome ?? '—',
                        'tipo_riga' => $r->tipo_riga,
                        'importo' => (int) $r->importo,
                    ])->values()->all(),
                    'importo_netto_fondo' => (int) $netto,
                ];
            })
            ->values();
    }

    /**
     * Genera le scritture di rettifica per tutte le scritture rilevate.
     * Ritorna i protocolli delle rettifiche create.
     *
     * @return array{rettificate:int, protocolli:array<string>}
     */
    public function esegui(Condominio $condominio, ?int $esercizioId = null): array
    {
        $daRiallineare = $this->rileva($condominio);

        if ($daRiallineare->isEmpty()) {
            return ['rettificate' => 0, 'protocolli' => []];
        }

        $esercizio = $esercizioId
            ? $condominio->esercizi()->where('esercizi.id', $esercizioId)->where('stato', 'aperto')->firstOrFail()
            : $condominio->esercizi()->where('stato', 'aperto')->orderBy('data_inizio')->firstOrFail();

        $contiFondo = Cassa::where('condominio_id', $condominio->id)
            ->where('tipo', 'fondo')
            ->whereNotNull('conto_contabile_id')
            ->pluck('conto_contabile_id');

        $contoSopravvenienze = ContoContabile::where('condominio_id', $condominio->id)
            ->where('ruolo', 'sopravvenienze_passive')
            ->first();

        // Serve solo se una rettifica lascia uno sbilancio residuo (caso pregresso):
        // per le coppie complete dello sforo la rettifica quadra da sola.
        $contoPassateGestioni = ContoContabile::where('condominio_id', $condominio->id)
            ->where('ruolo', 'passate_gestioni')
            ->first();

        $protocolli = [];

        foreach ($daRiallineare as $item) {
            $originale = ScritturaContabile::with('righe')->findOrFail($item['scrittura_id']);

            DB::transaction(function () use ($originale, $condominio, $esercizio, $contiFondo, $contoSopravvenienze, $contoPassateGestioni, &$protocolli) {

                // Anti doppia esecuzione (card web + artisan simultanei, doppio POST):
                // lock sull'originale e ricontrollo del marker DENTRO la transazione.
                // Senza, due esecuzioni concorrenti rileverebbero entrambe la stessa
                // scrittura e il fondo verrebbe sovra-corretto.
                ScritturaContabile::whereKey($originale->id)->lockForUpdate()->first();

                $giaRettificata = ScritturaContabile::where('scrittura_padre_id', $originale->id)
                    ->where('tipo_movimento', TipoMovimentoContabile::RETTIFICA->value)
                    ->where('causale', 'like', self::CAUSALE_MARKER.'%')
                    ->exists();

                if ($giaRettificata) {
                    return;
                }

                $righeFondo = $originale->righe->filter(
                    fn ($r) => $contiFondo->contains($r->conto_contabile_id)
                );

                // Le gemelle della vecchia coppia sforo: righe su sopravvenienze
                // passive con segno opposto e importo uguale a una riga fondo.
                $righeSopravvenienze = collect();
                if ($contoSopravvenienze) {
                    $candidate = $originale->righe
                        ->where('conto_contabile_id', $contoSopravvenienze->id)
                        ->values();

                    foreach ($righeFondo as $rigaFondo) {
                        $gemella = $candidate->first(
                            fn ($r) => (int) $r->importo === (int) $rigaFondo->importo
                                && $r->tipo_riga !== $rigaFondo->tipo_riga
                                && ! $righeSopravvenienze->contains('id', $r->id)
                        );
                        if ($gemella) {
                            $righeSopravvenienze->push($gemella);
                        }
                    }
                }

                $rettifica = ScritturaContabile::create([
                    'condominio_id' => $condominio->id,
                    'esercizio_id' => $esercizio->id,
                    'gestione_id' => $originale->gestione_id,
                    'scrittura_padre_id' => $originale->id,
                    'data_registrazione' => now()->toDateString(),
                    'data_competenza' => now()->toDateString(),
                    'causale' => mb_substr(
                        self::CAUSALE_MARKER." — rettifica {$originale->numero_protocollo}",
                        0, 255
                    ),
                    'tipo_movimento' => TipoMovimentoContabile::RETTIFICA,
                    'stato' => 'registrata',
                    'created_by' => auth()->id(),
                    'note' => 'Neutralizza le righe storiche sul fondo scritte prima della beta.19 '
                        .'(convenzione passiva): da questa versione i fondi si muovono solo via giroconto.',
                ]);

                $sbilancio = 0; // dare − avere della rettifica, da chiudere su passate gestioni

                foreach ($righeFondo->merge($righeSopravvenienze) as $riga) {
                    $tipoInverso = $riga->tipo_riga === 'dare' ? 'avere' : 'dare';

                    $rettifica->righe()->create([
                        'conto_contabile_id' => $riga->conto_contabile_id,
                        'cassa_id' => $riga->cassa_id,
                        'tipo_riga' => $tipoInverso,
                        'importo' => $riga->importo,
                        'note' => 'Rettifica riga storica — '.($riga->note ?: $originale->causale),
                    ]);

                    $sbilancio += $tipoInverso === 'dare' ? $riga->importo : -$riga->importo;
                }

                if ($sbilancio !== 0) {
                    if (! $contoPassateGestioni) {
                        throw new \DomainException(
                            "La rettifica di {$originale->numero_protocollo} richiede il conto "
                            .'«Passate gestioni», assente nel piano dei conti di questo condominio: '
                            .'aprire il gestionale del condominio una volta (il piano dei conti si '
                            .'completa da solo) e rilanciare il riallineamento.'
                        );
                    }

                    $rettifica->righe()->create([
                        'conto_contabile_id' => $contoPassateGestioni->id,
                        'tipo_riga' => $sbilancio < 0 ? 'dare' : 'avere',
                        'importo' => abs($sbilancio),
                        'note' => 'Chiusura rettifica su passate gestioni',
                    ]);
                }

                DoubleEntryValidator::validateOrFail($rettifica->id);

                $protocolli[] = $rettifica->numero_protocollo;
            });
        }

        return ['rettificate' => count($protocolli), 'protocolli' => $protocolli];
    }
}
