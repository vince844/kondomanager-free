<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * La riga di `anagrafica_immobile`: chi è titolare di un'unità, con quale ruolo, da quando a quando.
 *
 * Nasce con la 1.11.0-beta.30 (B1 del progetto `docs/subentro_e_competenza_temporale.md`) come
 * aggancio di B2. L'`id` della riga lo espone già `withPivot('id')`; il modello serve perché B2
 * lavorerà **per periodo** — la stessa persona che vende e ricompra, l'inquilino che diventa
 * proprietario — e le rotte dovranno indirizzare la riga per `id` (decisione 13), e perché `save()`
 * in `attach` rilegga l'`id` appena scritto (`$incrementing = true`). Fin qui le relazioni
 * `belongsToMany` lavoravano **per persona** — `updateExistingPivot($anagraficaId, …)`,
 * `detach($anagraficaId)` — e dall'interfaccia la riga per coppia è una sola (guardia 1 di
 * `ValidatesImmobileAnagraficaPivot`, agganciata alle sole FormRequest; l'importatore può scriverne
 * due con ruoli diversi, `LivelloTitolarita::commit()`).
 *
 * ⚠️ `using()` porta anche `attach`/`detach`/`updateExistingPivot` sul modello
 * (`InteractsWithPivotTable::attachUsingCustomClass` / `detachUsingCustomClass` /
 * `updateExistingPivotUsingCustomClass`). Conseguenze in B1: `updateExistingPivot($anagraficaId, …)`
 * aggiorna UNA riga della coppia — la prima che la query restituisce, senza ORDER BY — e non più
 * tutte; con valori identici a quelli in banca dati (`isDirty()` falso) non esegue nessun UPDATE,
 * `updated_at` non si muove e ritorna 0; partono gli eventi Eloquent del modello (oggi nessun
 * listener). Con una riga per coppia è indifferente. B2 lavora per riga:
 * `wherePivot('id', $rigaId)->updateExistingPivot(...)` funziona già e tocca solo quella riga.
 * `detach($anagraficaId)` resta per persona: cancella tutte le righe, una per `id`.
 *
 * ⚠️ Nessun cast sulle colonne della riga (`data_inizio`, `data_fine`, `attivo`, …), di proposito;
 * l'unico è `id => int`, implicito in `$incrementing = true`. `ImmobileAnagraficaResource:46-48`
 * emette le tre colonne tal quali e `AnagraficaController:202-204` le date (lì `attivo` è già
 * `(bool)`): un cast `date` cambierebbe il JSON da `2026-01-01` a ISO 8601 in entrambi, e sulla
 * Resource `attivo` da `1` a `true`. B1 non cambia i cast: l'unica novità nel JSON è `pivot.id` dove
 * i modelli vanno a Inertia senza Resource (`SaldoInizialeController:38-40, :66`). I cast arrivano
 * con B2, insieme all'interfaccia «Registra passaggio».
 */
class TitolaritaImmobile extends Pivot
{
    protected $table = 'anagrafica_immobile';

    public $incrementing = true;
}
