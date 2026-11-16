// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La lista «Sei mesi per agire · tutti i condomini» del cassetto dell'Action inbox. La pagina porta i condomini; `useSeiMesiDelloStudio` li chiede uno alla
 * volta, in fila, e la lista mette insieme le righe mentre arrivano: le righe grigie e «Calcolo i condomini: 12 su 40…» mentre carica, tre sezioni («Da
 * seguire adesso», «Rendiconto in assemblea», «Più avanti») con le date più vicine prima fra tutti i condomini, la regola delle 8 con «Mostra tutte» in ogni
 * sezione, il dettaglio di ogni riga con le persone, il vuoto, i condomini che non si sono potuti calcolare con «Riprova». Ogni riga ha «Apri le persone →»,
 * che porta al riquadro del suo condominio, aperto sulla sua riga. Nessuna scritta sotto gli 11 px (gli 11 solo per le etichette maiuscole), nessuna pillola.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h, reactive } from 'vue';

const richieste = vi.hoisted(() => ({ attese: [] as Array<{ id: number; signal?: AbortSignal; risolvi: (d: unknown) => void; rifiuta: (e: unknown) => void }> }));
vi.mock('axios', () => ({
    default: {
        get: vi.fn((url: string, opzioni?: { signal?: AbortSignal }) => new Promise((risolvi, rifiuta) => {
            const id = Number(new URL(url, 'http://x').searchParams.get('condominio'));
            richieste.attese.push({ id, signal: opzioni?.signal, risolvi: (d) => risolvi({ data: d }), rifiuta });
        })),
    },
}));
vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { visit: vi.fn() },
    usePage: () => ({ props: {} }),
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => `admin.${nome}`, hasPermission: () => true }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, p?: Record<string, unknown>) => `/${nome}${p ? '?' + new URLSearchParams(p as Record<string, string>).toString() : ''}`;

const axios = (await import('axios')).default;
const Lista = (await import('./SeiMesiDelloStudio.vue')).default;
const { useSeiMesiDelloStudio } = await import('@/composables/useSeiMesiDelloStudio');
const { FRASE_VUOTO_STUDIO, testoCaricamento } = await import('@/lib/gestionale/debiti/seiMesiDelloStudio');

const riga = (eid: number, data: string, giorni: number, colore: string, quante: number, cifra: number, altro: Record<string, unknown> = {}) => ({
    chiave: `sei-mesi-${eid}`, esercizio: { id: eid, nome: `Esercizio ${2000 + (eid % 100)}` }, data, giorni, colore, quante, cifra,
    persone: Array.from({ length: quante }, (_, i) => ({ anagrafica_id: eid * 100 + i, nome: `Persona ${eid}-${i}`, cifra: Math.round(cifra / quante) })),
    chiuse: { agito: 0, dispensa: 0 }, compito: giorni <= 60, compito_tornato: null, risposta: null, ...altro,
});
const condominio = (id: number, nome: string, righe: unknown[] = [], rendiconti: unknown[] = [], troppe: unknown = null) =>
    ({ condominio: { id, nome }, oggi: '2026-05-20', troppe, righe, rendiconti });

let carta: VueWrapper | null = null;
beforeEach(() => {
    richieste.attese = [];
    vi.mocked(axios.get).mockClear();
});
afterEach(() => {
    carta?.unmount();
    carta = null;
});

/** Risponde alla richiesta in attesa del condominio dato, e aspetta che la carta chieda il prossimo. */
async function rispondi(id: number, dati: unknown) {
    await flushPromises();
    const r = richieste.attese.find((x) => x.id === id);
    if (!r) throw new Error(`nessuna richiesta per ${id}`);
    richieste.attese = richieste.attese.filter((x) => x !== r);
    r.risolvi(dati);
    await flushPromises();
}

/** La lista con i condomini che arrivano in fila, come nella pagina. */
function monta(condomini: Array<{ id: number; nome: string }>) {
    const Involucro = defineComponent({
        setup() {
            const stato = reactive(useSeiMesiDelloStudio(() => condomini));

            return () => h(Lista, { stato, onRiprova: stato.riprova });
        },
    });
    carta = mount(Involucro);

    return carta;
}

const testo = (w: VueWrapper) => w.text().replace(/\s+/g, ' ');

