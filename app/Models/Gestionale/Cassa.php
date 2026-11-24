<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Gestionale;

use App\Models\ContoCorrente;
use App\Enums\TipoMovimentoContabile;
use App\Services\Gestionale\SaldoCassaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use App\Traits\RegistraLeModifiche;

class Cassa extends Model
{
    use RegistraLeModifiche;

    protected $table = 'casse';
    
    protected $fillable = [
        'condominio_id', 
        'nome', 
        'descrizione',
        'tipo', 
        'conto_contabile_id', 
        'saldo_iniziale',
        'attiva', 
        'note',
        // --- Nuovi campi Governance Fondi ---
        'sottotipo_fondo',
        'vincolo_descrizione',
        'is_override_assemblea',
        'motivazione_override'
    ];

    protected $casts = [
        'attiva' => 'boolean',
        'is_override_assemblea' => 'boolean',
    ];

    // Espone automaticamente gli attributi calcolati a Vue (Inertia)
    protected $appends = ['is_utilizzabile_per_imprevisti', 'saldo_reale'];

    /**
     * IL GUARDIANO DEL DOMINIO
     * Si assicura che il database resti sempre coerente, 
     * bloccando le forzature e pulendo i dati "sporchi".
     */
    protected static function booted()
    {
        static::saving(function (Cassa $cassa) {

            // 1. Se non è un fondo, puliamo tutta la logica di governance
            if ($cassa->tipo !== 'fondo') {
                $cassa->sottotipo_fondo = null;
                $cassa->vincolo_descrizione = null;
                $cassa->is_override_assemblea = false;
                $cassa->motivazione_override = null;
                return;
            }

            // 2. Default di sicurezza
            $cassa->sottotipo_fondo = $cassa->sottotipo_fondo ?? 'generico';

            // --- FIX QUI: Se NON è vincolato_lavori, svuotiamo la descrizione ---
            if ($cassa->sottotipo_fondo !== 'vincolato_lavori') {
                $cassa->vincolo_descrizione = null;
            }

            // 3. Regole per il Fondo Generico (Sempre libero)
            if ($cassa->sottotipo_fondo === 'generico') {
                $cassa->is_override_assemblea = false;
                $cassa->motivazione_override = null;
                $cassa->vincolo_descrizione = null;
                return;
            }

            // 4. Regola d'oro per i Fondi Vincolati (Enforcement)
            if ($cassa->is_override_assemblea) {
                if (empty(trim($cassa->motivazione_override))) {
                    throw ValidationException::withMessages([
                        'motivazione_override' => 'Motivazione obbligatoria per sbloccare l\'uso di un fondo vincolato.'
                    ]);
                }
            } else {
                // Se l'override è spento, la motivazione deve essere vuota
                $cassa->motivazione_override = null;
            }
        });
    }

    /**
     * RELAZIONI
     */
    public function movimenti(): HasMany
    {
        // Correttamente agganciato in Partita Doppia
        return $this->hasMany(RigaScrittura::class, 'conto_contabile_id', 'conto_contabile_id');
    }

    /**
     * Movimenti OPERATIVI: esclude la scrittura di apertura.
     *
     * L'apertura la genera il sistema (creazione cassa o backfill di aggiornamento),
     * non è un'operazione dell'amministratore: una cassa appena creata e mai usata
     * ne ha una, ma resta "vergine" dal suo punto di vista. Le guardie che vietano
     * di cambiare tipo o saldo di apertura devono guardare QUESTI movimenti,
     * altrimenti dopo la beta.25 nessuna cassa sarebbe più modificabile.
     */
    public function movimentiOperativi(): HasMany
    {
        return $this->movimenti()->whereHas(
            'scrittura',
            fn ($q) => $q->where('tipo_movimento', '!=', TipoMovimentoContabile::APERTURA->value)
        );
    }

