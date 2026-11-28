<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Ogni notifica del programma dice che cosa fa con un account sospeso (Coda 694)
 *
 * Ogni classe di `app/Notifications` è in uno di due elenchi: quelle che non partono verso chi è sospeso (qui sotto, con il motivo) o quelle che partono
 * comunque (`FermaGliAvvisiAChiESospeso::PASSANO`, con il motivo). Una notifica nuova senza una riga fa fallire la prova: chi la scrive decide.
 * Per ciascuna si chiede all'ascoltatore vero che cosa fa con un utente sospeso.
 */

use App\Models\User;
use App\Support\Notifiche\FermaGliAvvisiAChiESospeso;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Finder\Finder;

/** Le notifiche che non partono verso un account sospeso, né verso l'anagrafica di un account sospeso. */
function oasFermate(): array
{
    $n = 'App\\Notifications\\';

    return [
        $n . 'Allegati\\AllegatiComunicazioneNotification' => 'allegati nuovi di una comunicazione, a chi la vede',
        $n . 'Allegati\\AllegatoApprovatoNotification' => 'a chi ha caricato l\'allegato',
        $n . 'Allegati\\AllegatoEliminatoNotification' => 'a chi ha caricato l\'allegato',
        $n . 'Allegati\\AllegatoInAttesaNotification' => 'a chi approva, nello studio',
        $n . 'Allegati\\NuovoAllegatoNotification' => 'a chi segue la segnalazione',
        $n . 'Assemblee\\ConvocazioneDaSpedireNotification' => 'il promemoria allo studio: non è una convocazione',
        $n . 'Commenti\\CommentoApprovatoNotification' => 'a chi ha scritto il commento',
        $n . 'Commenti\\CommentoEliminatoNotification' => 'a chi ha scritto il commento',
        $n . 'Commenti\\CommentoInAttesaNotification' => 'a chi approva i commenti',
        $n . 'Commenti\\CommentoModificatoInAttesaNotification' => 'a chi approva i commenti',
        $n . 'Commenti\\CommentoNascostoNotification' => 'a chi ha scritto il commento',
        $n . 'Commenti\\NuovoCommentoNotification' => 'a chi segue la discussione',
        $n . 'Comunicazioni\\ApproveComunicazioneNotification' => 'a chi approva le comunicazioni',
        $n . 'Comunicazioni\\ApprovedComunicazioneNotification' => 'a chi ha scritto la comunicazione',
        $n . 'Comunicazioni\\ComunicazioneProgrammataPubblicataNotification' => 'la conferma a chi l\'ha programmata',
        $n . 'Comunicazioni\\NewComunicazioneNotification' => 'avviso della bacheca all\'account',
        $n . 'Comunicazioni\\UpdatedComunicazioneNotification' => 'avviso della bacheca all\'account',
        $n . 'Documenti\\ApproveDocumentoNotification' => 'a chi approva i documenti',
        $n . 'Documenti\\ApprovedDocumentoNotification' => 'a chi ha caricato il documento',
        $n . 'Documenti\\NewDocumentoNotification' => 'avviso dell\'archivio all\'account',
        $n . 'Documenti\\UpdatedDocumentoNotification' => 'avviso dell\'archivio all\'account',
        $n . 'Errori\\ErroreAvvisatoNotification' => 'l\'errore avvisato allo studio',
        $n . 'Segnalazioni\\ApproveSegnalazioneNotification' => 'a chi approva le segnalazioni',
        $n . 'Segnalazioni\\ApprovedSegnalazioneNotification' => 'a chi ha scritto la segnalazione',
        $n . 'Segnalazioni\\NewSegnalazioneNotification' => 'avviso della segnalazione all\'account',
        $n . 'Segnalazioni\\SegnalazioneAssegnataNotification' => 'a chi è assegnata',
        $n . 'Segnalazioni\\SegnalazioneNonPiuAssegnataNotification' => 'a chi non la segue più',
        $n . 'Segnalazioni\\SegnalazioneResaPubblicaNotification' => 'a chi ha scritto la segnalazione',
        $n . 'Segnalazioni\\UpdatedSegnalazioneNotification' => 'avviso della segnalazione all\'account',
        $n . 'Users\\RegisteredUserNotification' => 'a chi nello studio riceve le nuove registrazioni',
    ];
}

/** Le classi di notifica di `app/Notifications` (non i tratti di `Concerns`, non le basi astratte). @return list<string> */
function oasClassi(): array
{
    $classi = [];
    foreach (Finder::create()->files()->name('*.php')->in(app_path('Notifications')) as $file) {
        $classe = 'App\\Notifications\\' . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
        if (! class_exists($classe)) {
            continue;
        }
        $r = new ReflectionClass($classe);
        if (! $r->isAbstract() && $r->isSubclassOf(Notification::class)) {
            $classi[] = $classe;
        }
    }
    sort($classi);

    return $classi;
}

it('ogni notifica è fermata o passa, con il suo motivo, e non tutte e due', function () {
    $classi = oasClassi();
    expect(count($classi))->toBeGreaterThan(30);

    $fermate = oasFermate();
    $passano = FermaGliAvvisiAChiESospeso::PASSANO;
    foreach ($classi as $classe) {
        $dove = (int) array_key_exists($classe, $fermate) + (int) array_key_exists($classe, $passano);
        expect($dove)->toBe(1, "{$classe}: scrivi se parte verso un account sospeso (elenco delle fermate qui, o `PASSANO` con il motivo)");
    }
    // Nessuna riga per una classe che non c'è più.
    expect(array_diff(array_keys($fermate), $classi))->toBe([])->and(array_diff(array_keys($passano), $classi))->toBe([]);
    foreach (array_merge($fermate, $passano) as $classe => $motivo) {
        expect(trim((string) $motivo))->not->toBe('', "{$classe}: manca il motivo");
    }
});

it('l\'ascoltatore fa ciò che gli elenchi dicono', function () {
    $sospeso = User::factory()->create();
    $sospeso->forceFill(['suspended_at' => now()])->save();
    $attivo = User::factory()->create();

    foreach (oasClassi() as $classe) {
        $avviso = (new ReflectionClass($classe))->newInstanceWithoutConstructor();
        $perIlSospeso = Event::until(new NotificationSending($sospeso, $avviso, 'mail'));
        array_key_exists($classe, FermaGliAvvisiAChiESospeso::PASSANO)
            ? expect($perIlSospeso)->not->toBeFalse("{$classe} deve partire")
            : expect($perIlSospeso)->toBeFalse("{$classe} non deve partire");
        expect(Event::until(new NotificationSending($attivo, $avviso, 'mail')))->not->toBeFalse("{$classe} verso un account attivo");
    }
});
