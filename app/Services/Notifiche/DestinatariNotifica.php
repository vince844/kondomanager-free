<?php

namespace App\Services\Notifiche;

use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\Documento;
use App\Models\Segnalazione;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Chi riceverebbe **oggi** la notifica di creazione di un oggetto: la platea, l'unica fonte per gli
 * avvisi di creazione, modifica e approvazione di segnalazioni, comunicazioni e documenti.
 *
 * ## Perché serve, e perché sta qui e non nei listener
 *
 * I listener che mandano le notifiche di creazione risolvono i destinatari dai **dati del modulo**
 * (`$event->validated`), che è giusto per una creazione: in quel momento il modulo *è* la verità.
 * Ma alla modifica quei dati non bastano — serve sapere chi era destinatario **prima** e chi lo è
 * **dopo**, e quello si legge solo dall'archivio.
 *
 * Fino alla beta.64 questa domanda non se la poneva nessuno, e il risultato è il difetto che ha
 * aperto questa beta: i destinatari si fissavano alla creazione, quindi **chi veniva aggiunto in
 * modifica non riceveva niente, né allora né mai**. La comunicazione esisteva e loro la vedevano
 * solo se entravano a guardare. Non era «non li avvisiamo di una modifica»: a loro la
 * comunicazione non era mai arrivata.
 *
 * ## La platea è chi trova l'oggetto nell'elenco del portale
 *
 * - **Nessuno finché l'oggetto non è pubblicato e approvato** (Coda 198): una bozza o un oggetto in
 *   moderazione non avvisa. Alla prima pubblicazione la platea passa da vuota a piena, e la modifica
 *   (`avvisaDopoLaModifica`) manda a tutti l'avviso «nuovo», come l'approvazione. Senza memoria:
 *   rimesso in bozza e ripubblicato, l'avviso riparte (nessuna colonna «già avvisato»).
 * - **Poi, a chi è indirizzato** (decisione Q1 del 17/10/2026, Coda 302): le anagrafiche collegate,
 *   oppure — se non ce ne sono — tutte quelle dei condomìni (o del condominio, per le segnalazioni).
 *   Una segnalazione o comunicazione **privata** va solo all'autore e alle persone collegate, anche
 *   se i collegamenti sono stati tolti. La pivot decide la platea anche per le segnalazioni, come
 *   per le comunicazioni: la regola è quella dell'elenco del portale, per tutte e due, private
 *   comprese.
 *
 * Per segnalazioni e comunicazioni la regola sta nel modello (`anagraficheCheLaVedono()`), accanto a
 * quella dell'elenco (`scopeVisibiliNelPortale()`), perché dicano la stessa cosa. I documenti non
 * salvano `is_private`: per loro la regola è qui.
 *
 * ⚠️ **Questa classe non filtra per preferenza di notifica**, ed è voluto: dice chi è *destinatario*,
 * non chi *vuole la mail*. Il filtro delle preferenze resta dov'era, nel listener che invia — se
 * fosse qui, una persona che ha disattivato le notifiche risulterebbe «non ancora destinataria»
 * per sempre, e a ogni modifica comparirebbe fra i nuovi.
 */
class DestinatariNotifica
{
    /**
     * Gli id delle anagrafiche destinatarie, senza duplicati.
     *
     * @return Collection<int, int>
     */
    public function perModello(Model $modello): Collection
    {
        if ($modello instanceof Segnalazione || $modello instanceof Comunicazione) {
            return $modello->anagraficheCheLaVedono();
        }

        if ($modello instanceof Documento) {
            // Coda 198: un documento in bozza o in moderazione non avvisa nessuno.
            if (! ($modello->is_published && $modello->is_approved)) {
                return collect();
            }

            $esplicite = $modello->anagrafiche()->get()->pluck('id');

            if ($esplicite->isNotEmpty()) {
                return $esplicite->unique()->values();
            }

            return $this->anagraficheDeiCondomini(
                $modello->condomini()->get()->pluck('id')->all()
            );
        }

        // ⚠️ Si solleva invece di restituire una collezione vuota. Un modello nuovo che passasse
        // di qui senza un ramo suo otterrebbe «nessun destinatario», cioè **nessuna notifica e
        // nessun errore**: la forma di guasto che questo progetto paga più spesso. Meglio un
        // fallimento rumoroso in sviluppo che un silenzio in produzione.
        throw new \InvalidArgumentException(
            'DestinatariNotifica non sa risolvere i destinatari di '.$modello::class.
            ': aggiungere il ramo, non lasciarlo cadere qui.'
        );
    }

    /**
     * Tutte le anagrafiche collegate ai condomìni indicati.
     *
     * @param  array<int, int|null>  $condominioIds
     * @return Collection<int, int>
     */
    private function anagraficheDeiCondomini(array $condominioIds): Collection
    {
        $ids = array_values(array_filter($condominioIds));

        if ($ids === []) {
            return collect();
        }

        return Anagrafica::whereHas('condomini', fn ($q) => $q->whereIn('condominio_id', $ids))
            ->pluck('id')
            ->unique()
            ->values();
    }
}