    /** La cassa ha movimenti veri (non la sola apertura)? */
    public function hasMovimentiOperativi(): bool
    {
        return $this->movimentiOperativi()->exists();
    }

    /**
     * Perché questa risorsa non si può eliminare, o `null` se si può.
     *
     * Nasce nella beta.45 per chiudere la scorciatoia che l'interfaccia rendeva **conveniente**:
     * finché non è esistito un modo di registrare l'apertura, l'unico gesto che faceva tornare
     * verde il bollino dello Stato Patrimoniale era **eliminare la cassa**. E funzionava:
     * `casse` non ha `deleted_at`, quindi è una cancellazione vera; `righe_scritture.cassa_id`
     * è `nullOnDelete`, quindi le scritture restano bilanciate; e la liquidità non
     * contabilizzata spariva insieme alla riga. Il bollino diventava verde e **nessuno aveva
     * sistemato niente**.
     *
     * La forma è quella già collaudata da `FatturaPassiva::motivoBloccoEliminazione()` nella
     * beta.34: una guardia sola, che `destroy()` usa per decidere e l'elenco può usare per
     * spiegare, così le due non possono divergere. E ogni motivo nomina **la via d'uscita**:
     * un divieto muto è il difetto che quella beta era nata per togliere.
     */
    public function motivoBloccoEliminazione(): ?string
    {
        if ($this->hasMovimentiOperativi()) {
            return 'Questa risorsa ha movimenti contabili registrati: eliminandola le scritture '
                . 'resterebbero senza la loro cassa e il saldo non sarebbe più ricostruibile. '
                . 'Se non la usi più, disattivala invece di eliminarla.';
        }

        if ((int) $this->saldo_iniziale !== 0) {
            return 'Questa risorsa ha un saldo di apertura di '
                . \App\Helpers\MoneyHelper::format((int) $this->saldo_iniziale)
                . ' non ancora registrato in contabilità: eliminandola quella liquidità '
                . 'sparirebbe dallo Stato Patrimoniale senza lasciare traccia. Registra prima '
                . "l'apertura — dal Libro Giornale o risalvando la risorsa — poi valuta se "
                . 'eliminarla.';
        }

        // Coda 754 (1), decisione 696 (D3): eliminandola, la sua apertura si storna (`CassaController::destroy`). In un esercizio chiuso non si scrive.
        $inUnChiuso = $this->apertureValide()->first(fn (ScritturaContabile $a) => $a->esercizio?->stato === 'chiuso');
        if ($inUnChiuso) {
            return 'Questa risorsa ha la sua apertura di ' . \App\Helpers\MoneyHelper::format($this->importoDellApertura($inUnChiuso))
                . ' del ' . $inUnChiuso->data_competenza?->format('d/m/Y') . " nell'esercizio «" . $inUnChiuso->esercizio->nome . '», che è chiuso: '
                . "eliminandola, l'apertura andrebbe stornata in un esercizio chiuso. Se non la usi più, disattivala invece di eliminarla.";
        }

        // Rilievo 5 dello scettico della tappa 6 della 1.12.0-beta.14: un fondo che copre una fattura con il giroconto ancora da confermare (la pregressa
        // con «Usa fondo riserva», la spesa fuori preventivo dell'anno, lo sforo) non si elimina. Eliminato, «Giroconti» continuava a proporre la copertura
        // da un fondo senza più la sua cassa, e quella spesa non la copriva il fondo né la chiedeva un piano rate.
        $daConfermare = $this->copertureDaConfermare();
        if ($daConfermare->isNotEmpty()) {
            $perFattura = $daConfermare->groupBy('fattura_passiva_id')->map(function ($coperture) {
                $fattura = $coperture->first()->fattura;
                $fornitore = $fattura?->fornitore?->ragione_sociale;

                return 'la fattura n. ' . ($fattura?->numero_documento ?? 'S/N') . ($fornitore ? ' di ' . $fornitore : '')
                    . ' con ' . \App\Helpers\MoneyHelper::format((int) $coperture->sum('importo'));
            })->values();
            $elenco = $perFattura->count() > 1
                ? $perFattura->slice(0, -1)->implode(', ') . ' e ' . $perFattura->last()
                : $perFattura->first();

            return 'Questa risorsa copre ' . $elenco . ' ancora da confermare: '
                . ($daConfermare->count() > 1 ? 'conferma i giroconti' : 'conferma il giroconto')
                . ($perFattura->count() > 1 ? ' o storna le fatture' : ' o storna la fattura')
                . ' prima di eliminarla.';
        }

        return null;
    }

