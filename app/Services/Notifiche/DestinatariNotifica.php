<?php

namespace App\Services\Notifiche;

use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\Documento;
use App\Models\Segnalazione;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Chi riceverebbe **oggi** la notifica di creazione di un oggetto.
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
 * ## Le due forme, e perché non sono la stessa cosa
 *
 * - **Comunicazioni e documenti** hanno una platea *scelta*: o un elenco esplicito di anagrafiche,
 *   oppure — se l'elenco è vuoto — tutte le anagrafiche dei condomìni collegati. È la stessa
 *   cascata che i listener di creazione applicano ai dati del modulo, riletta dalle pivot.
 * - **Le segnalazioni** hanno una platea *derivata*: il condominio della segnalazione, e basta.
 *   La pivot `anagrafiche` che pure esiste su di esse **non decide chi riceve la notifica** — se
 *   ne fosse dedotto il contrario, modificare quella lista manderebbe mail a gente a cui il
 *   listener di creazione non le avrebbe mai mandate.
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
        if ($modello instanceof Segnalazione) {
            return $this->anagraficheDeiCondomini([$modello->condominio_id]);
        }

        if ($modello instanceof Comunicazione || $modello instanceof Documento) {
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
