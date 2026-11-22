// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il passo 6 «Delibere eseguite», con l'ordinaria di Via Roma 12:
 *
 * - il riepilogo «Che cosa cambia nel gestionale»: le righe spuntate di serie, il conto che segue le spunte, «Avvisa i condòmini» come pulsante con la casella,
 *   la delega, il piano che resta fuori con la differenza e «Apri il piano», «Conferma» con il corpo che il server ricalcola;
 * - la tabella dopo la conferma: «Eseguita il 16/12 · Rendiconto, versione 2», «Da eseguire · 2 di 3» con le cose una per riga, il fondo speciale, la riga tolta
 *   con «Applica», «Ferma il preventivo» sulla cosa «Correggi il preventivo e fermalo di nuovo», il menu di riga con la riga che dice a che cosa serve;
 * - al telefono le delibere da eseguire in cima e «Mostra le altre»;
 * - il verbale annullato, la frase e le schede che usano le delibere; la riunione su carta con «Da eseguire» e «Trenta giorni» in testa; gli esiti della
 *   conferma.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { confermato, cosa, daConfermare, rigaDelPasso } from './seguitoDiProva';
import PassoDelleDelibere from './PassoDelleDelibere.vue';

const inertia = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router: inertia, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n, generatePath: (p: string) => p, hasPermission: () => true }) }));
vi.mock('axios', () => ({ default: { get: vi.fn(async () => ({ data: { collaboratori: [{ id: 7, nome: 'Marco Galli' }] } })) } }));
(globalThis as Record<string, unknown>).route = (nome: string, p: Record<string, unknown> = {}) => `/${nome}?${new URLSearchParams(p as Record<string, string>)}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; inertia.post.mockReset(); });

async function monta(props: Record<string, unknown>) {
    w = mount(PassoDelleDelibere, { attachTo: document.body, props: { condominioId: 3, condominioNome: 'Via Roma 12', assembleaId: 9,
        sottotitolo: 'Dall\'assemblea del 15/12/2026, verbale n. 42.', ...props } });
    await flushPromises();
    return w;
}
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');
const el = (sel: string) => document.body.querySelector(sel) as HTMLElement;

describe('il riepilogo «Che cosa cambia nel gestionale»', () => {
    test('le righe spuntate di serie, la delega, il piano che resta fuori con la differenza e «Apri il piano», il conto', async () => {
        await monta({ seguito: daConfermare() });
        expect(testo()).toContain('Che cosa cambia nel gestionale');
        expect(testo()).toContain('Punto 4 · Portone: € 14.500,00 con Serramenti Marino');
        expect(el('[data-chiave="4:gestione:7:delibera"] button[role="checkbox"]').getAttribute('data-state')).toBe('checked');
        expect(el('[data-testid="resta-fuori"]').textContent).toContain('Il piano chiede € 49.200,00, l\'assemblea ha approvato € 48.000,00.');
        expect(el('[data-testid="apri-la-scheda"]').textContent).toContain('Apri il piano');
        expect(el('[data-testid="apri-la-scheda"]').getAttribute('href')).toContain('gestionale.schede.apri');
        expect(testo()).toContain('Delega con tetto: € 2.000,00 fino al 31/12/2027');
        expect(el('[data-testid="conto"]').textContent).toBe('1 cambio nei conti · 1 resta fuori · 1 cosa da fare · 1 comunicazione da rileggere · 1 delega in vigore');
    });

    test('«Avvisa i condòmini» è un pulsante con la casella: spento, il conto lo toglie', async () => {
        await monta({ seguito: daConfermare() });
        const avviso = el('[data-testid="avvisa-i-condomini"]');
        expect(avviso.getAttribute('data-acceso')).toBe('si');
        (avviso.querySelector('button[role="checkbox"]') as HTMLElement).click();
        await flushPromises();
        expect(el('[data-testid="avvisa-i-condomini"]').getAttribute('data-acceso')).toBe('no');
        expect(el('[data-testid="conto"]').textContent).not.toContain('comunicazione');
    });

    test('«Conferma» manda le chiavi spuntate e la data della cosa; la visita parziale rilegge il seguito, i passi e gli esiti', async () => {
        await monta({ seguito: daConfermare() });
        (el('[data-chiave="4:gestione:7:delibera"] button[role="checkbox"]')).click();
        await flushPromises();
        el('[data-testid="conferma"]').click();
        expect(inertia.post).toHaveBeenCalledWith(expect.stringContaining('gestionale.assemblee.seguito.conferma'),
            expect.objectContaining({ chiavi: ['4:cosa:crea_piano_straordinario', '4:cosa:avvisa_condomini'], altre: [], modifiche: {} }),
            expect.objectContaining({ only: ['seguito', 'passi', 'seguito_esiti', 'flash'] }));
    });
});

describe('la tabella del passo 6', () => {
    test('«Eseguita» con il giorno e la scheda; «Da eseguire · 2 di 3» con le cose una per riga; il fondo speciale; la riga tolta con «Applica»', async () => {
        await monta({ seguito: confermato() });
        const tabella = el('[data-testid="tabella-delle-delibere"]').textContent?.replace(/\s+/g, ' ') ?? '';
        expect(el('[data-delibera="1"] [data-testid="seguito"]').textContent).toBe('Eseguita');
        expect(el('[data-delibera="1"] [data-testid="dettaglio"]').textContent).toBe('il 16/12 · Rendiconto, versione 2');
        expect(tabella).toContain('Da eseguire · 2 di 3');
        expect(tabella).toContain('Avviso in bacheca: pubblicato il 17/12/2026');
        expect(tabella).toContain('Firma con Serramenti Marino: il 08/01/2027, segnata da Marco Galli');
        expect(tabella).toContain('Crea il piano straordinario · entro il 15/01/2027 · a Andrea Costa');
        expect(tabella).not.toContain('Crea il piano straordinario (€ 14.500,00)');
        expect(tabella).toContain('Fondo speciale: € 9.000,00 chiesti su € 14.500,00 deliberati.');
        el('[data-testid="applica"]').click();
        expect(inertia.post).toHaveBeenCalledWith(expect.stringContaining('gestionale.assemblee.seguito.applica'), { chiave: '4:gestione:7:delibera' }, expect.anything());
    });

    test('«Ferma il preventivo» sulla cosa «Correggi il preventivo e fermalo di nuovo» manda il punto alla rotta del fascicolo', async () => {
        await monta({ seguito: confermato() });
        el('[data-testid="ferma-il-preventivo"]').click();
        expect(inertia.post).toHaveBeenCalledWith(expect.stringContaining('gestionale.assemblee.fascicolo.preventivo'), { punto_id: 20 }, expect.anything());
    });

    test('l\'azione principale della cosa aperta: «Crea il piano straordinario» apre la pagina con la cosa scelta', async () => {
        await monta({ seguito: confermato() });
        const azione = el('[data-delibera="4"] [data-testid="azione"]');
        expect(azione.textContent?.trim()).toBe('Crea il piano');
        // Piccolo e chiaro, mai il pulsante nero grande in una riga.
        expect(azione.className).toContain('h-7');
        expect(azione.className).not.toMatch(/(^|\s)bg-slate-900/);
        expect(azione.getAttribute('href')).toContain('cosa=31');
    });

    test('al telefono le delibere da eseguire stanno in cima; oltre quattro «Mostra le altre»', async () => {
        const s = confermato();
        s.tabella = [...s.tabella, ...[5, 6, 7].map((n) => rigaDelPasso({ delibera_id: n, punto: n }))];
        await monta({ seguito: s });
        const righe = [...document.body.querySelectorAll('[data-testid="riga-al-telefono"]')].map((r) => r.getAttribute('data-delibera'));
        expect(righe).toEqual(['2', '4', '1', '5']);
        el('[data-testid="mostra-le-altre"]').click();
        await flushPromises();
        expect(document.body.querySelectorAll('[data-testid="riga-al-telefono"]')).toHaveLength(6);
    });
});

describe('le tre card in testa e la tabella a colonne fisse (decisione 645)', () => {
    test('tre card della stessa altezza in una griglia: «Da fare adesso» con il suo pulsante, «Le delibere» con i conti, «Trenta giorni»; anche nella pagina dell\'assemblea', async () => {
        const s = confermato();
        s.da_eseguire = [{ ...cosa({}), punto: 4 }];
        await monta({ seguito: s });
        const card = [...document.body.querySelectorAll('[data-testid="voci-in-testa"] > li')].map((l) => (l.textContent ?? '').replace(/\s+/g, ' ').trim());
        expect(card).toHaveLength(3);
        expect(el('[data-testid="voci-in-testa"]').className).toContain('sm:grid-cols-3');
        expect(card[0]).toContain('Da fare adesso Crea il piano straordinario · punto 4 · a Andrea Costa');
        expect(el('[data-testid="card-azione"]').textContent?.trim()).toBe('Crea il piano straordinario');
        expect(card[1]).toContain('Le delibere 2 da eseguire · 1 eseguita');
        expect(card[2]).toContain('Trenta giorni Passati per tutti');
    });

    test('le colonne sono fisse: la testata e ogni riga hanno la stessa griglia, e il pulsante nero grande non c\'è', async () => {
        await monta({ seguito: confermato() });
        const griglia = (e: Element) => e.className.split(' ').find((c) => c.startsWith('grid-cols-[')) ?? '';
        const testata = document.body.querySelector('[data-testid="testata-della-tabella"]')!;
        const righe = [...document.body.querySelectorAll('[data-testid="riga-del-passo"]')];
        expect(griglia(testata)).not.toBe('');
        for (const r of righe) expect(griglia(r)).toBe(griglia(testata));
        expect(griglia(testata)).not.toContain('auto');
        for (const r of righe) expect(r.innerHTML).not.toMatch(/class="[^"]*(^|\s|")bg-slate-900/);
    });

    test('«Da applicare» è una riga del seguito in ambra, e «Applica» sta fra le azioni a destra', async () => {
        await monta({ seguito: confermato() });
        const riga = el('[data-delibera="4"] [data-testid="da-applicare"]');
        expect(riga.textContent?.replace(/\s+/g, ' ')).toContain('Da applicare: Portone d\'ingresso 2027: legata alla delibera');
        expect(riga.className).toContain('text-amber-');
        expect(riga.className).not.toContain('bg-amber-50');
        expect(riga.querySelector('button')).toBeNull();
        expect(el('[data-delibera="4"] [data-testid="azioni"] [data-testid="applica"]').className).toContain('h-7');
    });

    test('la riga respinta dice «Nessuna conseguenza nei conti», le altre «Portalo alla prossima assemblea, se serve»', async () => {
        const s = confermato();
        s.tabella = [...s.tabella, rigaDelPasso({ delibera_id: 5, punto: 5, esito: 'respinta', esito_parola: 'Respinta', seguito: { stato: 'senza_seguito', parola: '', eseguita_il: null, fatte: 0, quante: 0 }, posti: [] }),
            rigaDelPasso({ delibera_id: 6, punto: 6, esito: 'rinviata', esito_parola: 'Rinviata', seguito: { stato: 'senza_seguito', parola: '', eseguita_il: null, fatte: 0, quante: 0 }, posti: [] }),
            rigaDelPasso({ delibera_id: 7, punto: 7, esito: 'senza_voto', esito_parola: 'Senza voto', memo_salvato: true, seguito: { stato: 'senza_seguito', parola: '', eseguita_il: null, fatte: 0, quante: 0 }, posti: [] })];
        await monta({ seguito: s });
        expect(el('[data-delibera="5"] [data-testid="senza-seguito"]').textContent).toBe('Nessuna conseguenza nei conti');
        expect(el('[data-delibera="6"] [data-testid="senza-seguito"]').textContent).toBe('Portalo alla prossima assemblea, se serve');
        // Dopo il memo salvato (B8): è nella memoria del condominio.
        expect(el('[data-delibera="7"] [data-testid="senza-seguito"]').textContent).toBe('Nella memoria del condominio');
    });

    test('al telefono ogni delibera è una scheda: il numero e il testo, sotto esito e seguito, sotto le azioni; la tabella dal tablet (sm) in su', async () => {
        await monta({ seguito: confermato() });
        expect(el('[data-testid="tabella-grande"]').className).toMatch(/\bhidden\b.*\bsm:block\b/);
        expect(el('[data-testid="delibere-al-telefono"]').className).toContain('sm:hidden');
        const scheda = el('[data-testid="riga-al-telefono"][data-delibera="4"]');
        expect(scheda.querySelector('[data-testid="azioni-al-telefono"]')).not.toBeNull();
    });
});

describe('gli altri casi del passo', () => {
    test('il verbale annullato: la frase, e le schede che usano le delibere', async () => {
        const s = confermato();
        s.passo = { stato: 'chiuso', riga: 'il verbale è annullato', perche: 'Il verbale è annullato: le delibere applicate restano.',
            piede: 'Le cose da fare aperte restano nella tabella: chiudile con «Segna eseguita» o «Non serve più».' };
        s.tabella = s.tabella.map((r) => ({ ...r, verbale_annullato: true }));
        await monta({ seguito: s });
        expect(el('[data-testid="verbale-annullato"]').textContent).toContain('Il verbale è annullato: le delibere applicate restano.');
        expect(el('[data-testid="verbale-annullato"]').textContent).toContain('Rendiconto, versione 2 (punto 1)');
    });

    test('la riunione su carta: «Da eseguire» e «Trenta giorni» in testa', async () => {
        await monta({ seguito: confermato(), suCarta: true });
        const voci = el('[data-testid="voci-in-testa"]').textContent?.replace(/\s+/g, ' ') ?? '';
        expect(voci).toContain('Da fare adesso Crea il piano straordinario · punto 4 · a Andrea Costa');
        expect(voci).toContain('Trenta giorni Passati per tutti dal 22/01/2027. Resta la nullità.');
    });

    test('gli esiti della conferma: quante righe applicate, e il perché di quella che non si è applicata', async () => {
        await monta({ seguito: confermato(), esiti: { a: { esito: 'applicata', frase: 'ok' }, b: { esito: 'non_applicata', frase: 'Il piano è già approvato dal 10/12.' } } });
        expect(el('[data-testid="esiti-della-conferma"]').textContent).toContain('1 riga applicata, 1 non applicata');
        expect(el('[data-testid="non-applicata"]').textContent).toBe('Il piano è già approvato dal 10/12.');
    });

    test('finché il seguito non arriva, «Un momento…»; prima del verbale, il perché', async () => {
        await monta({});
        expect(testo()).toContain('Un momento…');
        w?.unmount();
        const s = daConfermare();
        s.riepilogo = { ...s.riepilogo, aperto: false, perche_chiuso: 'Si apre con il verbale registrato.', punti: [], da_confermare: 0 };
        await monta({ seguito: s });
        expect(el('[data-testid="passo-chiuso"]').textContent).toContain('Si apre con il verbale registrato.');
    });
});
