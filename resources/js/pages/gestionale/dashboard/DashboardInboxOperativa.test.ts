// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'Inbox operativa della dashboard del condominio, con le regole dell'Action inbox: al posto di «Risolvi» la prossima
 * azione del tipo (la mappa unica, dal server), «Segna come fatto» che apre la risposta «Fatto» per i compiti senza una pagina; le segnalazioni e i bonifici
 * segnalati non sono «scaduti» e la riga dice «aperta il … · da N giorni» in grigio; il badge della priorità in minuscolo; il menu delle segnalazioni ha solo
 * «Ricordamelo». Nessuna scritta sotto i 12 pixel nelle righe. La descrizione una volta sola: quando la riga sotto il titolo è la prima riga della descrizione,
 * resta la descrizione. Le schede «Tutte», «Scadute», «In arrivo» stanno nella testata, a destra del titolo (al telefono sotto, su una riga loro), e rileggono
 * dal server solo l'elenco con il filtro (un gruppo di pulsanti, non un menu): la pagina resta dov'è, senza la barra di avanzamento in cima, con un segno di
 * caricamento dentro la card. Le segnalazioni e i bonifici, che non scadono, stanno in «Tutte», e una riga lo dice.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const { vaiA } = vi.hoisted(() => ({ vaiA: vi.fn() }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    InfiniteScroll: { template: '<div><slot /></div>' },
    router: { post: vi.fn(), get: vaiA, patch: vi.fn(), reload: vi.fn(), on: () => () => undefined },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: [], permissions: ['Accesso pannello amministratore', 'Visualizza scadenze in agenda', 'Modifica scadenza in agenda'] } } } }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Dashboard = (await import('@/pages/gestionale/dashboard/Dashboard.vue')).default;

const Risposte = { name: 'RisposteDelCompito', template: '<div />', methods: { apri: vi.fn() } };
const passa = { template: '<div><slot /></div>' };
let pagina: VueWrapper | null = null;
afterEach(() => { pagina?.unmount(); pagina = null; document.body.innerHTML = ''; Risposte.methods.apri.mockClear(); vaiA.mockClear(); });

const riga = (id: number, altro: Record<string, unknown>) => ({ id, title: `Compito ${id}`, titolo: `Compito ${id}`, sotto: '', description: '', action_url: null, status: 'scheduled',
    days_pending: 0, context: { anagrafica_nome: null }, risposta: null, promemoria_a_parte: false, priorita: null, ...altro });
const compiti = {
    total: 4,
    data: [
        riga(1, { type: 'pagamento_fornitore', titolo: 'Pagare Idraulica Rossi', sotto: 'Fattura n. F-12 · € 450,00', giorno: '2026-11-13', giorni: -2, gruppo: 'scaduti',
            status: 'expired', azione: { testo: 'Paga la fattura', url: '/admin/gestionale/7/pagamenti-fornitori/create?fattura_id=12' } }),
        riga(2, { type: 'segnalazione_guasto', titolo: 'Ascensore fermo', giorno: '2026-02-22', giorni: -267, gruppo: 'segnalazioni', priorita: 'urgente',
            azione: { testo: 'Apri la segnalazione', url: '/admin/segnalazioni/5' } }),
        riga(3, { type: 'verifica_pagamento', titolo: 'Verifica incasso della rata 3', sotto: 'Mario Rossi · € 123,45', giorno: '2026-11-10', giorni: -5, gruppo: 'bonifici',
            azione: { testo: 'Registra l\'incasso', url: '/admin/gestionale/7/movimenti-rate/create' } }),
        riga(4, { type: 'scadenza', titolo: 'Rinnovo assicurazione', giorno: '2026-11-15', giorni: 0, gruppo: 'avanti', azione: { testo: 'Segna come fatto', url: null } }),
    ],
};