    /**
     * Le coperture dal fondo ancora da confermare che hanno questa risorsa come fondo: le stesse che «Giroconti» propone di confermare
     * (`GirocontoController::create`), cioè pianificate, maggiori di zero, di fatture del condominio non stornate.
     *
     * @return \Illuminate\Support\Collection<int, FatturaCopertura>
     */
    private function copertureDaConfermare(): \Illuminate\Support\Collection
    {
        if (! $this->conto_contabile_id) {
            return collect();
        }

        return FatturaCopertura::query()
            ->where('tipo_copertura', 'fondo_riserva')
            ->where('fondo_id', $this->conto_contabile_id)
            ->where('stato', 'pianificata')
            ->where('importo', '>', 0)
            ->whereHas('fattura', fn ($q) => $q->where('condominio_id', $this->condominio_id)->where('stato_pagamento', '!=', 'stornata'))
            ->with(['fattura:id,numero_documento,fornitore_id', 'fattura.fornitore:id,ragione_sociale'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Le scritture di apertura ancora valide sul conto della cassa (Coda 754 (1)): non annullate, e non lo storno di un'apertura (che ha il padre ed è
     * anch'esso di tipo `apertura`, così le letture che tengono l'apertura fuori dai flussi tengono fuori anche lui). Le guardie «una volta sola per
     * cassa» (`hasAperturaRegistrata`, `RegistraAperturaCassaAction::haGiaApertura`) usano le stesse condizioni; l'eliminazione le storna.
     *
     * @return \Illuminate\Support\Collection<int, ScritturaContabile>
     */
    public function apertureValide(): \Illuminate\Support\Collection
    {
        if (! $this->conto_contabile_id) {
            return collect();
        }

        return ScritturaContabile::query()
            ->where('tipo_movimento', TipoMovimentoContabile::APERTURA->value)
            ->whereNull('scrittura_padre_id')
            ->where(fn ($q) => $q->whereNull('stato')->orWhere('stato', '!=', 'annullata'))
            ->whereHas('righe', fn ($q) => $q->where('conto_contabile_id', $this->conto_contabile_id))
            ->with(['righe', 'esercizio'])
            ->orderBy('id')
            ->get();
    }

    /** L'importo di un'apertura sul conto della cassa, in centesimi: positivo in dare (la liquidità entra), negativo per un conto scoperto. */
    public function importoDellApertura(ScritturaContabile $apertura): int
    {
        return (int) $apertura->righe->where('conto_contabile_id', $this->conto_contabile_id)
            ->sum(fn (RigaScrittura $r) => $r->tipo_riga === 'dare' ? (int) $r->importo : -(int) $r->importo);
    }

    /**
     * La frase dello storno per la finestra di conferma dell'eliminazione (Coda 754 (1)), o `null` se la cassa non ha un'apertura a giornale.
     * Al futuro («si storna») per la finestra, al passato («è stornata») per il messaggio dopo.
     */
    public function fraseDelloStornoDellApertura(bool $fatto = false): ?string
    {
        $aperture = $this->apertureValide();
        if ($aperture->isEmpty()) {
            return null;
        }
        $parti = $aperture->map(fn (ScritturaContabile $a) => 'di ' . \App\Helpers\MoneyHelper::format($this->importoDellApertura($a))
            . ' del ' . $a->data_competenza?->format('d/m/Y'))->implode(' e ');

        return $aperture->count() === 1
            ? "La sua apertura {$parti} " . ($fatto ? 'è stornata' : 'si storna') . ' nel libro giornale: restano visibili tutte e due, e il conto archiviato torna a zero.'
            : "Le sue aperture {$parti} " . ($fatto ? 'sono stornate' : 'si stornano') . ' nel libro giornale: restano visibili tutte, e il conto archiviato torna a zero.';
    }

    /**
     * Il saldo di apertura è già stato portato a giornale
     * (RegistraAperturaCassaAction)? Da quel momento il dato è nella scrittura,
     * non più nella colonna: riscrivere la colonna lo conterebbe due volte in
     * SaldoCassaService (colonna + movimenti). A differenza di
     * hasMovimentiOperativi() — che riguarda il tipo di risorsa — questa guardia
     * riguarda SOLO il campo saldo_iniziale e scatta anche su una cassa "vergine"
     * dal punto di vista operativo, perché l'apertura da sola basta a spostare il
     * dato dalla colonna al giornale.
     */
    public function hasAperturaRegistrata(): bool
    {
        if (! $this->conto_contabile_id) {
            return false;
        }

        // Coda 754 (1): un'apertura annullata, e il suo storno, contano come assenti (come in `apertureValide`).
        return \Illuminate\Support\Facades\DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', 'rs.scrittura_id', '=', 'sc.id')
            ->where('rs.conto_contabile_id', $this->conto_contabile_id)
            ->where('sc.tipo_movimento', TipoMovimentoContabile::APERTURA->value)
            ->whereNull('sc.deleted_at')
            ->whereNull('sc.scrittura_padre_id')
            ->where(fn ($q) => $q->whereNull('sc.stato')->orWhere('sc.stato', '!=', 'annullata'))
            ->exists();
    }

    /**
     * Il condominio proprietario della cassa.
     *
     * Mancava, pur essendo già usata da CreateCassaBankAccountAction e
     * UpdateCassaAction (`$cassa->condominio->nome` come intestatario di default
     * del conto corrente): creare o modificare una cassa di tipo Banca senza
     * compilare "intestatario" — campo `nullable` in validazione — leggeva una
     * relazione inesistente e generava un errore.
     */
    public function condominio(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Condominio::class);
    }

    public function contoCorrente(): MorphOne
    {
        return $this->morphOne(ContoCorrente::class, 'contable');
    }

    public function contoContabile(): BelongsTo
    {
        return $this->belongsTo(ContoContabile::class);
    }

    /**
     * ACCESSOR: Flag dinamico del CFO Engine (Mai salvato su DB)
     */
    public function getIsUtilizzabilePerImprevistiAttribute(): bool
    {
        if ($this->tipo !== 'fondo') {
            return false;
        }

        // La regola in un posto solo: un fondo generico o sbloccato dall'assemblea si può usare.
        return \App\Services\Gestionale\TipoDelFondo::siPuoUsare($this->sottotipo_fondo, (bool) $this->is_override_assemblea);
    }

    /**
     * ACCESSOR: Calcolo del Saldo Reale in tempo reale (in centesimi).
     *
     * Delega a SaldoCassaService, fonte unica del saldo cassa. Prima della
     * beta.25 questo accessor replicava la formula per conto proprio SENZA
     * escludere le scritture annullate (soft-deleted), mentre il service le
     * escludeva: due numeri diversi per la stessa cassa a seconda di chi
     * chiedeva. Ora c'è un solo calcolo, che filtra le annullate.
     *
     * @see \App\Services\Gestionale\SaldoCassaService::saldoDisponibile()
     */
    public function getSaldoRealeAttribute(): int
    {
        return app(SaldoCassaService::class)->saldoDisponibile($this);
    }
}