describe('mentre carica', () => {
    test('le righe grigie e «Calcolo i condomini: 12 su 40…»; i condomini si chiedono uno alla volta, in fila', async () => {
        const quaranta = Array.from({ length: 40 }, (_, i) => ({ id: i + 1, nome: `Condominio ${i + 1}` }));
        const w = monta(quaranta);
        await flushPromises();

        expect(testo(w)).toContain('Calcolo i condomini: 0 su 40…');
        expect(w.findAll('[data-testid="riga-grigia"]')).toHaveLength(3);
        expect(richieste.attese).toHaveLength(1);

        for (let id = 1; id <= 12; id++) {
            await rispondi(id, condominio(id, `Condominio ${id}`));
        }
        expect(testo(w)).toContain('Calcolo i condomini: 12 su 40…');
        expect(testoCaricamento(12, 40)).toBe('Calcolo i condomini: 12 su 40…');
        // Sempre una richiesta sola alla volta.
        expect(richieste.attese).toHaveLength(1);
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(13);
    });

    test('le righe che arrivano si vedono subito, ordinate; alla fine le righe grigie spariscono', async () => {
        const w = monta([{ id: 1, nome: 'Via Verdi 12' }, { id: 2, nome: 'Corso Italia 3' }]);
        await rispondi(1, condominio(1, 'Via Verdi 12', [riga(25, '2026-06-30', 41, 'vicina', 4, 324000)]));

        expect(w.findAll('[data-testid="riga-studio"]')).toHaveLength(1);
        expect(w.findAll('[data-testid="riga-grigia"]')).toHaveLength(3);

        await rispondi(2, condominio(2, 'Corso Italia 3', [riga(31, '2026-05-08', -12, 'passata', 2, 50000)]));
        expect(w.findAll('[data-testid="riga-grigia"]')).toHaveLength(0);
        expect(testo(w)).not.toContain('Calcolo i condomini');
        // La data passata da più tempo prima, anche se il suo condominio è arrivato dopo.
        expect(w.findAll('[data-testid="riga-studio"]').map((r) => r.attributes('data-chiave'))).toEqual(['2-sei-mesi-31', '1-sei-mesi-25']);
    });

    test('lasciando la pagina la fila si ferma', async () => {
        const w = monta([{ id: 1, nome: 'A' }, { id: 2, nome: 'B' }]);
        await flushPromises();
        const prima = richieste.attese[0];
        w.unmount();
        carta = null;
        expect(prima.signal?.aborted).toBe(true);
        prima.risolvi(condominio(1, 'A'));
        await flushPromises();
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);
    });
});

