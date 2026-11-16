// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il riquadro «Sei mesi per agire» della dashboard del condominio, montato con il riassunto del server scritto a mano (i due esempi delle bozze approvate: Via
 * Verdi 12 e il condominio appena importato). Le frasi a video, la regola delle 8 in una riga, il pulsante del passo di ogni persona, i pulsanti in blocco, il colore dei
 * giorni, il vuoto. Nessuna scritta sotto i 12 px nel componente.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: vi.fn(), visit: vi.fn() },
    usePage: () => ({ props: {} }),
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome, generatePath: (p: string) => `/${p}`, hasPermission: () => true }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, p?: Record<string, unknown>) => `/${nome}${p ? '?' + new URLSearchParams(p as Record<string, string>).toString() : ''}`;

const Widget = (await import('./SeiMesiPerAgireWidget.vue')).default;
const { testoGiorni, testiCartellini, testoInBlocco, testoOltre, testoSenzaData, testoChiuse } = await import('@/lib/gestionale/debiti/seiMesiPerAgire');

const passo = (p: string | null, frase: string, possibili = ['sollecito', 'diffida', 'fascicolo', 'agito']) => ({ caso: 'R11', passo: p, frase, note: [], possibili, rata_compensa: null, termine: null });
const persona = (id: number, nome: string, cifra: number, p: ReturnType<typeof passo>, situazione = 'senza_lettera', altro: Record<string, unknown> = {}) =>
    ({ anagrafica_id: id, nome, unita: [`Int. ${id}`], cifra, credito: 0, situazione, passo: p, torna: null, lettere_da_spedire: [], ...altro });

const viaVerdi = {
    oggi: '2026-05-20', passo_proposto: true, troppe: null,
    righe: [
        {
            chiave: 'sei-mesi-25', esercizio: { id: 25, nome: 'Esercizio 2025' }, data: '2026-06-30', giorni: 41, colore: 'vicina', aperta: true, piu_avanti: false, quante: 4, cifra: 324000,
            cartellini: { senza_lettera: 1, sollecito: 0, diffida_spedita: 1, diffida_da_spedire: 1, accordo: 1, dispensata: 0 },
            persone: [
                persona(1, 'Rossi', 124000, passo('diffida', 'Nessuna lettera; la data dei sei mesi è il 30/06/2026.')),
                persona(2, 'Bianchi', 90000, passo('fascicolo', 'Diffida spedita il 03/10/2025: il termine del 18/10/2025 è passato.'), 'diffida'),
                persona(3, 'Verdi', 64000, passo(null, 'Accordo di rientro del 10/01/2026, fino al 30/09/2026.', ['sollecito', 'diffida', 'fascicolo', 'accordo', 'agito']), 'accordo'),
                persona(4, 'Neri', 46000, passo('spedizione', 'Diffida stampata il 10/05/2026: la spedizione non è registrata.', ['sollecito', 'diffida', 'spedizione', 'fascicolo', 'agito']), 'diffida', { lettere_da_spedire: [91] }),
            ],
            tutte: [], chiuse: [{ anagrafica_id: 5, nome: 'Gialli', cifra: 30000, motivo: 'agito', frase: 'Hai agito il 12/03/2026: «pratica data all\'avv. Gallo» (Vincenzo).' }],
            in_blocco: [], oltre: null, risposta: { stato: 'ricordamelo', frase: 'Ti ricordo il 15/06/2026.', registro: [] }, compito: false, compito_tornato: null,
            url_elenco: '/rate-scadute?sei_mesi_del=2026-06-30',
        },
        {
            chiave: 'sei-mesi-26', esercizio: { id: 26, nome: 'Esercizio 2026' }, data: '2027-06-30', giorni: 406, colore: 'lontana', aperta: false, piu_avanti: true, quante: 2, cifra: 50000,
            cartellini: { senza_lettera: 2, sollecito: 0, diffida_spedita: 0, diffida_da_spedire: 0, accordo: 0, dispensata: 0 },
            persone: [persona(1, 'Rossi', 30000, passo('sollecito', 'Nessuna lettera.')), persona(6, 'Viola', 20000, passo('sollecito', 'Nessuna lettera.'))],
            tutte: [], chiuse: [], in_blocco: [{ passo: 'sollecito', persone: [1, 6], lettere: [] }], oltre: null, risposta: null, compito: false, compito_tornato: null,
            url_elenco: '/rate-scadute?sei_mesi_del=2027-06-30',
        },
    ],
    senza_data: null,
    rendiconti: [
        { esercizio: { id: 25, nome: 'Esercizio 2025' }, data: '2026-06-29', giorni: 40, colore: 'fermato', stato: 'presentata', frase: 'Fermato per l\'assemblea il 12/05/2026.', passo: 'approvazione',
            url: '/rendiconto/25', agenda: null, risposta: null, compito: false, ultimo: true },
    ],
    compiti: [],
};

