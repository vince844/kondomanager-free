<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Gestionale;

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Traits\RegistraLeModifiche;

/**
 * L'accordo di rientro di una persona: la data, le rate coperte con l'importo riconosciuto di ognuna, il calendario (le rate dell'accordo, con il
 * giorno e l'importo; la somma è il debito riconosciuto), «fino al» (il giorno dell'ultima rata del calendario), la regola di decadenza scelta
 * dall'amministratore, «autorizzato dall'assemblea» (sì o no, con la data e il verbale se sì), una nota, la scansione firmata (un `Documento`
 * dell'accordo, che non entra nell'archivio né nel portale); poi la chiusura, che può essere la decadenza registrata. Non si cancella: si chiude.
 *
 * Sulla parte di una rata che l'accordo non copre gli interessi continuano, o restano sospesi finché l'accordo regge: lo sceglie l'amministratore,
 * accordo per accordo (`interessi_parte_fuori`, decisione 450), alla registrazione o dopo, dalla scheda; il registro dell'accordo (`registro`) tiene ogni
 * cambio. Chiuso da una successione, l'accordo dice che cosa vale per gli interessi del defunto (`chiusura_per_successione`).
 *
 * Non sposta denaro: le rate coperte restano scadute (il debito originario resta a giornale), e le lettere proposte lasciano fuori la parte
 * riconosciuta. Il programma segnala quando la regola di decadenza si verifica e conserva il giorno; la decadenza la registra l'amministratore.
 * Non è la dispensa dei sei mesi: sono due delibere diverse. Gli accordi registrati senza calendario restano senza.
 */
class AccordoRientro extends Model
{
    use RegistraLeModifiche;

    protected $table = 'accordi_rientro';

    protected $fillable = [
        'condominio_id', 'anagrafica_id', 'data_accordo', 'fino_al', 'autorizzato_assemblea', 'data_autorizzazione', 'verbale_autorizzazione', 'nota',
        'decadenza_rate', 'decadenza_giorni', 'chiuso_il', 'decadenza_registrata_il', 'nota_chiusura', 'registrato_da', 'chiuso_da', 'interessi_parte_fuori',
        'registro', 'chiusura_per_successione',
    ];

    /** Sulla parte della rata fuori dall'accordo gli interessi continuano: il di serie (decisione 450). */
    public const INTERESSI_CONTINUANO = 'continuano';

    /** Sulla parte della rata fuori dall'accordo gli interessi restano sospesi finché l'accordo regge: la scelta dell'amministratore (decisione 450). */
    public const INTERESSI_SOSPESI = 'sospesi';

    /**
     * Chiuso da una successione con l'arretrato agli eredi, l'accordo di un defunto che era in regola resta in regola fino al decesso: nella proposta degli
     * interessi del defunto i giorni dell'accordo restano fuori, e dal decesso non ne maturano altri. Il di serie.
     */
    public const SUCCESSIONE_IN_REGOLA = 'in_regola';

    /** Chiuso da una successione, l'accordo vale come una decadenza quel giorno: tornano i giorni dell'accordo. La scelta dell'amministratore nell'anteprima. */
    public const SUCCESSIONE_DECADENZA = 'decadenza';

    protected $casts = [
        'condominio_id' => 'integer',
        'anagrafica_id' => 'integer',
        'data_accordo' => 'date',
        'fino_al' => 'date',
        'autorizzato_assemblea' => 'boolean',
        'data_autorizzazione' => 'date',
        'chiuso_il' => 'date',
        'decadenza_rate' => 'integer',
        'decadenza_giorni' => 'integer',
        'segnali' => 'array',
        'decadenza_registrata_il' => 'date',
        'registro' => 'array',
    ];

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Anagrafica::class, 'anagrafica_id');
    }

    /** Le rate coperte, ognuna con l'importo riconosciuto. */
    public function rate(): HasMany
    {
        return $this->hasMany(AccordoRientroRata::class, 'accordo_rientro_id');
    }

    /** Il calendario: le rate dell'accordo, in ordine. */
    public function scadenze(): HasMany
    {
        return $this->hasMany(AccordoRientroScadenza::class, 'accordo_rientro_id')->orderBy('numero');
    }

    /** La scansione dell'accordo firmato. */
    public function documenti(): MorphMany
    {
        return $this->morphMany(Documento::class, 'documentable');
    }

    public function registratoDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrato_da');
    }

    public function chiusoDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chiuso_da');
    }

    /** In corso a una data: registrato con una data non dopo quella, e non chiuso prima o quel giorno. */
    public function inCorsoIl(string $giorno): bool
    {
        return $this->data_accordo->format('Y-m-d') <= $giorno && ($this->chiuso_il === null || $this->chiuso_il->format('Y-m-d') > $giorno);
    }
}