describe('la lista', () => {
    async function seiCondomini() {
        const w = monta([1, 2, 3, 4, 5, 6].map((id) => ({ id, nome: `C${id}` })));
        await rispondi(1, condominio(1, 'Via Verdi 12', [riga(25, '2026-06-30', 41, 'vicina', 4, 324000), riga(26, '2027-06-30', 406, 'lontana', 2, 50000)],
            [{ chiave: 'rendiconto-25', esercizio: { id: 25, nome: 'Esercizio 2025' }, data: '2026-06-29', giorni: 40, colore: 'fermato', stato: 'presentata',
                breve: 'Fermato il 12/05/2026', frase: 'Fermato per l\'assemblea il 12/05/2026.', passo: 'approvazione', url: '/sintesi/25', compito: false, risposta: null }]));
        await rispondi(2, condominio(2, 'Corso Italia 3', [riga(31, '2026-05-08', -12, 'passata', 3, 90000)]));
        await rispondi(3, condominio(3, 'Piazza Duomo 1', [riga(41, '2026-06-30', 41, 'vicina', 1, 20000,
            { compito: false, risposta: { stato: 'ricordamelo', ricordami_il: '2026-06-15', frase: 'Ti ricordo il 15/06/2026.' } })]));
        await rispondi(4, condominio(4, 'Viale Monza 8', [riga(51, '2027-06-30', 406, 'lontana', 2, 40000)]));
        await rispondi(5, condominio(5, 'Via Po 2', [riga(61, '2027-06-30', 406, 'lontana', 1, 10000,
            { compito: false, risposta: { stato: 'non_serve', ricordami_il: null, frase: 'Non serve: «assemblea informata» (12/05/2026, Vincenzo).' } })],
            [{ chiave: 'rendiconto-60', esercizio: { id: 60, nome: 'Esercizio 2025' }, data: '2026-06-29', giorni: 40, colore: 'vicina', stato: 'bozza',
                breve: 'Bozza', frase: 'Bozza: le cifre cambiano a ogni registrazione.', passo: 'apri', url: '/sintesi/60', compito: true, risposta: null }]));
        await rispondi(6, condominio(6, 'Largo Augusto 5'));

        return w;
    }

    test('le righe di tutti i condomini in tre sezioni, le date più vicine prima; i condomini senza righe non ci sono; «Più avanti · 3 condomini»', async () => {
        const w = await seiCondomini();
        const righe = w.findAll('[data-testid="riga-studio"]');

        // A pari data, per nome del condominio; prima le date passate o entro 60 giorni, poi quelle più avanti.
        expect(righe.map((r) => r.attributes('data-chiave'))).toEqual(['2-sei-mesi-31', '3-sei-mesi-41', '1-sei-mesi-25', '5-sei-mesi-61', '1-sei-mesi-26', '4-sei-mesi-51']);
        expect(w.findAll('[data-testid="sezione"]').map((s) => s.attributes('data-sezione'))).toEqual(['adesso', 'rendiconti', 'avanti']);
        expect(w.findAll('[data-testid="sezione"]')[0].findAll('[data-testid="riga-studio"]')).toHaveLength(3);
        expect(testo(w.findAll('[data-testid="sezione"]')[2])).toContain('Più avanti · 3 condomini');
        expect(testo(w)).not.toContain('Largo Augusto 5');

        const prima = testo(righe[0]);
        expect(prima).toContain('08/05/2026');
        expect(prima).toContain('passata da 12 giorni');
        expect(prima).toContain('Corso Italia 3');
        expect(prima).toContain('esercizio 2031');
        expect(prima).toContain('3 persone');
        expect(prima).toContain('€ 900,00');
        expect(prima).toContain('compito aperto');
        expect(righe[0].find('[data-testid="giorni"]').classes()).toContain('text-red-600');
        expect(righe[2].find('[data-testid="giorni"]').classes()).toContain('text-amber-700');
        expect(righe[4].find('[data-testid="giorni"]').classes()).toContain('text-slate-500');
        expect(testo(righe[1])).toContain('Ti ricordo il 15/06/2026');
        expect(testo(righe[3])).toContain('Non serve');
    });

    test('ogni riga porta al riquadro del suo condominio, aperto sulla sua riga: «Apri le persone →» scritto per intero, in ogni sezione', async () => {
        const w = await seiCondomini();
        const azione = w.findAll('[data-testid="riga-studio"]')[2].find('[data-testid="azione-riga"]');

        expect(azione.text()).toContain('Apri le persone');
        expect(azione.attributes('href')).toBe('/admin.gestionale.index?condominio=1#sei-mesi-25');
        expect(w.findAll('[data-testid="riga-studio"]').every((r) => r.find('[data-testid="azione-riga"]').text().includes('Apri le persone'))).toBe(true);
        // Il nome del condominio porta allo stesso posto.
        expect(w.findAll('[data-testid="riga-studio"]')[2].find('[data-testid="vai-al-riquadro"]').attributes('href')).toBe('/admin.gestionale.index?condominio=1#sei-mesi-25');
    });

    test('il cassetto della riga: la freccia apre le persone con la cifra; chi è chiuso in una frase; la risposta', async () => {
        const w = await seiCondomini();
        const r = w.findAll('[data-testid="riga-studio"]')[2];
        expect(r.find('[data-testid="persone-studio"]').exists()).toBe(false);

        await r.find('[data-testid="apri-riga"]').trigger('click');
        const persone = r.find('[data-testid="persone-studio"]');
        expect(persone.findAll('[data-testid="persona-studio"]')).toHaveLength(4);
        expect(testo(persone)).toContain('Persona 25-0');
        expect(testo(persone)).toContain('€ 810,00');
        expect(r.find('[data-testid="apri-riga"]').attributes('aria-label')).toBe('Chiudi la riga');

        const piazza = w.findAll('[data-testid="riga-studio"]')[1];
        await piazza.find('[data-testid="apri-riga"]').trigger('click');
        expect(testo(piazza)).toContain('Ti ricordo il 15/06/2026.');
    });

    test('il cassetto con più di 8 persone: le 5 con la cifra più alta e quante mancano, con la loro cifra', async () => {
        const w = monta([{ id: 1, nome: 'A' }]);
        const nove = riga(25, '2026-06-30', 41, 'vicina', 9, 900000);
        nove.persone = nove.persone.map((p, i) => ({ ...p, cifra: 100000 - i * 1000 }));
        nove.chiuse = { agito: 1, dispensa: 1 };
        await rispondi(1, condominio(1, 'Via Verdi 12', [nove]));
        await w.find('[data-testid="apri-riga"]').trigger('click');

        expect(w.findAll('[data-testid="persona-studio"]')).toHaveLength(5);
        expect(testo(w)).toContain('Qui le 5 con la cifra più alta; mancano 4 persone, € 3.740,00');
        expect(testo(w)).toContain('Hai registrato che hai agito per 1 persona; 1 è dispensata');
    });

    test('la sezione del rendiconto: la data, il condominio, lo stato breve, l\'azione del caso', async () => {
        const w = await seiCondomini();
        const rendiconti = w.findAll('[data-testid="rendiconto-studio"]');

        expect(testo(w)).toContain('Rendiconto in assemblea');
        // A pari data, per nome del condominio: Via Po 2 prima di Via Verdi 12.
        expect(rendiconti.map((r) => r.attributes('data-chiave'))).toEqual(['5-rendiconto-60', '1-rendiconto-25']);
        const verdi = rendiconti[1];
        expect(testo(verdi)).toContain('29/06/2026');
        expect(testo(verdi)).toContain('fra 40 giorni');
        expect(testo(verdi)).toContain('Via Verdi 12');
        expect(testo(verdi)).toContain('rendiconto 2025');
        expect(testo(verdi)).toContain('Fermato il 12/05/2026');
        expect(verdi.find('[data-testid="azione-riga"]').text()).toContain('Registra l\'approvazione');
        expect(verdi.find('[data-testid="azione-riga"]').attributes('href')).toBe('/sintesi/25');
        expect(rendiconti[0].find('[data-testid="azione-riga"]').text()).toContain('Apri il rendiconto');
        expect(testo(rendiconti[0])).toContain('compito aperto');
        // Il nome del condominio porta al riquadro, sulla riga del rendiconto.
        expect(verdi.find('[data-testid="vai-al-riquadro"]').attributes('href')).toBe('/admin.gestionale.index?condominio=1#rendiconto-25');
    });

    test('la regola delle 8 in ogni sezione: fino a 8 righe tutte; oltre, le 5 più vicine, quante mancano e in quanti condomini, e «Mostra tutte»', async () => {
        const otto = monta([{ id: 1, nome: 'A' }]);
        await rispondi(1, condominio(1, 'A', Array.from({ length: 8 }, (_, i) => riga(i + 1, `2026-06-${10 + i}`, 21 + i, 'vicina', 1, 1000))));
        expect(otto.findAll('[data-testid="riga-studio"]')).toHaveLength(8);
        expect(otto.find('[data-testid="fondo-righe"]').exists()).toBe(false);
        otto.unmount();

        const w = monta([{ id: 1, nome: 'A' }, { id: 2, nome: 'B' }, { id: 3, nome: 'C' }]);
        await rispondi(1, condominio(1, 'A', [1, 2, 3, 4].map((i) => riga(i, `2026-06-0${i}`, 12 + i, 'vicina', 1, 1000))));
        await rispondi(2, condominio(2, 'B', [5, 6, 7].map((i) => riga(i, `2026-07-0${i}`, 40 + i, 'vicina', 1, 1000))));
        await rispondi(3, condominio(3, 'C', [8, 9].map((i) => riga(i, `2026-08-0${i}`, 50 + i, 'vicina', 1, 1000))));

        expect(w.findAll('[data-testid="riga-studio"]')).toHaveLength(5);
        expect(testo(w.find('[data-testid="fondo-righe"]'))).toContain('Qui le 5 più vicine; mancano 4 righe, in 2 condomini');
        await w.find('[data-testid="mostra-tutte"]').trigger('click');
        expect(w.findAll('[data-testid="riga-studio"]')).toHaveLength(9);
        expect(w.find('[data-testid="mostra-tutte"]').text()).toBe('Mostra meno');
    });

    test('vuoto: nessun condominio con righe', async () => {
        const w = monta([{ id: 1, nome: 'A' }, { id: 2, nome: 'B' }]);
        await rispondi(1, condominio(1, 'A'));
        await rispondi(2, condominio(2, 'B'));

        expect(testo(w)).toContain(FRASE_VUOTO_STUDIO);
        expect(w.find('[data-testid="sezione"]').exists()).toBe(false);
    });

    test('un condominio con troppe rate scadute lo dice, con l\'elenco per data dei sei mesi', async () => {
        const w = monta([{ id: 7, nome: 'Grande' }]);
        await rispondi(7, condominio(7, 'Residenza Grande', [], [], { frase: 'Questo condominio ha troppe rate scadute per calcolare qui i sei mesi: le date sono nell\'elenco «Rate scadute».' }));

        expect(testo(w)).toContain('Residenza Grande');
        expect(testo(w)).toContain('troppe rate scadute');
        expect(w.find('[data-testid="troppe"] a').attributes('href')).toBe('/admin.gestionale.rate-scadute.index?condominio=7&ordine=sei_mesi');
    });

    test('un condominio che non si è potuto calcolare: lo dice per nome, e «Riprova» lo chiede di nuovo; uno che non c\'è più non conta', async () => {
        const w = monta([{ id: 1, nome: 'Via Verdi 12' }, { id: 2, nome: 'Cancellato' }, { id: 3, nome: 'Corso Italia 3' }]);
        await flushPromises();
        richieste.attese.shift()!.rifiuta({ response: { status: 500 } });
        await flushPromises();
        richieste.attese.shift()!.rifiuta({ response: { status: 404 } });
        await rispondi(3, condominio(3, 'Corso Italia 3', [riga(31, '2026-05-08', -12, 'passata', 3, 90000)]));

        expect(testo(w)).toContain('Non ho potuto calcolare 1 condominio: Via Verdi 12.');
        expect(testo(w)).not.toContain('Cancellato');
        await w.find('[data-testid="riprova"]').trigger('click');
        await rispondi(1, condominio(1, 'Via Verdi 12', [riga(25, '2026-06-30', 41, 'vicina', 4, 324000)]));

        expect(testo(w)).not.toContain('Non ho potuto calcolare');
        expect(w.findAll('[data-testid="riga-studio"]')).toHaveLength(2);
    });
});

