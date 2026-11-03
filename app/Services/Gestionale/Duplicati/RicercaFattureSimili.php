<?php

namespace App\Services\Gestionale\Duplicati;

use App\Models\Gestionale\FatturaPassiva;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * «Questa fattura l'ho già registrata?» — chiesto **una volta sola**, e da tre posti.
 *
 * ## Perché è un servizio e non un metodo del controller
 *
 * La decisione **D4** (`docs/prima_nota_rapida.md`) nasce per la **prima nota rapida**, che al
 * 02/09/2026 non esiste ancora come codice ed è candidata alla 1.11.0 finale. I clienti di questa
 * regola sono quindi **tre**, e due non sono ancora nati:
 *
 *   1. il modulo di registrazione manuale (1.11.0-beta.13, questa);
 *   2. la lettura di un XML che compila il modulo (beta.14);
 *   3. la prima nota rapida (1.11.0 finale).
 *
 * Scritta dentro il componente o dentro il controller delle fatture, la regola «cosa conta come
 * stessa fattura» andrebbe riscritta due volte — e due copie della stessa regola divergono al
 * primo ritocco. È la lezione già pagata da `RicercaEsistenti`, il cui docblock la racconta per
 * esteso: la ricerca del duplicato viveva dentro il commit, l'anteprima non poteva farsi la stessa
 * domanda, e il risultato fu un vicolo cieco a video.
 *
 * ## I due livelli, e perché nessuno dei due blocca
 *
 * D4, alla lettera: *«Criteri duplicati: due livelli, non bloccanti. Forte: fornitore + numero
 * documento nello stesso esercizio. Standard: fornitore (o sottoconto) + importo lordo esatto al
 * centesimo + data ±7 giorni. Zero tolleranza sull'importo; ±7 giorni esclude le ricorrenze
 * mensili.»*
 *
 * - **Zero tolleranza sull'importo** non è pigrizia: i doppi inserimenti sono **copie esatte**, e
 *   il confronto approssimato sugli importi produce solo rumore.
 * - **±7 giorni** tiene fuori le **ricorrenze mensili legittime** — canoni e utenze a importo
 *   fisso — che con una finestra più larga suonerebbero come duplicati ogni mese.
 *
 * ⚠️ **La clausola «(o sottoconto)» di D4 è inerte su questa superficie**, e non è una
 * dimenticanza: esisteva perché in prima nota il fornitore è annullabile, mentre
 * `fatture_passive.fornitore_id` è `NOT NULL`. Il ramo tornerà vivo quando la prima nota userà
 * questo stesso servizio.
 *
 * ⚠️ **Questo servizio non blocca niente e non deve imparare a farlo.** Restituisce un elenco;
 * decidere è di chi chiama. Il precedente da non seguire ha un nome —
 * `PagamentoFornitoreService::rilevaDuplicato()`, che è modale bloccante con flag di override — e
 * la differenza non è di gusto: *un pagamento è un atto dell'amministratore, una fattura è un
 * fatto già accaduto*.
 */
final class RicercaFattureSimili
{
    /** Stesso fornitore e stesso numero documento nello stesso esercizio. Quasi certamente la stessa. */
    public const FORTE = 'forte';

    /** Stesso fornitore, importo identico al centesimo, data entro una settimana. Da guardare. */
    public const STANDARD = 'standard';

    /** La finestra di D4, in giorni. Fissa: «niente config UI finché il beta non la chiede». */
    public const GIORNI_FINESTRA = 7;

    /**
     * @param  int|null  $escludiFatturaId  la fattura che si sta modificando, che non deve segnalarsi da sola
     * @return Collection<int, FatturaSimile>
     */
    public function cerca(
        int $condominioId,
        int $esercizioId,
        int $fornitoreId,
        ?string $numeroDocumento,
        ?int $totaleDocumentoCents,
        ?string $dataDocumento,
        string $tipoDocumento = 'fattura',
        ?int $escludiFatturaId = null,
    ): Collection {
        $forti = $this->forte(
            $condominioId, $esercizioId, $fornitoreId, $numeroDocumento, $tipoDocumento, $escludiFatturaId
        );

        $standard = $this->standard(
            $condominioId, $fornitoreId, $totaleDocumentoCents, $dataDocumento, $tipoDocumento, $escludiFatturaId
        );

        // ⚠️ Una fattura che casca in tutti e due i livelli si mostra **una volta sola**, col
        // motivo più forte. Mostrarla due volte farebbe leggere due sospetti dove ce n'è uno.
        $giaViste = $forti->pluck('id')->all();

        return $forti->concat($standard->reject(fn (FatturaSimile $f) => in_array($f->id, $giaViste, true)))
            ->values();
    }

