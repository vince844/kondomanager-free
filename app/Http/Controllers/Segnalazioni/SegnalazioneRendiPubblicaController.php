<?php

namespace App\Http\Controllers\Segnalazioni;

use App\Events\Notifiche\DestinatariDaAvvisare;
use App\Http\Controllers\Controller;
use App\Models\Anagrafica;
use App\Models\Segnalazione;
use App\Notifications\Segnalazioni\SegnalazioneResaPubblicaNotification;
use App\Services\Notifiche\DestinatariNotifica;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * «Rendi pubblica»: l'unico modo di rendere pubblica una segnalazione privata (Coda 304, decisioni
 * 127.4 e 129.2).
 *
 * Il modulo di modifica non lo fa più: i residenti di una privata non si svuotano
 * (`CreateSegnalazioneRequest`), e il campo `is_private` l'amministratore non l'ha mai avuto. Qui il
 * gesto è esplicito, la pagina chiede conferma dicendo chi la vedrà e con quale nome, e l'autore
 * viene avvisato.
 *
 * Che cosa cambia: `is_private` diventa falso e i collegamenti alle persone si tolgono, perché la
 * segnalazione sia davvero di tutto il condominio come la conferma dice (con i collegamenti la
 * vedrebbero solo le persone collegate: decisione Q1 del 17/10/2026). Il testo, lo stato, la
 * priorità, l'approvazione e l'autore restano com'erano. Il contrario non esiste.
 *
 * Il comando toglie la privacy, non approva e non pubblica (decisione 127.2: l'approvazione cambia
 * solo dall'interruttore). Su una privata ancora in moderazione o in bozza i vicini la vedranno
 * quando sarà pubblicata e approvata, e il messaggio e la mail all'autore lo dicono così.
 *
 * Gli avvisi (decisione 134.5): per chi non la vedeva è come una prima pubblicazione, e riceve
 * «nuova segnalazione»; chi la vedeva già (le persone collegate) no, e l'autore riceve «resa
 * pubblica». Su una privata non ancora pubblicata e approvata nessuno la vede adesso: l'avviso
 * «nuova» parte con l'approvazione, come sempre.
 */
class SegnalazioneRendiPubblicaController extends Controller
{
    use HandleFlashMessages;

    public function __invoke(Segnalazione $segnalazione): RedirectResponse
    {
        Gate::authorize('rendiPubblica', $segnalazione);

        if (! $segnalazione->is_private) {
            return back()->with($this->flashInfo(__('segnalazioni.privacy.already_public')));
        }

        // Chi la vede prima del comando: la platea degli avvisi, letta prima di toccare i collegamenti.
        $destinatariPrima = app(DestinatariNotifica::class)->perModello($segnalazione);

        try {

            DB::transaction(function () use ($segnalazione) {
                $segnalazione->update([
                    'is_private' => false,
                    'updated_by' => Auth::id(),
                ]);

                $segnalazione->anagrafiche()->detach();
            });

        } catch (\Throwable $e) {

            Log::error('Error making segnalazione ID ' . $segnalazione->id . ' public: ' . $e->getMessage());

            return back()->with($this->flashError(__('segnalazioni.privacy.error_make_public')));
        }

        // Gli avvisi stanno fuori dalla transazione, come negli altri controller: una mail che non
        // parte non deve far tornare indietro una scelta che il database ha accettato. L'uno non
        // ferma l'altro.
        $autoreAvvisato = true;
        $condominioAvvisato = true;

        try {

            $autore = $segnalazione->createdBy;

            if ($autore !== null) {
                Notification::send($autore, new SegnalazioneResaPubblicaNotification(
                    $segnalazione,
                    (string) Auth::user()->name,
                    (string) $segnalazione->condominio?->nome,
                ));
            }

        } catch (\Throwable $e) {

            Log::error('Error notifying author of segnalazione ID ' . $segnalazione->id . ' made public: ' . $e->getMessage());

            $autoreAvvisato = false;
        }

        try {

            $this->avvisaChiNonLaVedeva($segnalazione, $destinatariPrima);

        } catch (\Throwable $e) {

            Log::error('Error notifying residents of segnalazione ID ' . $segnalazione->id . ' made public: ' . $e->getMessage());

            $condominioAvvisato = false;
        }

        // Il messaggio dice ogni avviso mancato: i due falliscono insieme proprio quando la coda non
        // accetta lavori, e dirne uno solo lascerebbe credere che l'altro sia partito.
        if (! $autoreAvvisato && ! $condominioAvvisato) {
            return back()->with($this->flashWarning(__('segnalazioni.privacy.error_notify_both')));
        }

        if (! $autoreAvvisato) {
            return back()->with($this->flashWarning(__('segnalazioni.privacy.error_notify_author')));
        }

        if (! $condominioAvvisato) {
            return back()->with($this->flashWarning(__('segnalazioni.privacy.error_notify_residents')));
        }

        return back()->with($this->flashSuccess($segnalazione->is_published && $segnalazione->is_approved
            ? __('segnalazioni.privacy.success_make_public')
            : __('segnalazioni.privacy.success_make_public_pending')));
    }

    /**
     * «Nuova segnalazione» a chi la vede per la prima volta: la platea dopo il comando meno quella di
     * prima, come in `SegnalazioneController::avvisaDopoLaModifica()`. L'autore non è mai fra i nuovi:
     * riceve «resa pubblica», non l'annuncio della propria.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $destinatariPrima
     */
    private function avvisaChiNonLaVedeva(Segnalazione $segnalazione, $destinatariPrima): void
    {
        $destinatariDopo = app(DestinatariNotifica::class)->perModello($segnalazione->fresh());

        $dellAutore = $segnalazione->created_by === null
            ? collect()
            : Anagrafica::where('user_id', $segnalazione->created_by)->pluck('id');

        $nuovi = $destinatariDopo->diff($destinatariPrima)->diff($dellAutore)->values()->all();

        if ($nuovi !== []) {
            DestinatariDaAvvisare::dispatch($segnalazione, $nuovi, 'nuovo');
        }
    }
}