async function monta(altro: Record<string, unknown> = {}) {
    pagina = mount(Dashboard, {
        attachTo: document.body,
        props: {
            condominio: { id: 7, nome: 'Via Verdi 12' } as never, condomini: [], esercizio: { id: 1, nome: '2026' } as never, esercizi: [],
            copertura: null, pianiDisallineati: [], fattureScoperte: [], inboxTasks: compiti, widgets: {}, oggi: '2026-11-15',
            inboxScaduti: 1, inboxSchede: { tutti: 4, scaduti: 1, in_arrivo: 1 }, ...altro,
        },
        global: {
            stubs: {
                GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, TreasuryGuardianWidget: true, ControlliPostImportWidget: true, CreditiDaCompensareWidget: true,
                SeiMesiPerAgireWidget: true, SeiMesiRisposte: true, RisposteDelCompito: Risposte, DashboardCondominioGuide: true,
                DropdownMenu: passa, DropdownMenuTrigger: passa, DropdownMenuContent: passa, DropdownMenuItem: passa, DropdownMenuSeparator: passa,
                TooltipProvider: { template: '<div><slot /></div>' }, Tooltip: { template: '<div><slot /></div>' }, TooltipTrigger: { template: '<div><slot /></div>' }, TooltipContent: { template: '<div><slot /></div>' },
            },
        },
    });
    await flushPromises();
}
const righe = () => [...document.body.querySelectorAll('[data-testid="compito-inbox"]')] as HTMLElement[];
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();

describe('l\'Inbox operativa del condominio', () => {
    test('la prossima azione del tipo al posto di «Risolvi»: un collegamento alla sua pagina, o «Segna come fatto»', async () => {
        await monta();
        const azioni = righe().map((r) => r.querySelector('[data-testid="prossima-azione"]') as HTMLElement);
        expect(azioni.map((a) => pulito(a.textContent))).toEqual(['Paga la fattura', 'Apri la segnalazione', 'Registra l\'incasso', 'Segna come fatto']);
        expect(azioni.map((a) => a.tagName)).toEqual(['A', 'A', 'A', 'BUTTON']);
        expect(azioni[0].getAttribute('href')).toBe('/admin/gestionale/7/pagamenti-fornitori/create?fattura_id=12');
        expect(pulito(document.body.textContent)).not.toContain('Risolvi');
    });

    test('«Segna come fatto» apre la risposta «Fatto» del compito', async () => {
        await monta();
        (righe()[3].querySelector('[data-testid="prossima-azione"]') as HTMLButtonElement).click();
        await flushPromises();
        expect(Risposte.methods.apri).toHaveBeenCalledWith('fatto', expect.objectContaining({ id: 4 }));
    });

    test('lo scaduto in rosso con i giorni; la segnalazione «aperta il … · da N giorni» in grigio; il bonifico non è scaduto; oggi', async () => {
        await monta();
        const date = righe().map((r) => r.querySelector('[data-testid="data-compito"]') as HTMLElement);
        expect(date.map((d) => pulito(d.textContent))).toEqual([
            'scaduto il 13/11/2026 · da 2 giorni', 'aperta il 22/02/2026 · da 267 giorni', 'segnalato il 10/11/2026 · da 5 giorni', 'scade oggi']);
        expect(date[0].className).toContain('text-red-600');
        expect(date[1].className).toContain('text-slate-500');
        expect(date[1].className).not.toContain('text-red');
        expect(date[2].className).not.toContain('text-red');
        // Solo lo scaduto ha il fondo e l'icona rossi.
        expect(righe().map((r) => r.className.includes('bg-red'))).toEqual([true, false, false, false]);
    });

    test('il titolo senza il condominio ripetuto, la riga sotto, il badge della priorità in minuscolo', async () => {
        await monta();
        expect(pulito(righe()[0].textContent)).toContain('Pagare Idraulica Rossi');
        expect(pulito(righe()[0].textContent)).toContain('Fattura n. F-12 · € 450,00');
        const badge = righe()[1].querySelector('[data-testid="priorita"]') as HTMLElement;
        expect(pulito(badge.textContent)).toBe('urgente');
        expect(badge.className).toContain('rounded-md');
        expect(badge.className).not.toContain('uppercase');
    });

    test('il menu della segnalazione ha solo «Ricordamelo»', async () => {
        await monta();
        const voci = [...righe()[1].querySelectorAll('[data-testid^="voce-"]')].map((v) => pulito(v.textContent));
        expect(voci).toEqual(['Ricordamelo il…']);
    });

    test('nelle righe nessuna scritta sotto i 12 pixel e nessun maiuscolo forzato', async () => {
        await monta();
        for (const r of righe()) {
            expect(r.innerHTML).not.toMatch(/text-\[(8|9|10|11)px\]/);
            expect(r.innerHTML).not.toContain('uppercase');
        }
    });
});

