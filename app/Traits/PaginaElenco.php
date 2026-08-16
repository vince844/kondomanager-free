<?php

namespace App\Traits;

use App\Models\PreferenzaTabellaUtente;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Quante righe mostra un elenco, e come se lo ricorda.
 *
 * ## Il difetto che chiude
 *
 * *«Nell'anagrafica condomini faccio una ricerca avendo impostato 40 elementi per pagina, me ne
 * vengono fuori solo 10.»* Segnalazione dal forum, agosto 2026. Il difetto aveva due metà: una
 * lato browser — le barre dei filtri ricostruivano la richiesta da zero e lasciavano indietro
 * `per_page`, chiusa in `useTabellaServer` — e una qui: **la scelta non sopravviveva a uscire
 * dall'elenco e rientrarci**, perché l'indirizzo si ricostruiva da capo e con esso il valore
 * predefinito.
 *
 * ## La catena
 *
 * 1. il `per_page` **esplicito nella richiesta** — l'utente ha appena mosso il selettore;
 * 2. la **scelta salvata** da quell'utente per quell'elenco;
 * 3. il **valore proprio dell'elenco**, se ne dichiara uno;
 * 4. `GeneralSettings::$default_per_page` — la riga nelle impostazioni generali;
 * 5. `config('pagination.default_per_page')` — l'ultimo ripiego.
 *
 * ⚠️ **Il gradino 3 sta sopra il 4, e ha una conseguenza da conoscere.** Un elenco che dichiara un
 * proprio valore lo fa per una ragione sua — il libro giornale mostra venti righe perché le
 * scritture sono dense e vederne dieci significa non vedere una giornata — e quella ragione non
 * cambia perché l'amministratore ha alzato il valore generale. Il rovescio è che **alzare
 * l'impostazione generale non muove quegli elenchi**: restano al loro valore finché qualcuno non
 * ne sceglie un altro lì sopra, dopodiché è la scelta personale a comandare, per sempre.
 *
 * Oggi lo dichiara un elenco solo. Se un giorno fossero molti, questo gradino diventerebbe il
 * posto in cui l'impostazione generale smette di significare qualcosa, e andrebbe ripensato.
 *
 * Ogni gradino è **normalizzato**, non validato: un valore fuori dalla lista consentita non è un
 * errore, è semplicemente ignorato in favore del gradino successivo.
 *
 * ⚠️ **Perché normalizzare e non rifiutare.** Un `Rule::in` sembrerebbe più rigoroso, ma
 * trasformerebbe in errore 422 un segnalibro `?per_page=200` — che sugli elenchi dei movimenti
 * era legittimo fino a ieri — e un 422 su una `GET` di Inertia rimanda l'utente indietro senza
 * spiegargli niente. Soprattutto: i gradini 2 e 3 **non passano da nessuna validazione**. La
 * preferenza salvata è un numero in una tabella e l'impostazione globale è un numero in un'altra;
 * se domani la lista consentita cambiasse, entrambi resterebbero al vecchio valore e finirebbero
 * dritti dentro un `LIMIT`. Il tetto deve stare dove i valori si risolvono, non dove arrivano.
 *
 * ## Cosa NON si ricorda
 *
 * **L'ordinamento.** Sarebbe il compagno naturale di `per_page`, ed è stato escluso per una ragione
 * meccanica: `useTabellaServer` scarta i parametri nulli, quindi «ho tolto l'ordinamento» e «non
 * ne ho parlato» arrivano al server come la stessa richiesta. Con un ordinamento memorizzato, il
 * **terzo clic** su un'intestazione — quello che riporta l'elenco all'ordine naturale — non
 * avrebbe più alcun effetto, su tutte e ventisei le tabelle e senza dare errore. Ricordare
 * l'ordinamento costerebbe la possibilità di toglierlo.
 *
 * **I filtri.** Un filtro ricordato fa sparire delle righe senza che nessuno abbia chiesto niente,
 * e chi rientra su un elenco vede un sottoinsieme credendolo il tutto. È la categoria di difetto
 * da cui nasce questa beta, non una da introdurre.
 */
