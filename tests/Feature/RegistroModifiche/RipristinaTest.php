<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission;
use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Fornitore;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Immobile;
use App\Models\Palazzina;
use App\Models\RegistroModifica;
use App\Models\TipologiaImmobile;
use App\Models\User;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Services\RegistroModifiche\Gesto;
use App\Services\RegistroModifiche\RegistroModifiche;
use App\Settings\PrintSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role as RuoloSpatie;

/**
 * 1.12.0-beta.13 (§8 e P3 del progetto della tappa 1; decisione 518 (5); scena 1 della bozza): «Ripristina» un campo dalla «Storia».
 *
 * - Il ripristino passa **dalle stesse regole della scheda**: la `FormRequest` di modifica della pagina, validata per intero sulla scheda com'è oggi con il
 *   solo campo cambiato; se rifiuta, torna il messaggio della pagina (la scadenza del documento ormai passata, la descrizione obbligatoria della palazzina).
 * - Scrive **solo quel campo**, con la scrittura della pagina, in una riga «ripristinata» con il nome di chi lo fa e il perché «Ripristina dalla Storia,
 *   modifica del 16/12/2026 alle 09:12»; i condomini della persona, il conto corrente del fornitore, l'interno dell'unità restano com'erano; la comunicazione
 *   non manda avvisi.
 * - La finestra: «Rimettere l'email di prima?», «Adesso» e «Torna a», e la frase sul registro degli invii quando al nuovo indirizzo è partito qualcosa.
 * - Non si ripristina: senza il permesso di modificare la scheda (403), in contabilità, un dato che è già così, la descrizione vuota di un documento.
 *
 * Che cosa non copre: le schede del portale dei condòmini (non hanno «Storia»); i campi del fornitore e del condominio uno per uno (il percorso è lo
 * stesso per tutti i campi della scheda); il ripristino delle impostazioni protette da una conferma della password, che non sono ripristinabili.
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    $this->travelTo(Carbon::parse('2026-12-16 09:12:00', 'Europe/Rome'));
    $this->andrea = User::factory()->create(['name' => 'Andrea Costa', 'email_verified_at' => now()]);
    $this->andrea->assignRole('amministratore');
    $this->marco = User::factory()->create(['name' => 'Marco Galli', 'email_verified_at' => now()]);
    $this->marco->assignRole('collaboratore');
    $this->c = Condominio::factory()->create(['nome' => 'Via Roma 12', 'codice_fiscale' => '80012345678']);
    $this->ida = Anagrafica::query()->create(['nome' => 'Ida Romano', 'indirizzo' => 'Via Roma 12', 'email' => 'ida.romano@libero.it',
        'scadenza_documento' => '2026-12-20']);
    $this->ida->condomini()->attach($this->c->id);
    $this->actingAs($this->marco);
    $this->ida->update(['email' => 'ida.romano@gmail.com']);
    $this->riga = rpsUltima('anagrafica', $this->ida->id);
    $this->travelTo(Carbon::parse('2026-12-17 10:00:00', 'Europe/Rome'));
});

function rpsUltima(string $tipo, ?int $id): RegistroModifica
{
    return RegistroModifica::query()->where('oggetto_tipo', $tipo)->where('oggetto_id', $id)->orderByDesc('id')->firstOrFail();
}

function rpsRipristina($test, User $chi, RegistroModifica $riga, string $campo)
{
    return $test->actingAs($chi)->postJson(route('admin.registro-modifiche.ripristina', $riga), ['campo' => $campo]);
}

it('la persona: l\'email torna quella di prima, in una riga «ripristinata» con il nome di chi lo fa e il perché; i condomini restano', function () {
    rpsRipristina($this, $this->andrea, $this->riga, 'email')->assertOk()
        ->assertJsonPath('message', 'Rimesso: «Indirizzo email primario» torna a «ida.romano@libero.it». Il ripristino è una riga del registro, con il tuo nome.');

    $ultima = rpsUltima('anagrafica', $this->ida->id);
    expect($this->ida->fresh()->email)->toBe('ida.romano@libero.it')
        ->and($this->ida->fresh()->condomini()->pluck('condomini.id')->all())->toBe([$this->c->id])
        ->and($ultima->azione)->toBe('ripristinata')
        ->and($ultima->autore_nome)->toBe('Andrea Costa')
        ->and($ultima->perche)->toBe('Ripristina dalla Storia, modifica del 16/12/2026 alle 09:12')
        ->and(array_column($ultima->campi, 'chiave'))->toBe(['email'])
        ->and(RegistroModifica::query()->where('oggetto_tipo', 'anagrafica')->where('riepilogo', 'like', 'Condomini%')->count())->toBe(0);
});

it('la finestra: «Rimettere l\'email di prima?», adesso e torna a; la frase sul registro degli invii quando al nuovo indirizzo è partita un\'email', function () {
    $a = $this->actingAs($this->andrea)->getJson(route('admin.registro-modifiche.ripristina.anteprima', ['riga' => $this->riga->id, 'campo' => 'email']))
        ->assertOk()->json();
    expect($a)->toMatchArray(['titolo' => 'Rimettere l\'email di prima?', 'adesso' => 'ida.romano@gmail.com', 'torna_a' => 'ida.romano@libero.it',
        'pulsante' => 'Rimetti l\'email di prima', 'invii' => null, 'gia_cosi' => false])
        // La frase della bozza (scena 1): il dato con la parola della pagina, la persona senza la parola del tipo, «cambiata» accordato al dato.
        ->and($a['descrizione'])->toBe('Email di Ida Romano, cambiata da Marco Galli il 16/12/2026 alle 09:12.');

    RegistroInvio::query()->create(['condominio_id' => $this->c->id, 'anagrafica_id' => $this->ida->id, 'tipo' => 'ricevuta', 'canale' => 'email',
        'documentabile_type' => 'incasso', 'documentabile_id' => 1, 'numero' => 'INC-2026-00231', 'destinatario' => 'ida.romano@gmail.com',
        'stato' => 'inviata', 'emesso_il' => Carbon::parse('2026-12-16 15:00:00', 'Europe/Rome')->utc()]);
    $a = $this->actingAs($this->andrea)->getJson(route('admin.registro-modifiche.ripristina.anteprima', ['riga' => $this->riga->id, 'campo' => 'email']))->json();
    expect($a['invii'])->toBe('Dal 16/12 a «ida.romano@gmail.com» è partita un\'email (la ricevuta n. INC-2026-00231, nel registro degli invii): '
        . 'il ripristino non la cambia e non la rimanda.');
});

it('passa dalle regole della pagina: una scadenza del documento ormai passata è rifiutata con il messaggio della scheda', function () {
    $this->travelTo(Carbon::parse('2026-12-10 09:00:00', 'Europe/Rome'));
    $this->ida->update(['scadenza_documento' => '2027-06-30']);
    $riga = rpsUltima('anagrafica', $this->ida->id);
    $this->travelTo(Carbon::parse('2026-12-22 09:00:00', 'Europe/Rome'));

    rpsRipristina($this, $this->andrea, $riga, 'scadenza_documento')->assertStatus(422)->assertJsonValidationErrors('scadenza_documento');
    expect($this->ida->fresh()->scadenza_documento)->toStartWith('2027-06-30');
});

it('senza il permesso di modificare la scheda: 403, e il dato resta', function () {
    RuoloSpatie::findOrCreate('Legge e basta', 'web')->syncPermissions([Permission::ACCESS_ADMIN_PANEL->value, Permission::VIEW_ANAGRAFICHE->value,
        Permission::VIEW_STORIA_SCHEDE->value]);
    $chi = User::factory()->create(['email_verified_at' => now()]);
    $chi->assignRole('Legge e basta');

    rpsRipristina($this, $chi->fresh(), $this->riga, 'email')->assertForbidden();
    $this->actingAs($chi->fresh())->getJson(route('admin.registro-modifiche.ripristina.anteprima', ['riga' => $this->riga->id, 'campo' => 'email']))->assertForbidden();
    expect($this->ida->fresh()->email)->toBe('ida.romano@gmail.com');
});

it('non si ripristina: un dato già così, una riga di contabilità, un campo che la scheda non dichiara', function () {
    rpsRipristina($this, $this->andrea, $this->riga, 'email')->assertOk();
    rpsRipristina($this, $this->andrea, $this->riga, 'email')->assertStatus(422)
        ->assertJsonPath('message', 'È già così: ida.romano@libero.it. Non c\'è niente da rimettere.');

    $fattura = RegistroModifiche::scrivi(Gesto::su('contabilita', 'fattura_passiva', 88, 'Fattura n. 88 di Serramenti Marino')->azione('modificata')
        ->campo('totale', 'Totale', '€ 14.000,00', '€ 14.500,00', 1400000, 1450000)->perLaRiga());
    rpsRipristina($this, $this->andrea, $fattura, 'totale')->assertStatus(422)
        ->assertJsonPath('message', 'In contabilità non si ripristina: si storna, dalla fattura.');

    $creata = RegistroModifica::query()->where('oggetto_tipo', 'anagrafica')->where('azione', 'creata')->firstOrFail();
    rpsRipristina($this, $this->andrea, $creata, 'email')->assertStatus(422);
});

it('il fornitore: il telefono torna quello di prima e il conto corrente resta (la pagina senza l\'IBAN lo toglierebbe)', function () {
    $this->actingAs($this->andrea);
    $f = Fornitore::query()->create(['ragione_sociale' => 'Serramenti Marino', 'telefono' => '081 111 2222', 'stato' => 'attivo',
        'modalita_pagamento_default' => 'bonifico', 'giorni_scadenza' => 30]);
    $f->contiCorrenti()->create(['tipo' => 'ordinario', 'iban' => 'IT60X0542811101000000123456', 'intestatario' => 'Serramenti Marino', 'predefinito' => true]);
    $f->update(['telefono' => '081 333 4444']);
    $riga = rpsUltima('fornitore', $f->id);

    rpsRipristina($this, $this->andrea, $riga, 'telefono')->assertOk();
    expect($f->fresh()->telefono)->toBe('081 111 2222')
        ->and($f->contiCorrenti()->where('tipo', 'ordinario')->count())->toBe(1)
        ->and(rpsUltima('fornitore', $f->id)->azione)->toBe('ripristinata');
});

it('la comunicazione: il titolo torna quello di prima, senza avvisi ai destinatari', function () {
    Bus::fake();
    Notification::fake();
    $this->actingAs($this->andrea);
    $com = Comunicazione::factory()->create(['subject' => 'Lavori al portone', 'description' => 'Dal 3 dicembre.', 'priority' => 'bassa',
        'is_published' => true, 'is_approved' => true, 'can_comment' => false, 'is_featured' => false, 'created_by' => $this->andrea->id]);
    $com->condomini()->attach($this->c->id);
    $com->update(['subject' => 'Lavori al portone e al cancello']);
    $riga = rpsUltima('comunicazione', $com->id);

    rpsRipristina($this, $this->andrea, $riga, 'subject')->assertOk();
    expect($com->fresh()->subject)->toBe('Lavori al portone')
        ->and($com->fresh()->condomini()->pluck('condomini.id')->all())->toBe([$this->c->id]);
    Bus::assertNothingDispatched();
    Notification::assertNothingSent();
});

it('il condominio, l\'unità e la palazzina: il nome torna; l\'interno dell\'unità resta; la descrizione vuota della palazzina è rifiutata dalla sua regola', function () {
    $this->actingAs($this->andrea);
    $this->c->update(['nome' => 'Via Roma 12 bis']);
    rpsRipristina($this, $this->andrea, rpsUltima('condominio', $this->c->id), 'nome')->assertOk();
    expect($this->c->fresh()->nome)->toBe('Via Roma 12');

    $tipologia = TipologiaImmobile::query()->create(['nome' => 'Appartamento', 'categoria' => 'A']);
    $unita = Immobile::query()->create(['condominio_id' => $this->c->id, 'tipologia_id' => $tipologia->id, 'nome' => 'Appartamento', 'interno' => '7',
        'codice_immobile' => 'L-7', 'superficie' => 80]);
    $unita->update(['nome' => 'Appartamento grande']);
    rpsRipristina($this, $this->andrea, rpsUltima('immobile', $unita->id), 'nome')->assertOk();
    expect($unita->fresh()->nome)->toBe('Appartamento')->and($unita->fresh()->interno)->toBe('7');

    $p = Palazzina::query()->create(['condominio_id' => $this->c->id, 'name' => 'A', 'description' => null]);
    $p->update(['description' => 'Scala principale']);
    rpsRipristina($this, $this->andrea, rpsUltima('palazzina', $p->id), 'description')->assertStatus(422)->assertJsonValidationErrors('description');
    expect($p->fresh()->description)->toBe('Scala principale');
});

it('il documento: il nome torna; la descrizione vuota no, come nella pagina del documento', function () {
    $this->actingAs($this->andrea);
    $d = Documento::query()->create(['name' => 'Regolamento', 'description' => null, 'path' => 'documenti/r.pdf', 'mime_type' => 'application/pdf',
        'file_size' => 100, 'is_published' => true, 'is_approved' => true, 'created_by' => $this->andrea->id]);
    $d->update(['name' => 'Regolamento 2026', 'description' => 'Approvato il 10/12']);
    $riga = rpsUltima('documento', $d->id);

    rpsRipristina($this, $this->andrea, $riga, 'description')->assertStatus(422)
        ->assertJsonPath('message', 'La pagina del documento non lascia vuota la descrizione, e il ripristino segue la stessa regola: scrivi la descrizione dalla scheda.');
    rpsRipristina($this, $this->andrea, $riga, 'name')->assertOk();
    expect($d->fresh()->name)->toBe('Regolamento')->and($d->fresh()->description)->toBe('Approvato il 10/12');
});

it('le impostazioni delle stampe: il nome dello studio torna, e la riga del salvataggio è «ripristinata»', function () {
    $this->actingAs($this->andrea);
    $s = app(PrintSettings::class);
    $s->studio_nome = 'Studio Costa';
    $s->save();
    $s->studio_nome = 'Studio Costa e associati';
    $s->save();
    $riga = RegistroModifica::query()->where('oggetto_tipo', 'impostazioni_stampe')->orderByDesc('id')->firstOrFail();
    expect($this->actingAs($this->andrea)->getJson(route('admin.registro-modifiche.ripristina.anteprima', ['riga' => $riga->id, 'campo' => 'studio_nome']))
        ->assertOk()->json('descrizione'))->toBe('Nome dello studio delle impostazioni delle stampe, cambiato da Andrea Costa il 17/12/2026 alle 10:00.');

    rpsRipristina($this, $this->andrea, $riga, 'studio_nome')->assertOk();
    $ultima = RegistroModifica::query()->where('oggetto_tipo', 'impostazioni_stampe')->orderByDesc('id')->firstOrFail();
    expect(app(PrintSettings::class)->refresh()->studio_nome)->toBe('Studio Costa')
        ->and($ultima->azione)->toBe('ripristinata')
        ->and($ultima->perche)->toStartWith('Ripristina dalla Storia, modifica del ');
});

it('«Ripristina» chiede anche di poter aprire la «Storia» di quella scheda: le impostazioni delle stampe sono dello studio, solo l\'amministratore (403)', function () {
    $this->actingAs($this->andrea);
    $s = app(PrintSettings::class);
    $s->studio_nome = 'Studio Costa';
    $s->save();
    $s->studio_nome = 'Studio Costa e associati';
    $s->save();
    $riga = RegistroModifica::query()->where('oggetto_tipo', 'impostazioni_stampe')->orderByDesc('id')->firstOrFail();
    // Un ruolo su misura: può cambiare le impostazioni e vede la storia, ma la «Storia» delle impostazioni dello studio è dell'amministratore.
    RuoloSpatie::findOrCreate('Segreteria', 'web')->syncPermissions([Permission::ACCESS_ADMIN_PANEL->value, Permission::MANAGE_GENERAL_SETTINGS->value,
        Permission::VIEW_STORIA_SCHEDE->value]);
    $chi = User::factory()->create(['email_verified_at' => now()]);
    $chi->assignRole('Segreteria');

    $this->actingAs($chi->fresh())->getJson(route('admin.registro-modifiche.storia', ['tipo' => 'impostazioni_stampe']))->assertForbidden();
    $this->actingAs($chi->fresh())->getJson(route('admin.registro-modifiche.ripristina.anteprima', ['riga' => $riga->id, 'campo' => 'studio_nome']))
        ->assertForbidden();
    rpsRipristina($this, $chi->fresh(), $riga, 'studio_nome')->assertForbidden();
    expect(app(PrintSettings::class)->refresh()->studio_nome)->toBe('Studio Costa e associati');
});

it('una riga senza la firma del programma (aggiunta con il database) non si rimette: «Ripristina» scriverebbe sulla scheda il valore di chi l\'ha aggiunta', function () {
    // Fuori dal programma: una riga «modificata» sull'anagrafica di Ida, con un'email «di prima» inventata, e una firma che non è del programma.
    $valori = ['campi' => [['chiave' => 'email', 'etichetta' => 'Indirizzo email primario', 'prima' => 'truffa@example.com', 'dopo' => 'ida.romano@gmail.com',
        'valore_prima' => 'truffa@example.com', 'valore_dopo' => 'ida.romano@gmail.com', 'segreto' => false]], 'riepilogo' => null, 'perche' => null,
        'rimando' => null, 'lotto' => null];
    $id = \Illuminate\Support\Facades\DB::table('registro_modifiche')->insertGetId(['avvenuta_il' => '2026-12-16 09:30:00', 'registrata_il' => '2026-12-16 09:30:00',
        'autore_tipo' => 'persona', 'autore_id' => $this->marco->id, 'autore_nome' => 'Marco Galli', 'autore_ruolo' => 'collaboratore', 'origine' => 'pagina',
        'area' => 'persone', 'oggetto_tipo' => 'anagrafica', 'oggetto_id' => $this->ida->id, 'oggetto_nome' => 'Anagrafica di Ida Romano', 'azione' => 'modificata',
        'campi' => json_encode($valori['campi']), 'impronta_valori' => \App\Services\RegistroModifiche\ImprontaDelRegistro::deiValori($valori),
        'firma' => str_repeat('0', 64)]);

    $this->actingAs($this->andrea)->getJson(route('admin.registro-modifiche.ripristina.anteprima', ['riga' => $id, 'campo' => 'email']))->assertStatus(422)
        ->assertJsonPath('message', 'Questa riga non ha la firma del programma: è stata aggiunta o cambiata fuori dal programma, e non si rimette.');
    rpsRipristina($this, $this->andrea, RegistroModifica::query()->findOrFail($id), 'email')->assertStatus(422);
    $storia = $this->actingAs($this->andrea)->getJson(route('admin.registro-modifiche.storia', ['tipo' => 'anagrafica', 'id' => $this->ida->id]))->json('righe');
    expect(collect($storia)->firstWhere('id', $id)['ripristina'] ?? null)->toBe(['email' => false])
        ->and($this->ida->fresh()->email)->toBe('ida.romano@gmail.com');
});