const importato = {
    oggi: '2026-02-05', passo_proposto: true, troppe: null,
    righe: [2022, 2023, 2024].map((anno, i) => ({
        chiave: `sei-mesi-${anno}`, esercizio: { id: anno, nome: `Esercizio ${anno}` }, data: `${anno + 1}-06-30`, giorni: -(950 - i * 365), colore: 'passata', aperta: i === 0, piu_avanti: false,
        quante: 9, cifra: 900000, cartellini: { senza_lettera: 7, sollecito: 2, diffida_spedita: 0, diffida_da_spedire: 0, accordo: 0, dispensata: 0 },
        persone: [1, 2, 3, 4, 5].map((n) => persona(n, `Persona ${n}`, 100000 + n, passo('diffida', 'Nessuna lettera; la data dei sei mesi è il 30/06/2023.'))),
        tutte: [], chiuse: [], in_blocco: [{ passo: 'diffida', persone: [1, 2, 3, 4, 5, 6, 7], lettere: [] }], oltre: { mancano: 4, cifra: 82000 },
        risposta: null, compito: true, compito_tornato: null, url_elenco: '/x',
    })),
    senza_data: { rate: 4, persone: 3, cifra: 28000, perche: ['Pregresso di prima del primo esercizio: le date non si calcolano.'], per_persona: {},
        righe: [
            { anagrafica_id: 41, nome: 'Tobia Cecchino', unita: ['Int. 1C'], cifra: 5000, motivi: [{ caso: 'prima_del_primo',
                perche: 'Saldo riportato con l\'origine «prima del primo esercizio».', spiegazione: 'Il programma non ha quell\'esercizio, quindi non sa quando chiude.',
                azione: { tipo: 'origine', etichetta: 'Dichiara l\'origine' } }] },
            { anagrafica_id: 42, nome: 'Ada Neri', unita: ['Int. 2A'], cifra: 13000, motivi: [{ caso: 'manca_esercizio', perche: 'L\'esercizio che contiene il 15/01/2027 non c\'è.',
                spiegazione: 'I sei mesi si contano dalla chiusura di quell\'esercizio: quando c\'è, la data si calcola.', azione: { tipo: 'esercizio', etichetta: 'Crea l\'esercizio' } }] },
            { anagrafica_id: 43, nome: 'Ugo Bassi', unita: ['Int. 3B'], cifra: 10000, motivi: [{ caso: 'senza_delibera', perche: 'Il piano straordinario non ha la data della delibera.',
                spiegazione: 'Per la straordinaria i sei mesi si contano dall\'esercizio della delibera.',
                azione: { tipo: 'piano', etichetta: 'Scrivi la data della delibera', piano: { id: 7, esercizio_id: 26 } } }] },
        ] },
    rendiconti: [], compiti: [],
};

const slot = { template: '<div><slot /></div>' };
const MENU = { DropdownMenu: slot, DropdownMenuTrigger: slot, DropdownMenuContent: slot, DropdownMenuItem: slot, DropdownMenuLabel: slot, DropdownMenuSeparator: slot };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

async function monta(seiMesi: unknown, puo = true) {
    w = mount(Widget, {
        attachTo: document.body,
        props: { seiMesi: seiMesi as never, condominioId: 3, puoRispondere: puo },
        global: { stubs: { ...MENU, LettereDialog: true, AccordoRientroDialog: true, FascicoloLegaleDialog: true, TooltipProvider: { template: '<div><slot /></div>' }, Tooltip: { template: '<div><slot /></div>' },
            TooltipTrigger: { template: '<div><slot /></div>' }, TooltipContent: { template: '<div><slot /></div>' } } },
    });
    await flushPromises();

    return w;
}
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');

