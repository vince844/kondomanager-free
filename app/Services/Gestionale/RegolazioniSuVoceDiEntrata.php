<?php

namespace App\Services\Gestionale;

use App\Enums\TipoMovimentoContabile;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ScritturaContabile;

/**
 * Le regolazioni immediate non stornate scritte su una voce che oggi non è di spesa, per condominio (Coda 287).
 *
 * La regolazione immediata scrive DARE sul mastro di costo della voce e AVERE sulla cassa: è un'uscita di banca. Dalla 1.11.0-beta.52 la
 * registrazione rifiuta le voci di entrata (`RegistraRegolazioneImmediataAction`, `StoreRegolazioneImmediataRequest`), ma quelle scritte prima
 * restano nel giornale: per ognuna la banca è scesa dell'importo invece di salire. Questo servizio le trova, in sola lettura, perché la lista delle
 * fatture passive le mostri finché non sono stornate. Non le corregge: stornarle o tenerle lo decide l'amministratore.
 *
 * - **Per condominio, non per esercizio**: lo storno passa anche su un esercizio chiuso e si registra nell'esercizio aperto
 *   (`StornaRegolazioneImmediataAction`), quindi una regolazione del 2025 resta da segnalare anche quando si lavora sul 2026.
 * - **Il tipo è quello di oggi** (`conti.tipo`): una voce può essere diventata un'entrata dopo la registrazione. La regola delle voci di spesa è
 *   `Conto::TIPI_DI_SPESA`.
 * - **Non stornata** vuol dire senza una figlia `storno_regolazione_immediata`: è il controllo con cui lo storno rifiuta il secondo storno.
 * - La regolazione scrive una sola riga DARE, quella con la voce: l'importo è il suo, in centesimi. Una voce cancellata dopo lascia la riga senza
 *   voce (`righe_scritture.voce_spesa_id` a null) e non si trova: non è più un'entrata.
 */
class RegolazioniSuVoceDiEntrata
{
    /**
     * Le regolazioni del condominio, dalla più vecchia: solo i campi che la lista delle fatture mostra.
     *
     * @return list<array{id: int, numero_protocollo: ?string, data: ?string, importo: int, voce: string, voce_id: int}>
     */
    public function delCondominio(int $condominioId): array
    {
        return ScritturaContabile::query()
            ->join('righe_scritture as riga', function ($join) {
                $join->on('riga.scrittura_id', '=', 'scritture_contabili.id')->where('riga.tipo_riga', 'dare');
            })
            ->join('conti as voce', 'voce.id', '=', 'riga.voce_spesa_id')
            ->where('scritture_contabili.condominio_id', $condominioId)
            ->where('scritture_contabili.tipo_movimento', TipoMovimentoContabile::REGOLAZIONE_IMMEDIATA->value)
            ->whereNotIn('voce.tipo', Conto::TIPI_DI_SPESA)
            ->whereDoesntHave('figlie', fn ($q) => $q->where('tipo_movimento', TipoMovimentoContabile::STORNO_REGOLAZIONE_IMMEDIATA->value))
            ->orderBy('scritture_contabili.data_competenza')
            ->orderBy('scritture_contabili.id')
            ->get([
                'scritture_contabili.id',
                'scritture_contabili.numero_protocollo',
                'scritture_contabili.data_competenza',
                'riga.importo as importo_riga',
                'voce.nome as nome_voce',
                'voce.id as id_voce',
            ])
            ->map(fn (ScritturaContabile $s) => [
                'id' => (int) $s->id,
                'numero_protocollo' => $s->numero_protocollo,
                'data' => $s->data_competenza?->format('d/m/Y'),
                'importo' => (int) $s->getAttribute('importo_riga'),
                'voce' => (string) $s->getAttribute('nome_voce'),
                // La voce si distingue per id: la lista dice su quante voci stanno le regolazioni, e due voci possono avere lo stesso nome.
                'voce_id' => (int) $s->getAttribute('id_voce'),
            ])
            ->values()
            ->all();
    }
}
