<?php

namespace App\Actions\Gestionale\Movimenti;

use App\Enums\TipoMovimentoContabile;
use App\Exceptions\Gestionale\GirocontoNonAmmessoException;
use App\Helpers\MoneyHelper;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\FatturaCopertura;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\DoubleEntryValidator;
use App\Services\Gestionale\SaldoCassaService;
use Illuminate\Support\Facades\DB;

/**
 * Registrazione di un giroconto — spostamento di liquidità fra casse dello
 * stesso condominio, senza movimento di denaro reale.
 *
 * I fondi sono partizioni contabili dell'unico conto corrente: un giroconto
 * banca → fondo accantona, fondo → banca libera (ed è l'atto che conferma una
 * copertura fattura), fondo → fondo ridestina. La scrittura è la più semplice
 * del giornale:
 *
 *   DARE   conto contabile della cassa di DESTINAZIONE   importo
 *   AVERE  conto contabile della cassa di ORIGINE        importo
 *
 * Convenzione unica (attivo, come tutte le casse): DARE aumenta, AVERE diminuisce.
 * La liquidità complessiva del condominio resta invariata per costruzione.
 *
 * Se il giroconto conferma una copertura fattura (fattura_copertura_id), nella
 * stessa transazione la copertura passa a 'confermata' e viene agganciata alla
 * scrittura: da quel momento il fondo è contabilmente consumato e la fattura
 * può essere pagata con il flusso ordinario dalla banca.
 *
 * Architettura: non è una nuova entità. È la ScritturaContabile che esiste già
 * (tipo_movimento 'giroconto', previsto nell'enum fin dal primo giorno), stesso
 * paradigma della Regolazione Immediata.
 */
class RegistraGirocontoAction
{
    public function __construct(
        private readonly SaldoCassaService $saldi,
    ) {}