describe('lo stato delle righe lontane', () => {
    test('una riga lontana senza compito dice quando il compito arriva nell\'Inbox; una vicina ha il compito, non la data', async () => {
        const w = monta([{ id: 1, nome: 'A' }]);
        await rispondi(1, condominio(1, 'Via Verdi 12', [
            riga(25, '2027-06-30', 406, 'lontana', 2, 125000),
            riga(26, '2026-06-30', 41, 'vicina', 4, 324000),
        ]));
        const stati = w.findAll('[data-testid="compito-dal"]');

        expect(stati).toHaveLength(1);
        expect(stati[0].text()).toBe('compito dal 01/05/2027');
        expect(testo(w)).toContain('compito aperto');
    });

    test('l\'anno dell\'esercizio senza «Esercizio» e senza «anno»', async () => {
        const { testoEsercizio, testoRendiconto } = await import('@/lib/gestionale/debiti/seiMesiDelloStudio');

        expect(testoEsercizio({ esercizio: { nome: 'Esercizio anno 2026' } })).toBe('esercizio 2026');
        expect(testoEsercizio({ esercizio: { nome: 'Anno 2026' } })).toBe('esercizio 2026');
        expect(testoRendiconto({ esercizio: { nome: 'Esercizio 2024/2025' } })).toBe('rendiconto 2024/2025');
    });
});