describe('la descrizione una volta sola', () => {
    const lunga = 'Il piano rate risulta incassato solo in parte. Residuo complessivo € 1.250,00, fra rate scadute e a scadere. I tre importi maggiori: Rossi € 500,00 · Bianchi € 400,00 · Verdi € 350,00';
    const conLaDescrizione = {
        total: 2,
        data: [
            riga(11, { type: 'controllo_incassi', titolo: 'Sollecitare le rate non ancora versate', sotto: `${lunga.slice(0, 139)}…`, sotto_dalla_descrizione: true,
                description: lunga, giorno: '2026-11-25', giorni: 10, gruppo: 'avanti', azione: { testo: 'Registra gli incassi', url: '/admin/gestionale/7/movimenti-rate' } }),
            riga(12, { type: 'pagamento_fornitore', titolo: 'Pagare Idraulica Rossi', sotto: 'Fattura n. F-12 · € 450,00', sotto_dalla_descrizione: false,
                description: 'Scadenza fattura n. F-12.', giorno: '2026-11-13', giorni: -2, gruppo: 'scaduti', status: 'expired', azione: { testo: 'Paga la fattura', url: '/x' } }),
        ],
    };
    const quante = (testo: string, parte: string) => testo.split(parte).length - 1;

    test('quando la riga sotto il titolo è la prima riga della descrizione, la descrizione compare una volta sola', async () => {
        await monta({ inboxTasks: conLaDescrizione });
        const testo = pulito(righe()[0].textContent);
        expect(quante(testo, 'Il piano rate risulta incassato solo in parte')).toBe(1);
        expect(testo).toContain('I tre importi maggiori');
    });

    test('quando la riga sotto dice la fattura e la cifra, restano tutte e due', async () => {
        await monta({ inboxTasks: conLaDescrizione });
        const testo = pulito(righe()[1].textContent);
        expect(testo).toContain('Fattura n. F-12 · € 450,00');
        expect(testo).toContain('Scadenza fattura n. F-12.');
    });
});

