// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il giro di correzioni della tappa 5 (1.12.0-beta.14): le frasi dell'importazione che con «Importa senza i saldi» non dicevano più il vero.
 * - il riconoscimento non dice «senza, non si prosegue» per l'Elenco unità: senza, si prosegue e i saldi non entrano;
 * - l'esito di un'importazione completata senza i saldi non dice «Si è fermata a…»: dice che i saldi non sono stati importati per scelta, e che li
 *   scrive l'amministratore;
 * - la verifica con i soli saldi da decidere non dice «Nessun errore» come se fosse tutto a posto;
 * - la card «Uniti a esistenti» e le sue vicine stanno a 12 px, senza il tutto maiuscolo;
 * - la guida non dice «senza, non c'è importazione»;
 * - il piede della verifica conta i rilievi nuovi della tappa 5: i saldi su un'unità o a un nome che i file non hanno, le cose che esistono
 *   già, il permesso che manca per il condominio e per le persone che ci sono già.
 */
import fs from 'node:fs';
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: { flash: {} } }),
    router: { post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Riconoscimento = (await import('./ImportRiconoscimento.vue')).default;
const Esito = (await import('./ImportEsito.vue')).default;
const Verifica = (await import('./ImportVerifica.vue')).default;

const STUBS = { AppLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: { template: '<div />' }, ImportGuide: { template: '<div />' }, 'v-select': { template: '<div />' } };
const MOCKS = { route: (nome: string) => `/${nome}` };
const testo = (file: string) => fs.readFileSync(file, 'utf8').replace(/\s+/g, ' ');

let pagina: VueWrapper | null = null;
afterEach(() => {
    pagina?.unmount();
    pagina = null;
});

describe('il riconoscimento', () => {
    test('l\'Elenco unità che manca non ferma più: dice che cosa non entra', () => {
        pagina = mount(Riconoscimento, {
            props: {
                lotto: { uuid: 'u-1', stato: 'in_corso' }, file: [], tipi: [],
                mancanti: [{ cosa: 'l\'Elenco unità', serve_per: 'persone, unità e chi possiede cosa', bloccante: true }],
            } as never,
            global: { stubs: STUBS, mocks: MOCKS },
        });

        const cartello = pagina.find('[data-mancanti="bloccanti"]').text();
        expect(cartello).not.toContain('non si prosegue');
        expect(cartello).toContain('senza, persone e saldi non entrano');
    });
});

describe('l\'esito di un\'importazione completata senza i saldi', () => {
    const livello = (livello: string, etichetta: string, altro: Record<string, unknown> = {}) => ({
        livello, etichetta, creati: 2, uniti: 0, saltati: 0, riuscito: true, gia_a_posto: false, saltato: false, prerequisiti_mancanti: [], rilievi: [], avvisi: [], ...altro,
    });

    test('non dice «Si è fermata»: la riga dei saldi dice «non importati, per tua scelta» e porta il rimedio', () => {
        pagina = mount(Esito, {
            props: {
                lotto: { uuid: 'u-1', stato: 'completato', condominio: 'Condominio Esempio', condominio_id: 1, url_condominio: '/c/1', url_controlli: null, url_rapporto: null, url_piano_conti: null, riferimento: 'X', completato_at: '2026-11-23' },
                rapporto: {
                    livelli: [
                        livello('condominio', 'Condominio'),
                        livello('saldi', 'Saldi di apertura', {
                            creati: 0, riuscito: false, saltato: true, per_scelta: true,
                            prerequisiti_mancanti: [{ codice: 'saldi.non_importati_per_scelta', cosa_manca: 'I saldi di apertura non li ho importati: hai scelto «Importa senza i saldi».', rimedio: 'I saldi li scrivi tu, dalla pagina «Saldi iniziali» del condominio.' }],
                        }),
                    ],
                    saldi: null,
                },
                creati: 2,
                annullamento: { possibile: false, motivo: null, aiuto: null, impedimenti: {}, conteggi: {}, condominio: null },
            } as never,
            global: { stubs: STUBS, mocks: MOCKS },
        });

        const t = pagina.text();
        expect(t).not.toContain('Si è fermata');
        expect(t).toContain('non importati, per tua scelta');
        expect(t).toContain('I saldi di apertura non li ho importati: hai scelto «Importa senza i saldi».');
        expect(t).toContain('I saldi li scrivi tu, dalla pagina «Saldi iniziali» del condominio.');
    });

    test('le etichette dei contatori stanno a 12 px, senza il tutto maiuscolo', () => {
        const sorgente = fs.readFileSync('resources/js/pages/import/ImportEsito.vue', 'utf8');

        expect(sorgente.match(/text-\[1[01]px\]/g)).toBeNull();
        expect(sorgente.match(/<p class="[^"]*uppercase[^"]*">(Creati|Uniti a esistenti|Saltati|Scarto sui saldi)/g)).toBeNull();
    });
});

describe('la verifica', () => {
    test('con i soli saldi da decidere non dice «Nessun errore»: dice che i saldi non entrerebbero e che cosa si sceglie', () => {
        pagina = mount(Verifica, {
            props: {
                lotto: { uuid: 'u-1', stato: 'in_corso' }, letture: [], confermabile: false, senza_errori: true, passi: [], destinazione: null,
                livelli: [{
                    chiave: 'saldi', etichetta: 'Saldi di apertura', confermabile: false, errori: [], avvisi: [],
                    contatori: { totali: 6, valide: 5, da_decidere: 1, errori: 0 },
                    da_decidere: [{ severita: 'da_decidere', codice: 'saldi.non_quadrano', messaggio: 'Lo scarto è di € 10,00.', rimedio: null, riga: null, colonna: null, foglio: null }],
                }],
            } as never,
            global: { stubs: STUBS, mocks: MOCKS },
        });

        const t = pagina.text();
        expect(t).not.toContain('Nessun errore. Restano');
        expect(t).toContain('I saldi non entrerebbero: nella schermata di conferma scegli se correggere il file o importare il resto senza i saldi.');
    });
});

describe('il piede della verifica conta i rilievi della tappa 5', () => {
    type R = { severita: string; codice: string; messaggio: string };
    const rilievo = (severita: string, codice: string, messaggio = 'Un rilievo.') => ({ severita, codice, messaggio, rimedio: null, riga: null, colonna: null, foglio: null });
    const livello = (chiave: string, r: { errori?: R[]; da_decidere?: R[]; avvisi?: R[] }) => ({
        chiave, etichetta: chiave, confermabile: false, errori: r.errori ?? [], da_decidere: r.da_decidere ?? [], avvisi: r.avvisi ?? [],
        contatori: { totali: 6, valide: 5, da_decidere: (r.da_decidere ?? []).length, errori: (r.errori ?? []).length },
    });
    const piede = (livelli: unknown[], senzaErrori = true) => {
        pagina = mount(Verifica, {
            props: { lotto: { uuid: 'u-1', stato: 'in_corso' }, letture: [], confermabile: false, senza_errori: senzaErrori, passi: [], destinazione: null, livelli } as never,
            global: { stubs: STUBS, mocks: MOCKS },
        });

        return pagina.find('[data-piede]').text().replace(/\s+/g, ' ');
    };

    test('il saldo su un\'unità o a un nome che i file non hanno è fra i saldi che non entrerebbero', () => {
        const t = piede([livello('saldi', { da_decidere: [rilievo('da_decidere', 'saldi.unita_sconosciuta'), rilievo('da_decidere', 'saldi.persona_sconosciuta')] })]);

        expect(t).not.toContain('Nessun errore');
        expect(t).toContain('I saldi non entrerebbero: nella schermata di conferma scegli se correggere il file o importare il resto senza i saldi.');
    });

    test('le cose che esistono già si contano per quello che sono, anche insieme ai saldi', () => {
        expect(piede([livello('condominio', { da_decidere: [rilievo('da_decidere', 'condominio.duplicato'), rilievo('da_decidere', 'esercizi.duplicato')] }),
            livello('soggetti', { da_decidere: [rilievo('da_decidere', 'soggetto.duplicato')] })]))
            .toBe('Nessun errore. 3 cose esistono già in archivio: le decidi nella schermata di conferma.');

        const t = piede([livello('soggetti', { da_decidere: [rilievo('da_decidere', 'soggetto.duplicato')] }),
            livello('saldi', { da_decidere: [rilievo('da_decidere', 'saldi.non_quadrano')] })]);
        expect(t).toContain('1 cosa esiste già in archivio: la decidi nella schermata di conferma.');
        expect(t).toContain('I saldi non entrerebbero');
        expect(t).not.toContain('Nessun errore');
    });

    test('la persona che c\'è già senza «Modifica anagrafiche»: il piede dice che cosa serve per unire', () => {
        const t = piede([livello('soggetti', {
            da_decidere: [rilievo('da_decidere', 'soggetto.duplicato')],
            avvisi: [rilievo('avviso', 'soggetto.senza_permesso')],
        })]);

        expect(t).toContain('1 cosa esiste già in archivio');
        expect(t).toContain('Per unire la persona che c\'è già in archivio serve il permesso «Modifica anagrafiche»: senza, nella schermata di conferma la lasci com\'è.');
    });

    test('il condominio che c\'è già senza «Modifica condomini» non è un errore da correggere nel file', () => {
        const messaggio = '«Condominio Esempio» c\'è già in archivio, e per importarci dentro serve il permesso «Modifica condomini».';
        const t = piede([livello('condominio', { errori: [rilievo('errore', 'condominio.senza_permesso', messaggio)] })], false);

        expect(t).not.toContain('Ci sono errori da correggere nel file');
        expect(t).toContain(messaggio + ' Chiedilo a chi amministra lo studio, oppure chiedigli di fare lui l\'importazione.');

        const insieme = piede([livello('condominio', { errori: [rilievo('errore', 'condominio.senza_permesso', messaggio), rilievo('errore', 'condominio.nome_assente')] })], false);
        expect(insieme).toContain('Ci sono errori da correggere nel file: sistemali e ricarica.');
        expect(insieme).toContain(messaggio);
    });
});

describe('la guida', () => {
    test('non dice «senza, non c\'è importazione», e dice di «Importa senza i saldi»', () => {
        const guida = testo('resources/js/components/guides/ImportGuide.vue');

        expect(guida).not.toContain('non c\'è importazione');
        expect(guida).not.toContain('Un foglio lasciato in bianco non blocca gli altri.');
        expect(guida).toContain('Importa senza i saldi');
    });

    test('dice i permessi che servono per unire: «Modifica condomini» per il condominio, «Modifica anagrafiche» per una persona', () => {
        const guida = testo('resources/js/components/guides/ImportGuide.vue');

        expect(guida).toContain('Importare dentro un condominio che c\'è già chiede «Modifica condomini»; unire una persona che c\'è già chiede «Modifica anagrafiche» (senza, la lasci com\'è).');
    });
});