describe('le scritte', () => {
    const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/components/gestionale/seiMesi/SeiMesiDelloStudio.vue'), 'utf8');

    test('nessuna scritta sotto gli 11 px, gli 11 solo per le etichette maiuscole; nessuna pillola', () => {
        expect(sorgente).not.toMatch(/text-\[(9|10)px\]/);
        for (const m of sorgente.matchAll(/class="([^"]*text-\[11px\][^"]*)"/g)) {
            expect(m[1]).toContain('uppercase');
        }
        expect(sorgente).not.toContain('rounded-full');
    });

    test('le colonne su una riga, con «Prossima azione»; le cifre a destra con le cifre allineate', async () => {
        const w = monta([{ id: 1, nome: 'A' }]);
        await rispondi(1, condominio(1, 'Via Verdi 12', [riga(25, '2026-06-30', 41, 'vicina', 4, 324000)]));
        const intestazione = w.find('[data-testid="intestazione-studio"]');

        expect(intestazione.classes()).toContain('whitespace-nowrap');
        expect(intestazione.findAll('[data-testid="colonna"]').map((c) => c.text())).toEqual(['Data', 'Condominio', 'Non versato', 'Stato', 'Prossima azione']);
        expect(w.find('[data-testid="non-versato"]').classes()).toEqual(expect.arrayContaining(['tabular-nums', '@2xl:text-right']));
    });
});
