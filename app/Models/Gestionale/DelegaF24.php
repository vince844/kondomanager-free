<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Gestionale;

use App\Enums\Fiscale\PlafondRitenuta;
use App\Enums\Fiscale\StatoDelegaF24;
use App\Enums\StatoPagamentoFornitore;
use App\Enums\TipoAllocazioneFattura;
use App\Helpers\DateHelper;
use App\Helpers\MoneyHelper;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Una delega di versamento F24: il documento con cui il condominio versa all'Erario le
 * ritenute d'acconto che ha operato pagando i fornitori.
 *
 * È il pezzo che mancava per **chiudere il conto 2202**. Fino alla beta.37 quel conto
 * accumulava soltanto: verificato a database, `dare = 0` su tutti i condomìni: le ritenute
 * ci entravano e non ne uscivano mai, perché nel gestionale non esisteva il movimento che
 * le versa.
 *
 * ## Cosa NON è
 *
 * Non è il tracciato telematico (record A/M/V) e non fa compensazione di crediti: quelli
 * restano la Fase 6 del design, marcata «oltre».
 *
 * La **stampa del modulo cartaceo** invece esiste dalla beta.39, e non è arrivata con la Fase
 * 6: il design la elencava lì insieme al tracciato, ma guardando il modulo pubblicato
 * dall'Agenzia si è visto che i due non hanno gli stessi prerequisiti — il foglio non chiede
 * nessuno dei campi anagrafico-fiscali rimandati alla v1.11. Vedi `ModelloF24Service`.
 *
 * La delega resta comunque anche il documento *interno*: raccoglie le ritenute da versare, le
 * raggruppa per codice tributo e periodo, e produce il prospetto con i campi che
 * l'amministratore trascrive nell'home banking o su F24 online.
 *
 * ## Il sigillo
 *
 * Dallo stato `versata` la delega non si rettifica: si storna. È la regola non negoziabile
 * del design, e la ragione non è tecnica — un versamento all'Erario è un fatto accaduto, e
 * modificarlo a posteriori significherebbe raccontarlo diversamente da come l'Agenzia lo ha
 * registrato.
 */
class DelegaF24 extends Model
{
    protected $table = 'deleghe_f24';

    protected $guarded = ['id'];

    protected $casts = [
        'condominio_id' => 'integer',
        'esercizio_id' => 'integer',
        'stato' => StatoDelegaF24::class,
        'plafond' => PlafondRitenuta::class,
        'data_scadenza' => 'date',
        'data_versamento' => 'date',
        'data_quietanza' => 'date',
        'totale_debito' => 'integer',
        'saldo' => 'integer',
    ];

    // ── Relazioni ────────────────────────────────────────────────────────────

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function esercizio(): BelongsTo
    {
        return $this->belongsTo(Esercizio::class);
    }

    public function righe(): HasMany
    {
        return $this->hasMany(RigaF24::class, 'delega_f24_id')->orderBy('ordine');
    }