describe('le frasi', () => {
    test('i giorni', () => {
        expect([41, 1, 0, -1, -12].map(testoGiorni)).toEqual(['fra 41 giorni', 'domani', 'oggi', 'passata ieri', 'passata da 12 giorni']);
    });

    test('i cartellini, i pulsanti in blocco, la riga di fondo, le rate senza data, le persone chiuse', () => {
        expect(testiCartellini({ senza_lettera: 2, sollecito: 1, diffida_spedita: 1, diffida_da_spedire: 0, accordo: 1, dispensata: 1 }))
            .toEqual(['2 senza lettera', '1 con il sollecito', '1 con la diffida spedita', '1 con un accordo', '1 dispensata']);
        expect(testoInBlocco('diffida', 4)).toBe('Manda la diffida a 4 persone');
        expect(testoInBlocco('sollecito', 3)).toBe('Manda un sollecito a 3 persone');
        expect(testoInBlocco('spedizione', 3)).toBe('Registra la spedizione di 3 lettere');
        expect(testoOltre({ mancano: 4, cifra: 82000 })).toBe('Qui le 5 con la cifra più alta; mancano 4 persone, € 820,00');
        expect(testoSenzaData({ rate: 2, persone: 1, cifra: 18000 })).toBe('2 rate di 1 persona, € 180,00, non hanno la data dei sei mesi.');
        expect(testoChiuse([{ motivo: 'agito' }, { motivo: 'agito' }, { motivo: 'dispensa' }])).toBe('Hai registrato che hai agito per 2 persone; 1 è dispensata');
    });
});

describe('la prima bozza: Via Verdi 12', () => {
    test('la testata, la riga aperta con la data, i giorni, le persone, i cartellini e la risposta', async () => {
        await monta(viaVerdi);
        const t = testo();
        expect(t).toContain('Sei mesi per agire');
        expect(t).toContain('Esercizio 2025');
        expect(t).toContain('30/06/2026');
        expect(t).toContain('fra 41 giorni');
        expect(t).toContain('4 persone · € 3.240,00 di questo esercizio');
        expect(t).toContain('1 senza lettera');
        expect(t).toContain('1 con la diffida spedita');
        expect(t).toContain('1 con un accordo');
        expect(t).toContain('Ti ricordo il 15/06/2026.');
        expect(t).toContain('Nessuna lettera; la data dei sei mesi è il 30/06/2026.');
        expect(t).toContain('Le lettere chiedono tutto lo scaduto della persona, non solo la parte di questo esercizio.');
        expect(t).toContain('Tutte le rate di questo esercizio →');
        expect(t).toContain('Registra la dispensa dell\'assemblea');
        expect(t).toContain('Le date si calcolano a ogni apertura. Il programma suggerisce la prossima azione per ogni persona: la scelta resta tua.');
    });

    test('il pulsante del passo di ogni persona, e «Registra che hai agito» accanto; senza passo il menu dei passi possibili', async () => {
        await monta(viaVerdi);
        const riga = document.body.querySelector('[data-testid="sei-mesi-25"]')!;
        const passi = Array.from(riga.querySelectorAll('[data-testid="passo-proposto"]')).map((b) => b.textContent?.trim());
        expect(passi).toEqual(['Manda la diffida', 'Prepara il fascicolo per il legale', 'Registra la spedizione']);
        expect(riga.querySelectorAll('[data-testid="registra-agito"]').length).toBe(4);
        // Il fascicolo ha la sua finestra: Bianchi, con la diffida e il termine passato, ha il pulsante; Verdi (l'accordo) il menu.
        expect(riga.textContent).toContain('Diffida spedita il 03/10/2025: il termine del 18/10/2025 è passato.');
        expect(riga.querySelectorAll('[data-testid="scegli-passo"]').length).toBe(1);
    });

    test('«Prepara il fascicolo per il legale» apre la finestra del fascicolo, con la persona', async () => {
        await monta(viaVerdi);
        const bianchi = document.body.querySelector('[data-testid="sei-mesi-25"] [data-testid="persona-2"] [data-testid="passo-proposto"]') as HTMLButtonElement;
        expect(bianchi.textContent?.trim()).toBe('Prepara il fascicolo per il legale');
        bianchi.click();
        await flushPromises();
        const finestra = w!.findComponent({ name: 'FascicoloLegaleDialog' });
        expect(finestra.exists()).toBe(true);
        expect(finestra.props('open')).toBe(true);
        expect(finestra.props('persona')).toEqual({ id: 2, nome: 'Bianchi' });
    });

    test('la riga «più avanti» sta chiusa sotto il suo titolo; i giorni hanno il colore della data', async () => {
        await monta(viaVerdi);
        expect(testo()).toContain('Più avanti');
        expect(testo()).toContain('fra 406 giorni');
        expect(document.body.querySelector('[data-testid="sei-mesi-26"] [data-testid="persone"]')).toBeNull();
        expect(document.body.querySelector('[data-testid="sei-mesi-25"] [data-testid="giorni"]')!.className).toContain('text-amber-700');
        expect(document.body.querySelector('[data-testid="sei-mesi-26"] [data-testid="giorni"]')!.className).toContain('text-slate-500');
    });

    test('le persone chiuse in fondo, ripiegate; il rendiconto in assemblea con la sua frase', async () => {
        await monta(viaVerdi);
        expect(testo()).toContain('Hai registrato che hai agito per 1 persona');
        expect(testo()).toContain('Rendiconto in assemblea');
        expect(testo()).toContain('Rendiconto 2025');
        expect(testo()).toContain('da convocare in assemblea entro il 29/06/2026');
        expect(testo()).toContain('Fermato per l\'assemblea il 12/05/2026.');
        expect(testo()).toContain('Registra l\'approvazione');
        expect(testo()).toContain('Mettilo in agenda');
    });

    test('senza il permesso dei compiti i pulsanti delle risposte non ci sono; i passi sì', async () => {
        await monta(viaVerdi, false);
        expect(document.body.querySelectorAll('[data-testid="registra-agito"]').length).toBe(0);
        expect(document.body.querySelectorAll('[data-testid="menu-risposte"]').length).toBe(0);
        expect(document.body.querySelectorAll('[data-testid="passo-proposto"]').length).toBeGreaterThan(0);
    });
});

