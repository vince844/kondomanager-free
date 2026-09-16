<?php

namespace App\Services\Riparto;

use App\Support\PeriodoCompetenza;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Collection;

/**
 * Chi è titolare di un'unità — risolto in un posto solo.
 *
 * Fino alla 1.11.0-beta.29 la domanda era sparsa in una ventina di punti (`CalcoloQuoteService`,
 * `GenerateSaldiAction`, `SituazioneDebitoriaController`, l'importatore, i verificatori…), ognuno con
 * il suo `->where('attivo', true)`. B1 del progetto `docs/subentro_e_competenza_temporale.md` (§4.3)
 * li fa passare tutti da qui, in due forme sulla stessa regola:
 *
 * - {@see attiviAlla()} per le collection già caricate — il motore lavora su relazioni eager-loaded,
 *   non su query, e una query per unità sarebbe un N+1 su 44 unità e 8 tabelle;
 * - {@see vincolaQuery()} per i punti che interrogano `anagrafica_immobile` con `DB::table` o con un
 *   join.
 *
 * `RisolutoreTitolariTest` (invariante 4) pretende che rispondano identico sullo stesso dataset.
 *
 * ⚠️ **In B1 la regola è quella di prima, e basta: `attivo === true`.** Il periodo di competenza
 * viene accettato e **ignorato**: `data_inizio` e `data_fine` non filtrano, né qui né altrove — è il
 * difetto di §1.1 del progetto, e a correggerlo è B2 (la beta successiva), con D7 e il pro rata. Il
 * cancello di rilascio di B1 è «riparto identico al centesimo su tutta la suite»: qui non deve
 * cambiare niente, e il parametro c'è perché le firme siano quelle definitive: i chiamanti
 * cominceranno a passarlo con B2.
 *
 * Decisione 10 del progetto: `attivo` resta una condizione AND dentro questo risolutore fino alla
 * 2.0, letta e mai scritta da qui: nessun punto che **decide** chi è titolare o chi paga la legge più
 * direttamente. La leggono ancora, e devono: la diagnosi `VerificaTitolaritaCommand` (guarda anche le
 * righe spente, A4), la presentazione (`ImmobileAnagraficaResource:48`, `AnagraficaController:202`) e
 * i sei punti dell'inventario §4.3 che di proposito non filtrano, ciascuno con il suo commento.
 */
class RisolutoreTitolari
{
    /**
     * Filtra una collection di anagrafiche caricate con la pivot (`$immobile->anagrafiche`).
     *
     * La collection deve portare `->pivot` (relazione `belongsToMany` di `Immobile` o `Anagrafica`);
     * per le righe grezze di `DB::table('anagrafica_immobile')->get()` si vincola la query prima, con
     * {@see vincolaQuery()}, non la collection dopo.
     */
    public function attiviAlla(Collection $anagrafiche, ?PeriodoCompetenza $periodo = null): Collection
    {
        // B1: il periodo non entra nella regola. Vedi il docblock della classe.
        return $anagrafiche->filter(fn ($a) => (bool) ($a->pivot?->attivo ?? false));
    }

    /**
     * Aggiunge alla query il vincolo di titolarità. `$tabella` è l'alias con cui `anagrafica_immobile`
     * compare nella query (`'ai'` in un join), vuoto quando la query è sulla tabella nuda.
     *
     * @template T of QueryBuilder|EloquentBuilder
     * @param T $query
     * @return T
     */
    public function vincolaQuery(QueryBuilder|EloquentBuilder $query, ?PeriodoCompetenza $periodo = null, string $tabella = ''): QueryBuilder|EloquentBuilder
    {
        // B1: il periodo non entra nella regola. Vedi il docblock della classe.
        return $query->where($this->colonna('attivo', $tabella), true);
    }

    /**
     * Rende deterministica una lettura che oggi si affida a «la prima riga che capita».
     *
     * `PianoRateController` e `IncassoRateService` facevano, fino alla beta.29, `->value('tipologia')`
     * sulla coppia (anagrafica, immobile) senza filtrare su `attivo` e senza `orderBy`; oggi passano
     * da qui, come `SituazioneDebitoriaController` e `VerificaSaldiSolidaliCommand`. Non era un
     * difetto, perché la guardia 1 di `ValidatesImmobileAnagraficaPivot` garantisce una riga per
     * coppia **dall'interfaccia** (il trait è agganciato alle sole FormRequest); l'importatore può
     * scriverne due con ruoli diversi, e lì l'ordine di prima era indefinito. Lo diventa nel momento
     * esatto in cui B2 toglie la guardia per registrare due periodi della stessa persona. L'ordine è
     * quello dell'invariante 6-bis del progetto: prima la riga attiva, poi la più recente
     * (`data_inizio` desc), poi l'`id` come spareggio stabile.
     *
     * Solo su letture puntuali (`->value()`, `->first()`). Con `distinct()` o `groupBy()` MySQL 8
     * rifiuta l'`ORDER BY` su colonne non selezionate (3065/1055) e SQLite — il motore della suite —
     * non lo rileva: per gli insiemi si usa `vincolaQuery()` e basta.
     *
     * @template T of QueryBuilder|EloquentBuilder
     * @param T $query
     * @return T
     */
    public function ordinePreferenza(QueryBuilder|EloquentBuilder $query, string $tabella = ''): QueryBuilder|EloquentBuilder
    {
        return $query
            ->orderByDesc($this->colonna('attivo', $tabella))
            ->orderByDesc($this->colonna('data_inizio', $tabella))
            ->orderBy($this->colonna('id', $tabella));
    }

    private function colonna(string $nome, string $tabella): string
    {
        return $tabella === '' ? $nome : "{$tabella}.{$nome}";
    }
}