    public function scrittura(): BelongsTo
    {
        return $this->belongsTo(ScritturaContabile::class, 'scrittura_contabile_id');
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'delega_padre_id');
    }

    public function figlie(): HasMany
    {
        return $this->hasMany(self::class, 'delega_padre_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Scope ────────────────────────────────────────────────────────────────

    public function scopeDelCondominio(Builder $query, int $condominioId): Builder
    {
        return $query->where('condominio_id', $condominioId);
    }

    /** Le deleghe che pesano ancora: non versate, non annullate. */
    public function scopeAperte(Builder $query): Builder
    {
        return $query->whereIn('stato', [
            StatoDelegaF24::BOZZA->value,
            StatoDelegaF24::CONFERMATA->value,
        ]);
    }

    /**
     * Le deleghe aperte già scadute: la scadenza è **prima di oggi**, nel giorno dell'amministratore (Coda 423).
     *
     * Il giorno stesso della scadenza si può ancora versare, quindi non è «Scaduta». Una regola sola per il filtro e il contatore
     * «Scadute» di «Ritenute e F24» (`DelegaF24Controller`) e per {@see isScaduta()}: fino alla 1.12.0-beta.13 il filtro confrontava la
     * data con `now()` del server (il giorno stesso no) e il contatore usava `isPast()` (il giorno stesso alle 10:00 sì), e il
     * 18/01/2027 il contatore diceva 1 mentre il filtro non trovava niente. L'oggi è quello di Roma (`DateHelper::oggiUtente()`):
     * alle 00:30 del 19/01 il server in UTC è ancora al 18.
     */
    public function scopeScadute(Builder $query): Builder
    {
        return $query->aperte()->whereDate('data_scadenza', '<', DateHelper::oggiUtente());
    }

    // ── Stato ────────────────────────────────────────────────────────────────

    /** Aperta e con la scadenza prima di oggi, nel giorno dell'amministratore: la stessa regola di {@see scopeScadute()}. */
    public function isScaduta(): bool
    {
        return in_array($this->stato, [StatoDelegaF24::BOZZA, StatoDelegaF24::CONFERMATA], true)
            && $this->data_scadenza !== null
            && $this->data_scadenza->toDateString() < DateHelper::oggiUtente();
    }

    /**
     * Il codice fiscale del contribuente di questa delega: un punto solo per la scheda e per la stampa del modello (Coda 754 (10)).
     *
     * Quello scritto sulla delega alla sua nascita, se c'è (`filled`, non `??`: una stringa vuota non conta); altrimenti quello di
     * oggi del condominio. Una bozza nata prima che il condominio avesse il codice fiscale lo trova così senza essere rifatta.
     */
    public function codiceFiscaleDelContribuente(): ?string
    {
        if (filled($this->cf_contribuente)) {
            return $this->cf_contribuente;
        }

        $codice = $this->condominio?->codice_fiscale;

        return filled($codice) ? $codice : null;
    }

    public function isVersata(): bool
    {
        return $this->stato === StatoDelegaF24::VERSATA;
    }

    public function isModificabile(): bool
    {
        return $this->stato->isModificabile();
    }

    /**
     * Il motivo per cui questa delega non si può modificare, o `null` se si può.
     *
     * Stesso schema di `FatturaPassiva::motivoBloccoEliminazione()`: una sola funzione che
     * decide *e* spiega, usata sia dalla guardia sia dall'interfaccia, così le due non
     * possono divergere. È la lezione della beta.34 — il divieto muto.
     */
    public function motivoBloccoModifica(): ?string
    {
        return match ($this->stato) {
            StatoDelegaF24::VERSATA => 'La delega è già stata versata: per correggerla va stornata.',
            StatoDelegaF24::STORNATA => 'La delega è stata stornata: non è più modificabile.',
            StatoDelegaF24::ANNULLATA => 'La delega è stata annullata.',
            StatoDelegaF24::CONFERMATA => 'La delega è confermata: riportala in bozza per modificarla.',
            StatoDelegaF24::BOZZA => null,
        };
    }

    /**
     * Perché il pagamento non si storna o non si modifica nella data o nella ritenuta, o `null` se si può (Coda 754 (10)).
     *
     * Una delega **confermata** resta com'è anche dopo «Aggiorna scadenze» (decisione 696, D6): se il pagamento che la riempie si stornasse,
     * la confermata terrebbe la ritenuta di un pagamento che non c'è più, e il pagamento registrato di nuovo entrerebbe in una bozza nuova,
     * con la stessa ritenuta chiesta due volte all'Erario. Il programma non la riporta in bozza da sé: lo dice, e decide l'amministratore.
     * Una bozza invece non ferma niente: è una proposta, e «Aggiorna scadenze» la rifà.
     *
     * Una delega **versata** non ferma lo storno né la modifica (decisione 704): la ritenuta già versata può essere un credito verso
     * l'Erario (il bonifico al fornitore respinto) o lo stesso versamento del pagamento rifatto, e lo storno del versamento scriverebbe che
     * l'Erario ha reso il denaro. Il programma avvisa: {@see avvisoRitenutaGiaVersata()} allo storno, {@see avvisiRitenuteGiaVersate()}
     * sulla delega nuova.
     *
     * @param  string  $poi  che cosa l'amministratore fa dopo averla riportata in bozza: «storna il pagamento», «modifica il pagamento»
     */
    public static function motivoBloccoDelPagamento(int $pagamentoId, string $poi): ?string
    {
        $confermata = self::query()
            ->where('stato', StatoDelegaF24::CONFERMATA->value)
            ->whereHas('righe.pagamenti', fn (Builder $q) => $q->where('pagamenti_fornitori.id', $pagamentoId))
            ->orderBy('data_scadenza')
            ->first();

        if ($confermata === null) {
            return null;
        }

        return sprintf(
            'La ritenuta di questo pagamento è nella delega F24 confermata in scadenza il %s: riportala in bozza, poi %s.',
            $confermata->data_scadenza->format('d/m/Y'),
            $poi,
        );
    }

    /**
     * L'avviso dello storno di un pagamento la cui ritenuta è già stata versata, o `null` (Coda 754 (10), decisione 704).
     *
     * Lo storno passa come prima: la delega versata resta, e il pagamento rifatto porterà la sua ritenuta in una delega nuova. Se è la
     * stessa ritenuta (la data del pagamento sbagliata), versarla di nuovo la darebbe due volte all'Erario; se il bonifico al fornitore
     * è tornato indietro, quella versata è un credito da recuperare. Il programma non sceglie: lo dice, con la cifra e la data.
     */
    public static function avvisoRitenutaGiaVersata(int $pagamentoId): ?string
    {
        $versata = self::query()
            ->where('stato', StatoDelegaF24::VERSATA->value)
            ->whereHas('righe.pagamenti', fn (Builder $q) => $q->where('pagamenti_fornitori.id', $pagamentoId))
            ->orderByDesc('data_versamento')
            ->first();

        if ($versata === null) {
            return null;
        }

        $ritenuta = (int) DB::table('riga_f24_pagamento')
            ->join('righe_f24', 'righe_f24.id', '=', 'riga_f24_pagamento.riga_f24_id')
            ->where('righe_f24.delega_f24_id', $versata->id)
            ->where('riga_f24_pagamento.pagamento_fornitore_id', $pagamentoId)
            ->sum('riga_f24_pagamento.importo');

        return sprintf(
            'La ritenuta di questo pagamento (%s) è già stata versata con la delega F24 del %s: quando rifai il pagamento, non versarla di nuovo.',
            MoneyHelper::format($ritenuta),
            ($versata->data_versamento ?? $versata->data_scadenza)->format('d/m/Y'),
        );
    }

    /**
     * Gli avvisi di una delega da versare che porta la ritenuta di una fattura già versata con un'altra delega, per un pagamento poi
     * stornato (Coda 754 (10), decisione 704): uno per fattura e delega versata, nella scheda e nello scadenzario.
     *
     * È il seguito di {@see avvisoRitenutaGiaVersata()}: il pagamento rifatto entra in una bozza nuova con la sua ritenuta, e la delega
     * lo deve dire finché non è versata. Solo per le deleghe in bozza o confermate: una versata, stornata o annullata non ha più niente
     * da versare.
     *
     * @return list<string>
     */
    public function avvisiRitenuteGiaVersate(): array
    {
        if (! in_array($this->stato, [StatoDelegaF24::BOZZA, StatoDelegaF24::CONFERMATA], true)) {
            return [];
        }

        $pagamento = TipoAllocazioneFattura::PAGAMENTO->value;

        return DB::table('righe_f24 as r')
            ->join('riga_f24_pagamento as rp', 'rp.riga_f24_id', '=', 'r.id')
            ->join('pagamenti_fornitori as p', 'p.id', '=', 'rp.pagamento_fornitore_id')
            // Le fatture del pagamento di questa delega.
            ->join('fattura_scrittura as fs', fn ($j) => $j->on('fs.scrittura_contabile_id', '=', 'p.scrittura_contabile_id')
                ->where('fs.tipo', $pagamento)->where('fs.importo_allocato', '>', 0))
            // Un altro pagamento della stessa fattura, stornato, con la ritenuta in una delega versata.
            ->join('fattura_scrittura as fs2', fn ($j) => $j->on('fs2.fattura_passiva_id', '=', 'fs.fattura_passiva_id')
                ->where('fs2.tipo', $pagamento)->where('fs2.importo_allocato', '>', 0))
            ->join('pagamenti_fornitori as p2', fn ($j) => $j->on('p2.scrittura_contabile_id', '=', 'fs2.scrittura_contabile_id')
                ->on('p2.id', '!=', 'p.id')->whereNull('p2.pagamento_padre_id'))
            ->join('riga_f24_pagamento as rp2', 'rp2.pagamento_fornitore_id', '=', 'p2.id')
            ->join('righe_f24 as r2', 'r2.id', '=', 'rp2.riga_f24_id')
            ->join('deleghe_f24 as v', 'v.id', '=', 'r2.delega_f24_id')
            ->join('fatture_passive as f', 'f.id', '=', 'fs.fattura_passiva_id')
            ->leftJoin('fornitori as fo', 'fo.id', '=', 'f.fornitore_id')
            ->where('r.delega_f24_id', $this->id)
            ->where('p2.stato', StatoPagamentoFornitore::STORNATO->value)
            ->where('v.stato', StatoDelegaF24::VERSATA->value)
            ->select(['f.id as fattura_id', 'f.numero_documento', 'fo.ragione_sociale', 'v.id as versata_id', 'v.data_versamento', 'v.data_scadenza'])
            ->distinct()
            ->orderBy('v.data_versamento')
            ->orderBy('f.id')
            ->get()
            ->map(fn ($riga) => sprintf(
                'La ritenuta della fattura n. %s di %s è già stata versata con la delega del %s (il pagamento è stato poi stornato): non versarla di nuovo.',
                $riga->numero_documento ?? '—',
                $riga->ragione_sociale ?? 'fornitore',
                Carbon::parse($riga->data_versamento ?? $riga->data_scadenza)->format('d/m/Y'),
            ))
            ->values()
            ->all();
    }

    /**
     * Perché questa delega non si versa né si conferma: porta la ritenuta di un pagamento stornato, o `null` (Coda 754 (10)).
     *
     * Versata, darebbe all'Erario una ritenuta che lo storno ha tolto; e il pagamento registrato di nuovo la porterebbe un'altra volta in
     * una delega nuova. La frase dice il pagamento e che cosa fare: «Aggiorna scadenze» rifà le deleghe con le sole ritenute da versare
     * (una confermata prima si riporta in bozza, perché il pulsante non la tocca).
     */
    public function motivoBloccoPerPagamentiStornati(): ?string
    {
        $stornati = PagamentoFornitore::query()
            ->with('fornitore')
            ->join('riga_f24_pagamento', 'riga_f24_pagamento.pagamento_fornitore_id', '=', 'pagamenti_fornitori.id')
            ->join('righe_f24', 'righe_f24.id', '=', 'riga_f24_pagamento.riga_f24_id')
            ->where('righe_f24.delega_f24_id', $this->id)
            ->where('pagamenti_fornitori.stato', StatoPagamentoFornitore::STORNATO->value)
            ->orderBy('pagamenti_fornitori.data_pagamento')
            ->orderBy('pagamenti_fornitori.id')
            ->get(['pagamenti_fornitori.*', 'riga_f24_pagamento.importo as ritenuta_nella_delega']);

        if ($stornati->isEmpty()) {
            return null;
        }

        $quale = fn (PagamentoFornitore $p) => sprintf(
            '%s del %s',
            $p->fornitore?->ragione_sociale ?? ($p->fornitore_snapshot['ragione_sociale'] ?? 'fornitore'),
            $p->data_pagamento?->format('d/m/Y') ?? '—',
        );
        $totale = (int) $stornati->sum(fn ($p) => (int) $p->ritenuta_nella_delega);

        $fatto = $stornati->count() === 1
            ? sprintf('Nella delega c\'è la ritenuta di %s del pagamento a %s, che è stato stornato.', MoneyHelper::format($totale), $quale($stornati->first()))
            : sprintf('Nella delega ci sono le ritenute di %d pagamenti stornati, per %s (%s).', $stornati->count(), MoneyHelper::format($totale),
                $stornati->map($quale)->implode(', '));

        return $fatto.($this->stato === StatoDelegaF24::CONFERMATA
            ? ' Riportala in bozza e premi «Aggiorna scadenze»: rifà le deleghe con le sole ritenute da versare.'
            : ' Premi «Aggiorna scadenze»: rifà le deleghe con le sole ritenute da versare.');
    }
}