describe('la seconda bozza: il condominio appena importato', () => {
    test('tre righe rosse «passata da …», la regola delle 8 in una riga, i pulsanti in blocco, le rate senza data', async () => {
        await monta(importato);
        expect(testo()).toContain('passata da 950 giorni');
        expect(document.body.querySelector('[data-testid="sei-mesi-2022"] [data-testid="giorni"]')!.className).toContain('text-red-600');
        expect(testo()).toContain('Qui le 5 con la cifra più alta; mancano 4 persone, € 820,00');
        expect(testo()).toContain('Manda la diffida a 7 persone');
        expect(testo()).toContain('4 rate di 3 persone, € 280,00, non hanno la data dei sei mesi.');
    });
});

describe('le rate senza la data dei sei mesi', () => {
    test('chiusa: quante, e la frase che dice che sotto ci sono le persone; niente «Vedi le rate →»', async () => {
        await monta(importato);
        const riga = document.body.querySelector('[data-testid="senza-data"]')!;
        expect(riga.textContent).toContain('4 rate di 3 persone, € 280,00, non hanno la data dei sei mesi.');
        expect(riga.textContent).toContain('Il programma non sa da quale esercizio contare i sei mesi: qui sotto chi è e che cosa fare.');
        expect(riga.textContent).not.toContain('Vedi le rate');
        expect(riga.querySelector('[data-testid="persone-senza-data"]')).toBeNull();
    });

    test('aperta con la freccia, come la riga dell\'esercizio: le colonne, il nome che apre l\'estratto conto, il perché e la prossima azione del caso', async () => {
        await monta(importato);
        const freccia = document.body.querySelector('[data-testid="apri-senza-data"]') as HTMLButtonElement;
        expect(freccia.getAttribute('aria-label')).toBe('Apri la riga');
        freccia.click();
        await flushPromises();
        const tabella = document.body.querySelector('[data-testid="persone-senza-data"]')!;
        const intestazione = tabella.querySelector('[data-testid="intestazione-senza-data"]')!;
        expect(Array.from(intestazione.querySelectorAll('[data-testid="colonna"]')).map((c) => (c.textContent ?? '').trim())).toEqual(['Persona', 'Non versato', 'Perché manca la data', 'Prossima azione']);

        const tobia = tabella.querySelector('[data-testid="senza-data-41"]')!;
        expect(tobia.querySelector('a')!.getAttribute('href')).toContain('gestionale.anagrafiche.estratto-conto');
        expect(tobia.textContent).toContain('Int. 1C');
        expect(tobia.textContent).toContain('€ 50,00');
        expect(tobia.textContent).toContain('Saldo riportato con l\'origine «prima del primo esercizio».');
        expect(tobia.textContent).toContain('Il programma non ha quell\'esercizio, quindi non sa quando chiude.');
        const origine = tobia.querySelector('[data-testid="azione-senza-data"]')!;
        expect(origine.textContent).toContain('Dichiara l\'origine');
        expect(origine.getAttribute('href')).toContain('gestionale.saldi.index');
        expect(origine.getAttribute('href')).toContain('origine_di=41');

        const esercizio = tabella.querySelector('[data-testid="senza-data-42"] [data-testid="azione-senza-data"]')!;
        expect(esercizio.textContent).toContain('Crea l\'esercizio');
        expect(esercizio.getAttribute('href')).toContain('gestionale.esercizi.create');
        const piano = tabella.querySelector('[data-testid="senza-data-43"] [data-testid="azione-senza-data"]')!;
        expect(piano.textContent).toContain('Scrivi la data della delibera');
        expect(piano.getAttribute('href')).toContain('gestionale.esercizi.piani-rate.show');
        expect(piano.getAttribute('href')).toContain('pianoRate=7');
        expect(piano.getAttribute('href')).toContain('esercizio=26');
        expect(freccia.getAttribute('aria-label')).toBe('Chiudi la riga');
    });
});

