// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Un testo che indica una strada nomina una cosa che a video c'è con quel nome.
 *
 * - La guida della dashboard del condominio rimanda a una scelta «nelle impostazioni del condominio»: quel nome c'è nella pagina delle impostazioni, fuori
 *   dai commenti.
 * - «Fatto» sul bonifico segnalato ancora in verifica dice di registrare l'incasso con un pulsante: il nome è quello del pulsante della riga (la prossima
 *   azione del bonifico) o di una voce del menu, nelle due Inbox.
 * - Ogni frase della guida dell'Action inbox ha la sua riga di prova con il numero della riga, come le altre guide.
 * - Le parole: a video «la prossima azione», mai «i passi»; le maiuscole solo a inizio frase, anche nella legenda dell'estratto conto e nelle card in alto
 *   della dashboard del condominio, che la guida dice riassumere la pagina.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const leggi = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');
const senzaCommenti = (s: string) => s.replace(/<!--[\s\S]*?-->/g, ' ').replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/(^|[^:'"`])\/\/.*$/gm, '$1 ');
const pulito = (s: string) => s.replace(/\\'/g, '\'').replace(/\s+/g, ' ');
const nomi = (frase: string) => [...frase.matchAll(/«([^«»]+)»/g)].map((m) => m[1].trim());

describe('un testo che indica una strada nomina una cosa che c\'è', () => {
    test('la guida della dashboard rimanda alle impostazioni del condominio con un nome che quella pagina mostra', () => {
        const guida = pulito(senzaCommenti(leggi('resources/js/components/guides/DashboardCondominioGuide.vue')));
        const impostazioni = pulito(senzaCommenti(leggi('resources/js/pages/gestionale/struttura/ImpostazioniCondominio.vue')));
        const rimandi = [...guida.matchAll(/«([^«»]+)» nelle impostazioni del condominio/g)].map((m) => m[1]);
        expect(rimandi.length).toBeGreaterThan(0);
        expect(rimandi.filter((n) => !impostazioni.includes(n))).toEqual([]);
    });

    test('«Fatto» sul bonifico ancora in verifica nomina il pulsante che la riga mostra', () => {
        const tipi = leggi('app/Services/Gestionale/Inbox/TipiDeiCompiti.php');
        const azioneDelBonifico = pulito(/'verifica_pagamento' => \['nome' => '[^']+', 'azione' => '((?:[^'\\]|\\.)*)'/.exec(tipi)?.[1] ?? '');
        expect(azioneDelBonifico).toBeTruthy();
        const menu = pulito(leggi('resources/js/lib/inbox/risposteDelCompito.ts'));
        const vociDelMenu = [...menu.matchAll(/etichetta: (?:eUnBonificoSegnalato\(c\) \? )?'([^']+)'(?: : '([^']+)')?/g)].flatMap((m) => [m[1], m[2]]).filter(Boolean) as string[];
        // Un nome vale se è il pulsante della riga o una voce del menu, anche solo fino ai due punti («Non serve» per «Non serve: rifiuta la segnalazione»).
        const aVideo = (n: string) => n === azioneDelBonifico || vociDelMenu.some((v) => v === n || v.split(/[:,]/)[0].trim() === n);

        const frase = /FRASE_PRIMA_L_INCASSO = '((?:[^'\\]|\\.)*)'/.exec(leggi('app/Services/Gestionale/RispostePromemoria.php'))?.[1] ?? '';
        const finestra = pulito(leggi('resources/js/components/inbox/RisposteDelCompito.vue'));
        const rigaDellaFinestra = /voce === 'fatto' && bonifico"[^>]*>([^<]+)</.exec(finestra)?.[1] ?? '';
        expect(frase).not.toBe('');
        expect(rigaDellaFinestra).not.toBe('');
        expect(nomi(pulito(frase)).filter((n) => !aVideo(n))).toEqual([]);
        expect(nomi(rigaDellaFinestra).filter((n) => !aVideo(n))).toEqual([]);
    });

    test('ogni riga di prova della guida dell\'Action inbox ha il numero della riga', () => {
        const sorgente = leggi('resources/js/components/guides/ActionInboxGuide.vue');
        const tutte = sorgente.match(/<!-- prova:/g) ?? [];
        const conLaRiga = [...sorgente.matchAll(/<!-- prova: ([^\s:]+):(\d+) «([^»]+)» -->/g)];
        expect(conLaRiga.length).toBe(tutte.length);
    });

    test('la guida dell\'estratto conto dice ogni perché per cui un addebito non si annulla, anche la successione', () => {
        const servizio = leggi('app/Services/Gestionale/Addebiti/AddebitiIndividuali.php');
        const guida = pulito(senzaCommenti(leggi('resources/js/components/guides/EstrattoContoGuide.vue')).replace(/<[^>]+>/g, ' '));
        const sezione = guida.slice(guida.indexOf('Quando un addebito non si annulla'), guida.indexOf('Il credito: «Compensa»'));
        // I perché che il servizio scrive al posto di «Annulla», e la parola che la guida usa per ognuno.
        const perche: Array<[string, RegExp]> = [['FRASE_NON_ANNULLABILE', /incasso/], ['FRASE_IN_ACCORDO', /accordo di rientro/], ['FRASE_ESERCIZIO_CHIUSO', /esercizio chiuso/],
            ['FRASE_PASSATO_AGLI_EREDI', /eredi|successione/]];
        expect(perche.filter(([c]) => !servizio.includes(`const ${c} =`))).toEqual([]);
        expect(perche.filter(([, parola]) => !parola.test(sezione)).map(([c]) => c)).toEqual([]);
    });

    test('«Verifica i bonifici» porta a un elenco che ha anche le richieste di pagare con il credito, e la guida lo dice', () => {
        // La richiesta di saldare la rata con il credito è un compito dello stesso tipo del bonifico segnalato: l'Inbox la elenca con i bonifici.
        const portale = leggi('app/Http/Controllers/Eventi/Utenti/PaymentReportingController.php');
        expect(portale).toContain('tipo: EventoTipo::VERIFICA_PAGAMENTO');
        expect(portale).toContain('\'intent_usa_credito\' => $intentUsaCredito');
        const guida = pulito(senzaCommenti(leggi('resources/js/components/guides/DashboardCondominioGuide.vue')).replace(/<[^>]+>/g, ' '));
        const frase = guida.slice(guida.indexOf('Bonifici segnalati dal portale'), guida.indexOf('Rate non ancora emesse'));
        expect(frase).toContain('«Verifica i bonifici»');
        expect(frase.slice(frase.indexOf('«Verifica i bonifici»'))).toMatch(/credito/);
    });
});

describe('le parole delle pagine toccate', () => {
    test('le dispense: a video «la prossima azione», mai «i passi»', () => {
        const pagina = leggi('resources/js/pages/gestionale/esercizi/DispenseSeiMesi.vue');
        const stringhe = [...senzaCommenti(pagina).matchAll(/'((?:[^'\\]|\\.)*)'/g)].map((m) => m[1]).filter((s) => / /.test(s));
        expect(stringhe.filter((s) => /\b[Pp]ass[oi]\b/.test(s))).toEqual([]);
    });

    test('la legenda dell\'estratto conto: la maiuscola solo a inizio frase', () => {
        const pagina = senzaCommenti(leggi('resources/js/pages/gestionale/pianiRate/EstrattoContoAnagrafica.vue'));
        const guida = leggi('resources/js/components/guides/EstrattoContoGuide.vue');
        expect(pagina).not.toContain('>Non Pagata<');
        expect(guida).not.toContain('«Non Pagata»');
    });

    test('le card in alto della dashboard del condominio: maiuscole solo a inizio frase, niente inglese', () => {
        const pagina = leggi('resources/js/pages/gestionale/dashboard/Dashboard.vue');
        const card = /const pageGuides = \[([\s\S]*?)\];/.exec(pagina)?.[1] ?? '';
        const testi = [...card.matchAll(/(?:title|description): '((?:[^'\\]|\\.)*)'/g)].map((m) => m[1]);
        expect(testi.length).toBe(6);
        // Una parola grammaticale maiuscola in mezzo alla frase (il controllo delle maiuscole), e le parole inglesi.
        const grammaticali = /(?<![.!?:;«(—-]\s)\b(Sotto|Sopra|Che|Dei|Del|Della|Di|Il|La|Le|Non|Con|Per|In|Su)\b(?!\s*$)/;
        // I nomi fra virgolette basse sono citazioni: la loro maiuscola è quella del nome.
        expect(testi.filter((t) => grammaticali.test(t.replace(/«[^»]*»/g, '«»').replace(/^\S+\s/, '')))).toEqual([]);
        expect(testi.filter((t) => /\b(task|budget|Budget)\b/.test(t))).toEqual([]);
    });

    test('le guide toccate: l\'elisione davanti a vocale («un\'azione», mai «una azione»)', () => {
        const guide = ['DashboardCondominioGuide', 'ActionInboxGuide', 'ImpostazioniCondominioGuide', 'EstrattoContoGuide']
            .map((g) => pulito(senzaCommenti(leggi(`resources/js/components/guides/${g}.vue`)).replace(/<[^>]+>/g, ' ')));
        expect(guide.flatMap((t) => t.match(/\buna [aeiouàèéìòù]\w*/gi) ?? [])).toEqual([]);
    });
});
