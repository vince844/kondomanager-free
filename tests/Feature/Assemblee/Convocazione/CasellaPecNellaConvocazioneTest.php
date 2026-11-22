<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\Convocazione\StatoDellaConvocazione;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Posta\ConfigurazionePec;
use App\Services\Posta\TrasportoPec;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Settings\PecSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (decisione 572, proposta 11): «Manda le PEC» e la casella PEC impostata nella pagina «Configurazione email».
 *
 * - Senza la casella il pulsante è spento e dice «Prima imposta la casella PEC dello studio», con il collegamento alla pagina con la PEC aperta per chi può
 *   aprire le impostazioni; il collaboratore che gestisce le assemblee legge la frase, senza il collegamento.
 * - Con la casella salvata dalla pagina il pulsante si accende: la convocazione legge la casella da `CasellaPec`, come ogni invio.
 * - Con l'ultima prova non andata il pulsante resta acceso, e la scheda porta la frase per la conferma: decide l'amministratore.
 *
 * Che cosa non copre: la finestra della conferma (vitest di `SchedaConvocazione`); un server PEC vero.
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** La casella PEC dello studio come la salva la pagina, e la sua ultima prova (con l'impronta della casella salvata). */
function cpncSalva(?string $erroreDellaProva = null): void
{
    $s = app(PecSettings::class);
    $s->indirizzo = 'studio.costa@pec.it';
    $s->server = 'smtps.pec.esempio.it';
    $s->porta = 465;
    $s->cifratura = 'ssl';
    $s->utente = 'studio.costa@pec.it';
    $s->password = Crypt::encryptString('segreta');
    $s->save();
    if (func_num_args() > 0) {
        $c = new ConfigurazionePec('studio.costa@pec.it', 'smtps.pec.esempio.it', 465, 'studio.costa@pec.it', 'segreta', 'ssl');
        \App\Services\Posta\ProveDelleCaselle::ricordaPec($erroreDellaProva === null, $erroreDellaProva, 'studio.costa@pec.it', \App\Services\Posta\CasellaPec::impronta($c));
    }
}

it('senza la casella: «Prima imposta la casella PEC dello studio», con il collegamento per chi apre le impostazioni e senza per chi non le apre', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);

    $this->actingAs(ViaRoma12::utente([\App\Enums\Permission::MANAGE_GENERAL_SETTINGS]));
    $pec = app(StatoDellaConvocazione::class)->perLaPagina($a)['pec'];
    expect($pec['attivo'])->toBeFalse()
        ->and($pec['perche'])->toBe('Prima imposta la casella PEC dello studio.')
        ->and($pec['dove'])->toBe(route('impostazioni.mail', ['apri' => 'pec']));

    $this->actingAs(ViaRoma12::utente());
    expect(app(StatoDellaConvocazione::class)->perLaPagina($a)['pec'])->toMatchArray(['perche' => 'Prima imposta la casella PEC dello studio.', 'dove' => null]);
});

it('con la casella salvata dalla pagina «Manda le PEC» si accende e manda dalla casella dello studio', function () {
    cpncSalva();
    ConvocazioneDiViaRoma12::$pecMandate = [];
    app()->instance(TrasportoPec::class, new class implements TrasportoPec {
        public function manda(ConfigurazionePec $casella, Email $messaggio): void
        {
            ConvocazioneDiViaRoma12::$pecMandate[] = ['casella' => $casella, 'messaggio' => $messaggio];
        }
    });
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin);

    expect(app(StatoDellaConvocazione::class)->perLaPagina($a)['pec'])->toMatchArray(['attivo' => true, 'perche' => null, 'dove' => null, 'avviso' => null]);
    $this->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]))->assertRedirect();
    $mandate = ConvocazioneDiViaRoma12::$pecMandate;
    expect($mandate)->toHaveCount(3)->and($mandate[0]['casella']->server)->toBe('smtps.pec.esempio.it')
        ->and($mandate[0]['casella']->password())->toBe('segreta')
        ->and($mandate[0]['messaggio']->getFrom()[0]->getAddress())->toBe('studio.costa@pec.it');
});

it('con l\'ultima prova non andata il pulsante resta acceso, e la conferma dice quando e perché', function () {
    ConvocazioneDiViaRoma12::oggi('2026-11-19 10:02');
    cpncSalva('Expected response code "235" but got code "535", with message "535 5.7.8 Error: authentication failed".');
    ConvocazioneDiViaRoma12::oggi();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin);

    $pec = app(StatoDellaConvocazione::class)->perLaPagina($a)['pec'];
    expect($pec['attivo'])->toBeTrue()
        ->and($pec['avviso'])->toBe('L\'ultima prova della casella PEC dello studio, il 19/11 alle 10:02, non è andata: il gestore non ha accettato la password. '
            . 'Se mandi lo stesso, le PEC che il gestore non accetta restano da mandare, con il motivo.');

    cpncSalva(null);
    expect(app(StatoDellaConvocazione::class)->perLaPagina($a)['pec']['avviso'])->toBeNull();
});
