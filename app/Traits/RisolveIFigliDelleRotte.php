<?php

namespace App\Traits;

/**
 * # Dice a Laravel come si chiama la relazione verso il figlio di una rotta annidata
 *
 * ## Il problema, misurato
 *
 * `/gestionale/{condominio}/tabelle/{tabella}` deve rifiutare la tabella di un **altro** condominio.
 * Laravel lo sa fare — si chiama *scoped binding* e si accende con `->scopeBindings()` — ma per
 * farlo chiede al modello padre una relazione verso il figlio, e **il nome se lo inventa**:
 * `Str::plural(Str::camel($childType))`, cioè una pluralizzazione **inglese**.
 *
 * Su nomi italiani non funziona: per `{tabella}` cerca `Condominio::tabellas()`, per `{esercizio}`
 * `Condominio::esercizios()`. Misurato il 19/08/2026 sulle **26** coppie padre>figlio del
 * gestionale: le relazioni col nome che il binding si aspetta sono **zero su 26**.
 *
 * ⚠️ **E il fallimento non è un 404.** `resolveChildRouteBindingQuery()` invoca la relazione come
 * primo statement, prima di qualunque query: quindi è `BadMethodCallException`, cioè **500 su ogni
 * richiesta, anche legittima**. L'amministratore che apre la propria tabella nel proprio condominio
 * prende lo stesso errore di chi tenta l'abuso. È il motivo per cui accendere lo scoping senza
 * questo trait *distrugge invece di proteggere*.
 *
 * ## La soluzione: il seggio che il framework offre
 *
 * `Model::childRouteBindingRelationshipName($childType)` esiste apposta per essere sovrascritto. Si
 * dichiara una mappa esplicita e la derivazione automatica non entra più in gioco.
 *
 * ⚠️ **L'alternativa scartata: relazioni-alias.** Si sarebbe potuto aggiungere `tabellas()`,
 * `esercizios()`, `immobiles()` accanto a quelle vere. Sarebbero quindici metodi con nomi che non
 * significano niente in nessuna delle due lingue, dentro un codice che è italiano di proposito — e
 * chi li leggesse fra un anno non capirebbe perché esistono. Un metodo per padre, con la mappa in
 * chiaro, dice anche il perché.
 *
 * ## Cosa NON risolve
 *
 * Le coppie in cui il figlio **non ha una relazione col padre**, perché non ha la colonna: dal
 * `{esercizio}` non si arriva a `{pianoConto}` né a `{pianoRate}` (`piani_conti` e `piani_rate` non
 * hanno `esercizio_id`, il legame passa dal pivot delle gestioni), e da nessuno dei due si arriva a
 * `{conto}` (`conti` pende da `piano_conto_id`). Lì la mappa non ha niente da mappare e la guardia
 * resta scritta a mano nel controller.
 */
trait RisolveIFigliDelleRotte
{
    /**
     * Tipo del figlio (il nome del parametro nella rotta) => nome della relazione su questo modello.
     *
     * ⚠️ Una coppia che non è qui dentro ricade sulla derivazione automatica di Laravel, che sui
     * nomi italiani sbaglia: **non si accende lo scoping su una rotta la cui coppia non è mappata**.
     * Il presidio è `tests/Feature/System/ScopingDelleRotteAnnidateTest.php`.
     *
     * @return array<string, string>
     */
    abstract protected function relazioniDeiFigliNelleRotte(): array;

    /**
     * @param  string  $childType
     * @return string
     */
    protected function childRouteBindingRelationshipName($childType)
    {
        return $this->relazioniDeiFigliNelleRotte()[$childType]
            ?? parent::childRouteBindingRelationshipName($childType);
    }
}