describe('«Scelgo io il passo»', () => {
    test('nessun passo segnato; il fondo della riga scrive alle persone della riga', async () => {
        const io = { ...viaVerdi, passo_proposto: false, righe: viaVerdi.righe.map((r) => ({ ...r, persone: r.persone.map((p) => ({ ...p, passo: { ...p.passo, passo: null } })) })) };
        await monta(io);
        expect(document.body.querySelectorAll('[data-testid="passo-proposto"]').length).toBe(0);
        expect(testo()).toContain('Scrivi alle 4 persone della riga');
    });
});

describe('il vuoto', () => {
    test('una linea con il segno verde', async () => {
        await monta({ ...viaVerdi, righe: [], rendiconti: [] });
        expect(testo()).toContain('Nessuna rata scaduta da seguire per i sei mesi.');
    });
});

describe('la forma', () => {
    test('nessuna scritta sotto i 12 px, badge con gli angoli rounded-md', () => {
        const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/components/gestionale/seiMesi/SeiMesiPerAgireWidget.vue'), 'utf8');
        expect(sorgente).not.toMatch(/text-\[(9|10|11)px\]/);
        expect(sorgente).not.toContain('rounded-full border px');
    });

    test('fra 640 e 1.023 pixel la tabella delle persone non stringe le colonne: le quattro colonne vengono da lg, e la risposta della riga va sotto', async () => {
        await monta(viaVerdi);
        const persona = document.body.querySelector('[data-testid="sei-mesi-25"] [data-testid="persona-1"]')!;
        expect(persona.className).toContain('lg:grid-cols-');
        expect(persona.className).not.toContain('md:grid-cols-');
        // I due pulsanti della persona vanno a capo se non stanno.
        expect(persona.querySelector('[data-testid="pulsanti-persona"]')!.className).toContain('flex-wrap');
        // La riga chiusa: la risposta a fianco solo da lg.
        expect(document.body.querySelector('[data-testid="sei-mesi-25"] [data-testid="testa-riga"]')!.className).toContain('lg:flex-row');
    });
});

