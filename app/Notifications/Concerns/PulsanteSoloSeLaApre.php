<?php

namespace App\Notifications\Concerns;

use App\Helpers\RouteHelper;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;

/**
 * Il pulsante di un avviso a chi ha scritto, solo se chi lo riceve apre la scheda a cui porta.
 *
 * Gli avvisi che vanno all'autore o al creatore di una segnalazione o di una comunicazione
 * («approvata», «resa pubblica»), e quello che va all'autore di un commento («il tuo commento è stato
 * approvato»), portano un pulsante verso la scheda. Fuori dal pannello però la scheda si apre secondo
 * la regola di `scopeVisibiliNelPortale()`: pubblicata e approvata (o propria), e indirizzata a chi la
 * apre. Fra lo scritto e l'avviso possono passare giorni; un pulsante che risponde 403 non spiega
 * niente a chi lo riceve.
 *
 * La regola, una per tutti questi avvisi: se **quando la mail si compone** il destinatario non apre
 * l'oggetto (la stessa domanda della scheda, `show` della policy), la mail parte senza il pulsante e
 * dice perché. Prima della scheda c'è il portale: chi non ha un'anagrafica collegata all'account e non
 * ha il pannello non arriva alla scheda, perché il portale lo manda a completare i suoi dati
 * (`CheckHasAnagrafica`, decisione 134.4), anche quando la policy gliela aprirebbe (una privata resta
 * del suo autore). Per lui la ragione è quella. La ragione deve essere vera per chi la legge, e la
 * regola della scheda ne lascia quattro:
 * - non ha il permesso di vedere quell'entità;
 * - non ne è l'autore, e l'oggetto in questo momento non è pubblicato e approvato (una bozza o un
 *   oggetto in moderazione lo apre solo l'autore);
 * - non ne è l'autore, e l'oggetto è privato o indirizzato a persone fra cui lui non c'è: chi ha
 *   commentato lo apriva, quindi gli era indirizzato, e non lo è più;
 * - altrimenti non risulta fra i condòmini dei palazzi a cui è indirizzato. Per l'autore è l'unica
 *   ragione possibile con il permesso: lo stato non lo ferma, e una privata resta sempre sua.
 * La regola di chi vede che cosa resta quella della policy: qui cambia solo ciò che la mail dice.
 */
trait PulsanteSoloSeLaApre
{
    /**
     * @param  string  $permessoDiVedere  il permesso con cui il portale mostra quell'entità
     * @param  string  $condominio  il palazzo, o i palazzi, a cui l'oggetto è indirizzato, già scritti
     */
    protected function pulsanteOMotivo(
        MailMessage $mail,
        object $notifiable,
        Model $oggetto,
        string $etichetta,
        string $indirizzo,
        string $permessoDiVedere,
        string $condominio,
    ): MailMessage {
        if (! $notifiable instanceof User) {
            return $mail->action($etichetta, $indirizzo);
        }

        // Il pulsante del portale (non quello del pannello, che non chiede un'anagrafica) porterebbe
        // alla pagina dove si completano i dati, non alla scheda.
        if (RouteHelper::getRoutePrefixForUser($notifiable) === 'user' && $notifiable->anagrafica === null) {
            return $mail->line(__('notifications.author_cannot_open.no_anagrafica'));
        }

        if (Gate::forUser($notifiable)->allows('show', $oggetto)) {
            return $mail->action($etichetta, $indirizzo);
        }

        return $mail->line($this->motivoPerCuiNonLaApre($notifiable, $oggetto, $permessoDiVedere, $condominio));
    }

    /** La ragione del rifiuto della scheda, letta dalle condizioni della regola (vedi sopra). */
    private function motivoPerCuiNonLaApre(User $utente, Model $oggetto, string $permessoDiVedere, string $condominio): string
    {
        if (! $utente->hasPermissionTo($permessoDiVedere)) {
            return __('notifications.author_cannot_open.no_permission');
        }

        $autore = $oggetto->created_by !== null && (int) $oggetto->created_by === (int) $utente->id;

        if (! $autore && ! ($oggetto->is_published && $oggetto->is_approved)) {
            return __('notifications.author_cannot_open.not_published');
        }

        if (! $autore && ($oggetto->is_private || $oggetto->anagrafiche()->exists())) {
            return __('notifications.author_cannot_open.not_addressed');
        }

        return __('notifications.author_cannot_open.outside_building', ['condominio' => $condominio]);
    }
}
