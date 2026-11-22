// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 121 (punto 3, decisione 184) — la guida della posta non dà più una regola falsa sul mittente.
 *
 * La guida diceva che l'indirizzo «Mittente (Address)» deve essere identico all'account con cui ci si autentica nel server SMTP. È vero per
 * una casella di posta (Aruba, Gmail), falso per un servizio di invio (SMTP2GO, Brevo, Mailgun, Armada), dove il nome utente è un
 * identificativo del servizio e il mittente dev'essere un indirizzo, o un dominio, verificato nel pannello del servizio: Brevo, con un
 * mittente non verificato, rifiuta l'invio con «Validate your sender or authenticate your domain». La guida ora dice che dipende dal
 * gestore e le due strade, e dove si legge il motivo di un invio fallito.
 *
 * Si legge il sorgente, come nei test delle altre guide: conta la frase, non il disegno.
 */
import { describe, expect, test } from 'vitest';
import fs from 'node:fs';

const sorgente = fs.readFileSync('resources/js/components/guides/MailSetupGuide.vue', 'utf8').replace(/\s+/g, ' ');

describe('MailSetupGuide — il mittente (Coda 121)', () => {
    test('non dice più che il mittente deve essere identico all\'account', () => {
        expect(sorgente).not.toMatch(/identico all'account/i);
        expect(sorgente).not.toContain('con cui ti autentichi nel server SMTP');
    });

    test('dice che dipende dal gestore: una casella di posta usa l\'indirizzo della casella, con Gmail anche un alias aggiunto', () => {
        // L'etichetta è quella della pagina delle caselle (decisione 572): «Indirizzo del mittente».
        expect(sorgente).toContain('<strong>Indirizzo del mittente:</strong> dipende dal gestore.');
        expect(sorgente).toContain('Con una casella di posta (Aruba, Gmail) è l\'indirizzo della casella con cui ti autentichi (con Gmail, anche un alias che hai aggiunto nell\'account).');
    });

    test('dice che con un servizio di invio il nome utente lo dà il servizio, anche in forma di indirizzo, e il mittente va verificato nel pannello del servizio', () => {
        expect(sorgente).toContain('Con un servizio di invio (SMTP2GO, Brevo, Mailgun) il nome utente è quello che ti dà il servizio, anche quando ha la forma di un indirizzo email: il mittente dev\'essere un indirizzo, o un dominio, che hai verificato nel pannello del servizio');
        // Brevo (l'indirizzo dell'account, o uno «…@smtp-brevo.com») e Mailgun («postmaster@» e il dominio) danno un nome utente in forma di
        // indirizzo: la guida non può dire che non lo è.
        expect(sorgente).not.toMatch(/nome utente non è un indirizzo/i);
        expect(sorgente).toContain('il servizio rifiuta l\'invio anche se server e password sono giusti');
    });

    test('dice dove si legge il motivo di un invio fallito: sotto la prova e, se la prova arriva all\'invio, nel registro delle email', () => {
        // Un indirizzo non valido e la password da riscrivere (422), come le troppe prove (429), si leggono sotto la prova e non lasciano righe nel registro.
        expect(sorgente).toContain('<strong>Se la prova fallisce:</strong> il motivo è scritto sotto la prova e, se la prova arriva all\'invio, anche in «Audit & Logs di Sistema», scheda «Email Logs».');
        expect(sorgente).not.toContain('e resta in «Audit & Logs di Sistema»');
    });

    test('controprova: il consiglio sul pulsante di prova e le altre schede non cambiano', () => {
        expect(sorgente).toContain('<strong>Pulsante di prova:</strong> Prima di salvare la configurazione, digita il tuo indirizzo personale ed esegui il test per verificare la connessione immediata.');
        expect(sorgente).toContain('Host: <code>smtps.aruba.it</code>');
        expect(sorgente).toContain('Host: <code>smtp.gmail.com</code>');
        expect(sorgente).toContain('Host: <code>mail.smtp2go.com</code>');
    });
});

describe('MailSetupGuide — che cosa registra il programma (ricevute, 1.12.0-beta.2)', () => {
    test('dice quello che è vero: registra ogni messaggio che il server accetta, e senza un server lo segna come non partito; «accettato» non è «consegnato»', () => {
        expect(sorgente).toContain('Il programma registra ogni messaggio che il server accetta, e senza un server lo segna come non partito; «accettato» non vuol dire «consegnato».');
    });

    test('non promette più una tracciabilità totale', () => {
        expect(sorgente).not.toContain('100% di tracciabilità');
    });
});

describe('MailSetupGuide — la ricevuta letta, «Togli la casella PEC», la posta mai provata (decisione 577)', () => {
    test('la ricevuta di consegna trascinata: il programma ne legge il giorno e l\'ora e la archivia sulla riga', () => {
        expect(sorgente).toContain('il programma ne legge il giorno e l\'ora, e la archivia sulla riga, da dove si scarica.');
    });

    test('«Togli la casella PEC» chiede conferma e la casella torna «Da impostare»', () => {
        expect(sorgente).toContain('<b class="block text-slate-900 dark:text-white mb-0.5">Togli la casella PEC</b>');
        expect(sorgente).toContain('La casella torna «Da impostare» e «Manda le PEC» lo dice; le PEC già partite restano.');
    });

    test('«Modifica» apre i campi di una casella impostata, anche accanto a «Prova l\'invio»; i dati nuovi prendono il posto dei vecchi', () => {
        expect(sorgente).toContain('<strong>Cambiare una casella:</strong> «Modifica», sulla sua riga, apre i campi.');
        expect(sorgente).toContain('Con un gestore nuovo scrivi i suoi dati e salva: prendono il posto dei vecchi, e la prova fatta con i dati di prima non vale per i nuovi.');
    });

    test('la posta mai provata ha il consiglio, e le email partono lo stesso', () => {
        expect(sorgente).toContain('«Fai una prova quando puoi: gli invii continuano.». Non è un allarme: le email partono lo stesso.');
    });
});

describe('MailSetupGuide — il promemoria per email delle convocazioni da spedire', () => {
    test('dice a chi va, quando, che si spegne qui e che senza un server di posta non parte, con la prova di ogni frase', () => {
        expect(sorgente).toContain('<b class="block text-slate-900 dark:text-white mb-0.5">Promemoria per email delle convocazioni da spedire</b>');
        expect(sorgente).toContain('A chi ha preparato l\'assemblea, due giorni lavorativi prima del giorno limite del compito «Spedire la convocazione entro il …» e la mattina di quel giorno, se restano avvisi da spedire.');
        expect(sorgente).toContain('Acceso di serie; si spegne qui, con il suo interruttore, anche dove la posta la prepara chi gestisce l\'installazione.');
        expect(sorgente).toContain('Senza un server di posta non parte, e non dà errori: il compito resta nell\'Action inbox.');
        expect(sorgente).toContain('<!-- prova: app/Services/Assemblee/Convocazione/PromemoriaDellaConvocazione.php');
        expect(sorgente).toContain('<!-- prova: app/Http/Controllers/Impostazioni/MailSettingsController.php');
    });

    test('ogni prova della guida ha il numero della riga, e la riga c\'è', () => {
        const prove = [...sorgente.matchAll(/<!-- prova: ([^\s:]+):(\d+) «([^»]+)» -->/g)];
        expect(prove.length).toBeGreaterThanOrEqual(6);
        const mancanti = prove.filter(([, file, riga, testo]) => !(fs.readFileSync(file, 'utf8').split('\n')[Number(riga) - 1] ?? '').replace(/\\'/g, '\'').includes(testo))
            .map(([, f, r, t]) => `${f}:${r} «${t}»`);
        expect(mancanti).toEqual([]);
    });
});