describe('la tabella delle persone', () => {
    test('l\'intestazione su una riga, con «Non versato» e la sua «i», e le cinque colonne fisse da lg, le stesse nelle righe', async () => {
        await monta(viaVerdi);
        const riga = document.body.querySelector('[data-testid="sei-mesi-25"]')!;
        const intestazione = riga.querySelector('[data-testid="intestazione-persone"]')!;
        expect(Array.from(intestazione.querySelectorAll('[data-testid="colonna"]')).map((c) => (c.textContent ?? '').trim())).toEqual(['Persona', 'Non versato', 'A che punto è', 'Prossima azione', 'Registra che hai agito']);
        expect(intestazione.children).toHaveLength(5);
        expect(intestazione.className).toContain('whitespace-nowrap');
        expect(intestazione.textContent).toContain('Non versato');
        expect(riga.textContent).toContain('Le rate di questo esercizio non versate.');
        const colonne = (intestazione.className.match(/lg:grid-cols-\[[^\]]+\]/) ?? [''])[0];
        expect(colonne).not.toBe('');
        expect(riga.querySelector('[data-testid="persona-1"]')!.className).toContain(colonne);
        // Le colonne dei pulsanti hanno una larghezza fissa: i pulsanti sono larghi uguali in ogni riga.
        expect(colonne).toMatch(/_\d+(\.\d+)?rem_\d+(\.\d+)?rem\]$/);
    });

    test('la cifra a destra con le cifre della stessa larghezza; la frase e sotto, più piccola, la spiegazione', async () => {
        await monta({ ...viaVerdi, righe: [{ ...viaVerdi.righe[0], persone: [persona(1, 'Rossi', 124000, { ...passo('diffida', 'Nessuna lettera; la data dei sei mesi è il 30/06/2026.'),
            note: ['La rata del 10/05/2026, € 100,00, non è nel sollecito: è nella tolleranza.'] })] }] });
        const cifra = document.body.querySelector('[data-testid="persona-1"] [data-testid="non-versato"]')!;
        expect(cifra.className).toContain('tabular-nums');
        expect(cifra.className).toContain('text-right');
        const punto = document.body.querySelector('[data-testid="persona-1"] [data-testid="a-che-punto"]')!;
        expect(punto.children[0].textContent).toContain('Nessuna lettera; la data dei sei mesi è il 30/06/2026.');
        expect(punto.querySelector('[data-testid="spiegazione"]')!.textContent).toContain('non è nel sollecito: è nella tolleranza.');
    });

    test('a video «Prossima azione», mai «il passo»', async () => {
        await monta(viaVerdi);
        expect(testo()).toContain('Prossima azione');
        expect(testo()).not.toMatch(/\b[Pp]ass[oi]\b/);
    });
});

describe('il permesso, i collegamenti, le righe senza persone aperte', () => {
    test('senza il permesso dei compiti «Registra che hai agito» non c\'è, nemmeno nel menu dei passi', async () => {
        await monta(viaVerdi, false);
        expect(testo()).not.toContain('Registra che hai agito');
    });

    test('oltre 8 persone «Tutte le rate di questo esercizio →» c\'è una volta sola nella riga', async () => {
        await monta(importato);
        const riga = document.body.querySelector('[data-testid="sei-mesi-2022"]')!;
        expect((riga.textContent ?? '').split('Tutte le rate di questo esercizio →').length - 1).toBe(1);
    });

    test('«In agenda il …» è un collegamento all\'agenda', async () => {
        await monta({ ...viaVerdi, rendiconti: [{ ...viaVerdi.rendiconti[0], agenda: { giorno: '2026-06-29' } }] });
        const agenda = document.body.querySelector('[data-testid="in-agenda"]')!;
        expect(agenda.tagName).toBe('A');
        expect(agenda.getAttribute('href')).toContain('eventi.index');
        expect(agenda.textContent).toContain('In agenda il 29/06/2026');
    });

    test('una riga con tutte le persone chiuse non dice «0 persone»: dice chi è chiuso', async () => {
        const riga = { ...viaVerdi.righe[0], quante: 0, cifra: 0, persone: [], aperta: false,
            chiuse: [{ anagrafica_id: 5, nome: 'Gialli', cifra: 30000, motivo: 'agito', frase: 'Hai agito.' }, { anagrafica_id: 6, nome: 'Rosa', cifra: 30000, motivo: 'agito', frase: 'Hai agito.' }] };
        await monta({ ...viaVerdi, righe: [riga] });
        expect(testo()).not.toContain('0 persone');
        expect(testo()).toContain('Nessuna persona da seguire: hai registrato che hai agito per 2 persone');
    });

    test('chi ha avuto solo un promemoria ha il suo cartellino', () => {
        expect(testiCartellini({ senza_lettera: 1, promemoria: 2, sollecito: 0, diffida_spedita: 0, diffida_da_spedire: 0, accordo: 0, dispensata: 0 }))
            .toEqual(['1 senza lettera', '2 con il promemoria']);
    });
});