trait PaginaElenco
{
    /**
     * Le righe per pagina da usare, e — se è il caso — la scelta da ricordare.
     *
     * @param  string|null  $suffisso     per le pagine che mostrano due elenchi distinti
     * @param  int|null     $predefinito  il valore proprio di questo elenco, se ne ha uno
     */
    protected function righePerPagina(
        Request $request,
        ?string $suffisso = null,
        ?int $predefinito = null,
    ): int {
        $chiesto = $this->normalizza($request->input('per_page'));

        $senzaRichiesta = $this->normalizza($this->ricordata($request, $suffisso))
            ?? $this->normalizza($predefinito)
            ?? $this->normalizza(app(GeneralSettings::class)->default_per_page)
            ?? $this->normalizza(config('pagination.default_per_page'))
            ?? 10;

        if ($chiesto === null) {
            return $senzaRichiesta;
        }

        // ⚠️ **Una scelta è tale solo se cambia qualcosa.** Il browser rimanda `per_page` a ogni
        // azione — è così che la scelta smette di perdersi cercando o ordinando — quindi anche un
        // semplice clic su «pagina 2» arriva qui con un `per_page` esplicito. Senza questo
        // confronto verrebbe registrato come una decisione: la memoria si riempirebbe da sola di
        // valori che nessuno ha scelto, e da quel momento **l'impostazione generale non
        // sposterebbe più nulla** per chiunque abbia cambiato pagina almeno una volta.
        //
        // Il confronto è con ciò che sarebbe uscito *senza* la richiesta, non con la sola
        // preferenza salvata: alla prima visita quella è vuota, ed è proprio il caso in cui il
        // difetto si presenta.
        if ($chiesto !== $senzaRichiesta) {
            $this->ricorda($request, $chiesto, $suffisso);
        }

        return $chiesto;
    }

    /** Un valore ammesso resta tale; tutto il resto vale `null`, cioè «passo al gradino dopo». */
    private function normalizza(mixed $valore): ?int
    {
        if ($valore === null || $valore === '') {
            return null;
        }

        $intero = (int) $valore;

        return in_array($intero, config('pagination.consentite'), true) ? $intero : null;
    }

    /**
     * L'identità dell'elenco: il nome della rotta, più un suffisso se la pagina ne ha due.
     *
     * ⚠️ Il nome della rotta identifica **una pagina**, non una tabella. Finché una pagina mostra
     * un elenco solo le due cose coincidono; quando non coincidono serve il suffisso, altrimenti i
     * due elenchi si scambierebbero la preferenza a vicenda.
     */
    private function chiave(?string $suffisso): ?string
    {
        $rotta = Route::currentRouteName();

        if ($rotta === null) {
            return null;
        }

        return $suffisso === null ? $rotta : "{$rotta}#{$suffisso}";
    }

    private function ricordata(Request $request, ?string $suffisso): ?int
    {
        $utente = $request->user();
        $chiave = $this->chiave($suffisso);

        if ($utente === null || $chiave === null) {
            return null;
        }

        return PreferenzaTabellaUtente::query()
            ->where('user_id', $utente->id)
            ->where('chiave', $chiave)
            ->value('per_page');
    }

    /**
     * Registra la scelta, ma solo quando è davvero una scelta.
     *
     * ⚠️ **Solo sulle richieste Inertia.** Una `GET` che scrive non è idempotente: senza questa
     * condizione, aprire il link che un collega ha incollato in chat — completo del suo
     * `?per_page=50` — riscriverebbe la preferenza di chi lo apre. Dentro il programma la
     * navigazione passa sempre da Inertia; un indirizzo battuto a mano o seguito da fuori no, e
     * vale per quella volta sola.
     *
     * ⚠️ **Solo se il valore cambia.** Ogni clic su «pagina successiva» riporta `per_page`
     * nell'indirizzo: senza il confronto sarebbe una `UPDATE` identica a ogni cambio di pagina di
     * ogni elenco. Il confronto grosso — «è davvero una scelta o è il valore che sarebbe uscito
     * comunque?» — sta a monte, in `righePerPagina()`; questo è la seconda rete, quella che evita
     * la scrittura inutile quando la preferenza salvata coincide già.
     */
    private function ricorda(Request $request, int $perPage, ?string $suffisso): void
    {
        $utente = $request->user();
        $chiave = $this->chiave($suffisso);

        if ($utente === null || $chiave === null || ! $request->hasHeader('X-Inertia')) {
            return;
        }

        if ($this->ricordata($request, $suffisso) === $perPage) {
            return;
        }

        PreferenzaTabellaUtente::query()->updateOrCreate(
            ['user_id' => $utente->id, 'chiave' => $chiave],
            ['per_page' => $perPage],
        );
    }
}