    /** @return Collection<int, FatturaSimile> */
    private function forte(
        int $condominioId,
        int $esercizioId,
        int $fornitoreId,
        ?string $numeroDocumento,
        string $tipoDocumento,
        ?int $escludiFatturaId,
    ): Collection {
        $numero = trim((string) $numeroDocumento);

        if ($numero === '') {
            return collect();
        }

        return $this->base($condominioId, $fornitoreId, $tipoDocumento, $escludiFatturaId)
            // D4 àncora il livello forte all'**esercizio**: lo stesso numero l'anno dopo è una
            // fattura diversa, e molti fornitori ricominciano la numerazione a gennaio.
            ->where('esercizio_id', $esercizioId)
            ->whereRaw('LOWER(TRIM(numero_documento)) = ?', [mb_strtolower($numero)])
            ->get()
            ->map(fn (FatturaPassiva $f) => FatturaSimile::da($f, self::FORTE));
    }

    /** @return Collection<int, FatturaSimile> */
    private function standard(
        int $condominioId,
        int $fornitoreId,
        ?int $totaleDocumentoCents,
        ?string $dataDocumento,
        string $tipoDocumento,
        ?int $escludiFatturaId,
    ): Collection {
        if ($totaleDocumentoCents === null || $totaleDocumentoCents <= 0 || ! $dataDocumento) {
            return collect();
        }

        try {
            $data = Carbon::parse($dataDocumento);
        } catch (\Throwable) {
            // La data arriva da un modulo in compilazione: mezza data non è un errore, è uno stato
            // di passaggio. Nessun sospetto, nessuna eccezione in faccia a chi sta scrivendo.
            return collect();
        }

        return $this->base($condominioId, $fornitoreId, $tipoDocumento, $escludiFatturaId)
            // ⚠️ Confronto **intero contro intero**: `totale_documento` è un `bigint` in centesimi.
            // Nessuna conversione, nessun arrotondamento — «zero tolleranza» di D4 è letterale.
            //
            // ⚠️ **Il segno, non solo il valore.** `FatturaPassivaService::registraFattura()`
            // salva `totale_documento` moltiplicato per -1 sulle note di credito (il
            // moltiplicatore di riga 176 del service). I due chiamanti di questo metodo — il
            // form e il confronto con la fattura aperta — mandano sempre il **valore assoluto**
            // digitato a video (`totali.ts` fa `imponibile + iva` senza segno, ed Edit applica
            // `Math.abs()` in più): per una NC il confronto va quindi fatto contro il negativo,
            // o non trova mai niente. Provato dalla revisione avversariale della beta.13: senza
            // questa riga il livello STANDARD è muto su ogni nota di credito.
            ->where('totale_documento', $tipoDocumento === 'nota_credito' ? -$totaleDocumentoCents : $totaleDocumentoCents)
            // La finestra **non** è ancorata all'esercizio: sette giorni a cavallo di capodanno
            // restano sette giorni, e una fattura del 28/12 duplicata il 3/1 è lo stesso sbaglio.
            ->whereBetween('data_documento', [
                $data->copy()->subDays(self::GIORNI_FINESTRA)->toDateString(),
                $data->copy()->addDays(self::GIORNI_FINESTRA)->toDateString(),
            ])
            ->get()
            ->map(fn (FatturaPassiva $f) => FatturaSimile::da($f, self::STANDARD));
    }

    /** Il perimetro comune ai due livelli: cosa non conta mai come duplicato. */
    private function base(
        int $condominioId,
        int $fornitoreId,
        string $tipoDocumento,
        ?int $escludiFatturaId,
    ): Builder {
        $q = FatturaPassiva::query()
            // ⚠️ Sempre per condominio. `fornitori` non ha `condominio_id` — un fornitore è
            // condiviso fra i palazzi dello stesso studio (misurato: il fornitore 10 su quattro
            // condomìni) — quindi una ricerca sul solo `fornitore_id` mostrerebbe numero, data e
            // importo delle fatture di **un altro condominio**.
            ->where('condominio_id', $condominioId)
            ->where('fornitore_id', $fornitoreId)
            // Una nota di credito con lo stesso numero di una fattura non è un doppione: sono due
            // registri con segno opposto. Si confronta sempre lo stesso tipo.
            ->where('tipo_documento', $tipoDocumento);

        // ⚠️⚠️ **Le stornate si escludono così, e la forma ovvia non funziona.**
        // `where('dati_extra->is_stornata', '!=', true)` restituisce **zero** righe su trenta,
        // perché in SQL un confronto su una chiave JSON assente dà NULL, e NULL non è "diverso da
        // true": è sconosciuto. Il filtro ingenuo non sbaglia il risultato, **spegne la ricerca in
        // silenzio** — misurato il 02/09/2026, ed è presidiato da un test apposta.
        $q->where(function (Builder $sub) {
            $sub->whereNull('dati_extra->is_stornata')
                ->orWhere('dati_extra->is_stornata', false);
        });

        // In modifica la fattura aperta si segnalerebbe da sé all'apertura della pagina.
        if ($escludiFatturaId !== null) {
            $q->whereKeyNot($escludiFatturaId);
        }

        // ⚠️ Le **pregresse non si escludono**, ed è deliberato: sono il caso a più alto rischio di
        // doppio inserimento, perché si caricano a mano in blocco riprendendo da dove si era
        // rimasti. Il «Radar Anti-Duplicati» che esisteva prima di questa beta guardava *solo*
        // quelle, ed è il segnale che il rischio era già noto.

        return $q->orderByDesc('data_documento')->limit(5);
    }
}