    public function execute(array $validated, Condominio $condominio, Esercizio $esercizio): ScritturaContabile
    {
        $importoCents = MoneyHelper::toCents($validated['importo']);

        if ($importoCents <= 0) {
            throw new GirocontoNonAmmessoException(
                'L\'importo di un giroconto deve essere positivo.'
            );
        }

        // ── Casse: esistenza, appartenenza, coerenza ──────────────────────────
        $origine = Cassa::with('contoContabile')->findOrFail($validated['cassa_origine_id']);
        $destinazione = Cassa::with('contoContabile')->findOrFail($validated['cassa_destinazione_id']);

        if ($origine->id === $destinazione->id) {
            throw new GirocontoNonAmmessoException(
                'Origine e destinazione coincidono: un giroconto sposta liquidità fra due casse diverse.'
            );
        }

        foreach ([$origine, $destinazione] as $cassa) {
            if ($cassa->condominio_id !== $condominio->id) {
                throw new GirocontoNonAmmessoException(
                    "La cassa «{$cassa->nome}» non appartiene a questo condominio."
                );
            }
            if (! $cassa->attiva) {
                throw new GirocontoNonAmmessoException(
                    "La cassa «{$cassa->nome}» non è attiva."
                );
            }
            if (! $cassa->contoContabile) {
                throw new GirocontoNonAmmessoException(
                    "La cassa «{$cassa->nome}» non ha un conto contabile associato."
                );
            }
        }

        // ── Coppie di tipi: la liquidità di un fondo vive sul c/c ────────────
        // fondo ↔ banca e fondo ↔ fondo hanno senso; fondo ↔ contanti/virtuale no
        // (il prelievo contante passa da fondo → banca → contanti, due movimenti).
        $tipi = [$origine->tipo, $destinazione->tipo];
        if (in_array('fondo', $tipi, true)) {
            $altro = $origine->tipo === 'fondo' ? $destinazione->tipo : $origine->tipo;
            if (! in_array($altro, ['banca', 'fondo'], true)) {
                throw new GirocontoNonAmmessoException(
                    'Un giroconto che coinvolge un fondo può avvenire solo con il conto corrente '
                    .'o con un altro fondo: la liquidità del fondo vive sul conto corrente reale.'
                );
            }
        }

        // ── Governance fondo vincolato (origine) ─────────────────────────────
        // Il vincolo di destinazione prevale: da un fondo vincolato si esce solo
        // con deroga assembleare. Nessun vincolo sul fondo in INGRESSO.
        if ($origine->tipo === 'fondo' && ! $origine->is_utilizzabile_per_imprevisti) {
            throw new GirocontoNonAmmessoException(
                "Il fondo «{$origine->nome}» è vincolato ({$origine->sottotipo_fondo}): "
                .'per utilizzarlo fuori dalla sua destinazione serve la deroga assembleare '
                .'(attivabile dalla scheda della cassa, con motivazione).'
            );
        }

        // ── Capienza origine: bloccante, senza override ───────────────────────
        // Un fondo negativo è denaro accantonato inesistente; una banca negativa
        // per un accantonamento è un non-senso. (Il pagamento fornitore ha
        // allow_overdraft perché è un obbligo verso terzi: qui non lo replichiamo.)
        $saldoOrigine = $this->saldi->saldoDisponibile($origine);
        if ($saldoOrigine < $importoCents) {
            throw new GirocontoNonAmmessoException(sprintf(
                'Capienza insufficiente sulla cassa «%s»: disponibili %s, richiesti %s.',
                $origine->nome,
                MoneyHelper::format($saldoOrigine),
                MoneyHelper::format($importoCents)
            ));
        }

        // ── Copertura collegata (conferma sforo) ─────────────────────────────
        $copertura = null;
        if (! empty($validated['fattura_copertura_id'])) {
            $copertura = FatturaCopertura::with('fattura')->findOrFail($validated['fattura_copertura_id']);

            if (! $copertura->fattura || $copertura->fattura->condominio_id !== $condominio->id) {
                throw new GirocontoNonAmmessoException(
                    'La copertura selezionata non appartiene a questo condominio.'
                );
            }
            if ($copertura->fattura->stato_pagamento === \App\Enums\StatoPagamentoFattura::STORNATA
                || ($copertura->fattura->dati_extra['is_stornata'] ?? false)) {
                throw new GirocontoNonAmmessoException(
                    'La fattura di questa copertura è stata stornata: il debito non esiste più, '
                    .'non c\'è nulla da coprire con il fondo.'
                );
            }
            if ($copertura->tipo_copertura !== 'fondo_riserva') {
                throw new GirocontoNonAmmessoException(
                    'Solo le coperture da fondo di riserva si confermano con un giroconto.'
                );
            }
            if ($copertura->stato !== 'pianificata') {
                throw new GirocontoNonAmmessoException(
                    'La copertura selezionata è già stata confermata.'
                );
            }
            if ($copertura->importo <= 0) {
                throw new GirocontoNonAmmessoException(
                    'La copertura selezionata ha importo nullo o negativo (fattura stornata?): niente da confermare.'
                );
            }
            if ($copertura->fondo_id !== $origine->conto_contabile_id) {
                throw new GirocontoNonAmmessoException(
                    'La cassa di origine non è il fondo indicato dalla copertura: '
                    .'il giroconto di conferma deve uscire dal fondo che copre lo sforo.'
                );
            }
            if ($destinazione->tipo !== 'banca') {
                throw new GirocontoNonAmmessoException(
                    'La conferma di una copertura libera liquidità verso il conto corrente: '
                    .'la destinazione deve essere una cassa di tipo banca.'
                );
            }
            if ($copertura->importo !== $importoCents) {
                throw new GirocontoNonAmmessoException(sprintf(
                    'La conferma è solo integrale: la copertura vale %s, il giroconto %s.',
                    MoneyHelper::format($copertura->importo),
                    MoneyHelper::format($importoCents)
                ));
            }
        }

        return DB::transaction(function () use ($validated, $condominio, $esercizio, $importoCents, $origine, $destinazione, $copertura) {

            // Idempotenza: doppio click o retry di rete non producono due giroconti.
            // Il lookup è SCOPATO su condominio e tipo: la colonna è UNIQUE globale e
            // ospita anche le key dei pagamenti — senza scoping, una key riciclata
            // restituirebbe come "giroconto" una scrittura estranea.
            if (! empty($validated['idempotency_key'])) {
                $esistente = ScritturaContabile::where('idempotency_key', $validated['idempotency_key'])
                    ->where('condominio_id', $condominio->id)
                    ->where('tipo_movimento', TipoMovimentoContabile::GIROCONTO->value)
                    ->first();
                if ($esistente) {
                    return $esistente;
                }
            }

            $scrittura = ScritturaContabile::create([
                'condominio_id' => $condominio->id,
                'esercizio_id' => $esercizio->id,
                'gestione_id' => $validated['gestione_id'],
                'data_registrazione' => now()->toDateString(),
                'data_competenza' => $validated['data_operazione'],
                'causale' => mb_substr($validated['causale'], 0, 255),
                'tipo_movimento' => TipoMovimentoContabile::GIROCONTO,
                'stato' => 'registrata',
                'created_by' => auth()->id(),
                'idempotency_key' => $validated['idempotency_key'] ?? null,
            ]);

            $scrittura->righe()->create([
                'conto_contabile_id' => $destinazione->contoContabile->id,
                'cassa_id' => $destinazione->id,
                'tipo_riga' => 'dare',
                'importo' => $importoCents,
                'note' => 'Giroconto in entrata — '.$destinazione->nome,
            ]);

            $scrittura->righe()->create([
                'conto_contabile_id' => $origine->contoContabile->id,
                'cassa_id' => $origine->id,
                'tipo_riga' => 'avere',
                'importo' => $importoCents,
                'note' => 'Giroconto in uscita — '.$origine->nome,
            ]);

            DoubleEntryValidator::validateOrFail($scrittura->id);

            // La copertura diventa reale nella stessa transazione: o entrambe
            // le cose accadono, o nessuna.
            if ($copertura) {
                $copertura->update([
                    'stato' => 'confermata',
                    'scrittura_giroconto_id' => $scrittura->id,
                    'confermata_at' => now(),
                ]);
            }

            return $scrittura;
        });
    }
}
