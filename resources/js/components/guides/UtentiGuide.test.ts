// @vitest-environment jsdom

/**
 * La guida «Utenti e accessi» (`UtentiGuide.vue`), il punto sul proprio account del collaboratore (Coda 258).
 *
 * Il proprio account del collaboratore è fuori dalla sua portata: la sua scheda utente la gestisce solo un
 * amministratore, e i propri dati si cambiano dalle impostazioni dell'account. Lì il profilo prende nome ed email
 * (`ProfileController::update`) e la password ha la sua voce (`PasswordController::update`, `settings/password`): la
 * guida le nomina come il menù delle impostazioni (`settings.layout.nav`: «Profilo», «Password»), come la frase del
 * rifiuto sulla propria scheda (`policies.users_own_from_profile`, `SchedaSoloNellaPortataTest.php`).
 *
 * Il paragrafo sull'invito (Coda 258): chi accetta un invito del modulo «Inviti» riceve il ruolo predefinito dei nuovi
 * utenti solo se è utente o fornitore, altrimenti utente (`InvitoRegisteredUserController`, `UserRegistrationService::register`
 * con `daUnInvito`). Il gestionale chiama «invito» anche l'email di attivazione di un account creato da qui, quella che rimanda
 * «Reinvita» (`users.actions.invite_user`, `notifications.new_user_created.link_expired`): con quella l'account tiene il ruolo che
 * ha (`UserReinviteController` azzera la password e non tocca i ruoli). La guida dice di quale invito parla.
 *
 * Si monta la guida con i segnaposto del foglio e si legge il testo; la guida è solo in italiano.
 */
import { expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import UtentiGuide from './UtentiGuide.vue';

function testoDellaGuida(): string {
    const passa = { template: '<div><slot /></div>' };
    const w = mount(UtentiGuide, {
        props: { open: true },
        global: {
            stubs: { Sheet: passa, SheetContent: passa, SheetHeader: passa, SheetTitle: passa, SheetDescription: passa },
        },
    });

    return w.text().replace(/\s+/g, ' ');
}

test('controllo — la guida dice ancora che il proprio account del collaboratore lo gestisce solo l\'amministratore', () => {
    expect(testoDellaGuida()).toContain('Vale anche per il proprio account del collaboratore');
});

test('il proprio account: nome ed email si cambiano in «Profilo», la password nella sua voce «Password»', () => {
    const testo = testoDellaGuida();

    expect(testo).toContain('nome ed email si cambiano nelle impostazioni, in «Profilo», e la password in «Password»');
    expect(testo).not.toContain('nome, email e password si cambiano dal profilo');
});

test('l\'invito che non dà poteri è quello del modulo «Inviti»; l\'email di attivazione di «Reinvita» lascia il ruolo che c\'è', () => {
    const testo = testoDellaGuida();

    expect(testo).toContain('Un invito del modulo «Inviti» non dà poteri: chi lo accetta riceve il ruolo predefinito dei nuovi utenti solo se è utente o fornitore, altrimenti riceve utente.');
    expect(testo).toContain('L\'email di attivazione di un account già creato, anche quella di «Reinvita», non cambia il ruolo: l\'account tiene quello che ha.');
    expect(testo).not.toContain('Un invito non dà poteri');
});