describe('le schede «Tutte», «Scadute», «In arrivo»', () => {
    const schede = () => [...document.body.querySelectorAll('[data-testid^="scheda-inbox-"]')] as HTMLButtonElement[];

    test('nella testata, accanto al titolo, un gruppo di pulsanti con il numero di ognuna; «Tutte» scelta all\'apertura; al telefono sotto il titolo', async () => {
        await monta();
        const gruppo = document.body.querySelector('[data-testid="schede-inbox"]') as HTMLElement;
        const testata = document.body.querySelector('[data-testid="testata-inbox"]') as HTMLElement;
        expect(gruppo.getAttribute('role')).toBe('group');
        // Nella testata, nella stessa fila del titolo: a destra da sm in su, sotto al telefono, larghe quanto la card.
        expect(testata.contains(gruppo)).toBe(true);
        const fila = gruppo.parentElement as HTMLElement;
        expect(pulito(fila.textContent)).toContain('Inbox operativa');
        expect(fila.className).toContain('flex-col');
        expect(fila.className).toContain('sm:flex-row');
        expect(gruppo.className).toContain('w-full');
        expect(gruppo.className).toContain('sm:w-auto');
        // Niente riga a sé per le schede sotto la testata.
        expect(testata.nextElementSibling?.querySelector('[data-testid="schede-inbox"]') ?? null).toBeNull();
        expect(schede().map((b) => pulito(b.textContent))).toEqual(['Tutte 4', 'Scadute 1', 'In arrivo 1']);
        expect(schede().map((b) => b.getAttribute('aria-pressed'))).toEqual(['true', 'false', 'false']);
        expect(document.body.querySelector('select')).toBeNull();
    });

    test('i compiti dei sei mesi contano nelle schede: con la data passata in «Scadute», gli altri in «In arrivo»', async () => {
        const seiMesi = (chiave: string, giorni: number) => ({ chiave, argomento: 'sei_mesi', esercizio: { id: 25, nome: 'Esercizio 2025' }, data: '2026-11-01', giorni,
            colore: giorni < 0 ? 'passata' : 'vicina', tornato: null, ancora: chiave, tutte: [] });
        await monta({ compitiSeiMesi: [seiMesi('a', -14), seiMesi('b', 20)] });
        expect(schede().map((b) => pulito(b.textContent))).toEqual(['Tutte 6', 'Scadute 2', 'In arrivo 2']);
    });

    test('un clic su «Scadute» rilegge solo l\'Inbox dal server, con il filtro nell\'indirizzo e la lista da capo: la pagina resta dov\'è', async () => {
        await monta();
        schede()[1].click();
        await flushPromises();
        expect(vaiA).toHaveBeenCalledTimes(1);
        const [indirizzo, dati, opzioni] = vaiA.mock.calls[0];
        expect(String(indirizzo)).toContain('inbox=scaduti');
        expect(String(indirizzo)).not.toContain('page=');
        expect(dati).toEqual({});
        // Solo le prop del riquadro; la pagina non torna in alto e non si ricarica; niente barra di avanzamento in cima alla pagina.
        expect(opzioni.only).toEqual(['inboxTasks', 'inboxSchede', 'inboxScaduti', 'inboxScheda']);
        expect(opzioni).toMatchObject({ reset: ['inboxTasks'], preserveState: true, preserveScroll: true, async: true, showProgress: false });
    });

    test('mentre la scheda arriva, un segno di caricamento dentro la card e la lista più chiara; un altro clic aspetta; poi tutto torna com\'era', async () => {
        await monta();
        expect(document.body.querySelector('[data-testid="inbox-in-caricamento"]')).toBeNull();
        schede()[1].click();
        await flushPromises();
        const opzioni = vaiA.mock.calls[0][2];
        opzioni.onStart();
        await flushPromises();
        const segno = document.body.querySelector('[data-testid="inbox-in-caricamento"]') as HTMLElement;
        expect(segno).not.toBeNull();
        expect((document.body.querySelector('[data-testid="testata-inbox"]') as HTMLElement).contains(segno)).toBe(true);
        expect(document.body.querySelector('[data-testid="schede-inbox"]')?.getAttribute('aria-busy')).toBe('true');
        expect((document.body.querySelector('[data-testid="compito-inbox"]')?.closest('.overflow-y-auto') as HTMLElement).className).toContain('opacity-60');
        schede()[2].click();
        await flushPromises();
        expect(vaiA).toHaveBeenCalledTimes(1);
        opzioni.onFinish();
        await flushPromises();
        expect(document.body.querySelector('[data-testid="inbox-in-caricamento"]')).toBeNull();
        expect((document.body.querySelector('[data-testid="compito-inbox"]')?.closest('.overflow-y-auto') as HTMLElement).className).not.toContain('opacity-60');
    });

    test('«Tutte» toglie il filtro dall\'indirizzo; la scheda già scelta non rilegge', async () => {
        await monta({ inboxScheda: 'scaduti' });
        schede()[1].click();
        await flushPromises();
        expect(vaiA).not.toHaveBeenCalled();
        schede()[0].click();
        await flushPromises();
        expect(String(vaiA.mock.calls[0][0])).not.toContain('inbox=');
    });

    test('con «Scadute» i compiti dei sei mesi in arrivo non ci sono, e una riga dice dove stanno le segnalazioni e i bonifici', async () => {
        const seiMesi = (chiave: string, giorni: number) => ({ chiave, argomento: 'sei_mesi', esercizio: { id: 25, nome: 'Esercizio 2025' }, data: '2026-11-01', giorni,
            colore: giorni < 0 ? 'passata' : 'vicina', tornato: null, ancora: chiave, tutte: [] });
        await monta({ inboxScheda: 'scaduti', compitiSeiMesi: [seiMesi('a', -14), seiMesi('b', 20)], inboxTasks: { total: 1, data: [compiti.data[0]] } });
        expect(document.body.querySelectorAll('[data-testid="compiti-sei-mesi"] > *').length).toBe(1);
        expect(pulito(document.body.querySelector('[data-testid="fuori-dalle-schede"]')?.textContent))
            .toBe('Le segnalazioni e i bonifici segnalati non hanno una scadenza: 2 sono in «Tutte».');
    });

    test('con «Tutte» la riga non c\'è; una scheda vuota lo dice', async () => {
        await monta();
        expect(document.body.querySelector('[data-testid="fuori-dalle-schede"]')).toBeNull();
        pagina?.unmount();
        await monta({ inboxScheda: 'in_arrivo', inboxTasks: { total: 0, data: [] }, inboxSchede: { tutti: 2, scaduti: 2, in_arrivo: 0 } });
        expect(pulito(document.body.querySelector('[data-testid="scheda-vuota"]')?.textContent)).toBe('Nessuna attività in arrivo.');
    });

    test('le schede non hanno scritte sotto i 12 pixel; lo zero è grigio, le scadute rosse', async () => {
        await monta({ inboxSchede: { tutti: 4, scaduti: 1, in_arrivo: 0 } });
        const gruppo = document.body.querySelector('[data-testid="schede-inbox"]') as HTMLElement;
        expect(gruppo.innerHTML).not.toMatch(/text-\[(8|9|10|11)px\]/);
        expect(schede()[1].querySelector('span')?.className).toContain('text-red-600');
        expect(schede()[2].querySelector('span')?.className).toContain('text-slate-400');
    });
});
