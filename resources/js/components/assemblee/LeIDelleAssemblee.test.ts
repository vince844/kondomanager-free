// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Le «i» delle pagine delle assemblee hanno il suggerimento del programma (`SuggerimentoDellaI`, con `ui/tooltip`), che compare subito e al tocco, non il
 * `title` del browser, che compare dopo un secondo fermi e mai al tocco (in sala, sul tablet, non si apriva).
 *
 * - Le otto che restavano: nel punto la maggioranza, «Per chi» e «Con quale tabella»; nella delibera a mano il numero del verbale; nelle date «Per chi»;
 *   nel pagamento per l'urgenza il perché; in «Scegli la delibera» la sua «i»; nel memo «Per quale assemblea». Ognuna è un pulsante con il testo come
 *   etichetta, si apre al tocco e non sta dentro un'etichetta (un pulsante dentro un `<label>` ne diventerebbe il campo).
 * - Nel sorgente del modulo nessuna «i» con `title` torna.
 * - Sotto il menu della maggioranza del punto una riga con la regola scelta, con le stesse parole della sala.
 *
 * Che cosa non copre: il resto del programma (Coda 730); la posizione del suggerimento a video (la prova a video).
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: vi.fn(), patch: vi.fn() } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n, hasPermission: () => true }) }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { post: vi.fn(), patch: vi.fn(), delete: vi.fn(), reload: vi.fn(), visit: vi.fn() } }));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options'], template: '<div class="v-select-finto" />' } }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; axiosGet.mockReset(); document.body.innerHTML = ''; });

const scelte = { tipi: [], perimetri: [], modalita: [], materie: [{ valore: 'lavori', nome: 'Lavori' }, { valore: 'altro', nome: 'Altro' }],
    maggioranze: [{ valore: 'ordinaria', nome: 'Ordinaria' }, { valore: 'meta_del_valore', nome: 'Metà del valore' }], esiti: [], a_chi: [], unita: 26, palazzine: [],
    scale: [], tabelle: [], maggioranze_proposte: {} };

/** La «i» del programma: un pulsante con il testo come etichetta, senza `title`, fuori da ogni etichetta; al tocco il suggerimento con lo stesso testo. */
async function provaLaI(testid: string, testo: string) {
    const i = document.body.querySelector<HTMLElement>(`[data-testid="${testid}"]`);
    expect(i, testid).not.toBeNull();
    expect(i!.tagName, testid).toBe('BUTTON');
    expect(i!.getAttribute('title'), testid).toBeNull();
    expect(i!.getAttribute('aria-label'), testid).toBe(testo);
    expect(i!.closest('label'), testid).toBeNull();
    i!.click();
    await flushPromises();
    const aperti = [...document.body.querySelectorAll('[data-testid="suggerimento"] p')].map((p) => p.textContent?.replace(/\s+/g, ' ').trim());
    expect(aperti, testid).toContain(testo);
    i!.click();
    await flushPromises();
}

describe('le otto «i» delle assemblee', () => {
    test('il punto: la maggioranza, «Per chi» e «Con quale tabella»', async () => {
        const Finestra = (await import('./FinestraDelPunto.vue')).default;
        w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, condominioNome: 'Via Roma 12', assembleaNome: 'Assemblea ordinaria',
            tabellaDellAssemblea: 'Proprietà', punto: null, scelte, nellaPagina: true } as never });
        await flushPromises();
        await provaLaI('i-maggioranza', 'Il programma propone la maggioranza della materia: per lavori di notevole entità e per le innovazioni agevolate la metà '
            + 'del valore (art. 1136 c. 4), per le altre innovazioni due terzi (c. 5). La scegli tu.');
        await provaLaI('i-per-chi', 'Di serie il punto è di tutta l\'assemblea. Per una spesa di una sola scala, scegli la scala: in sala per quel punto contano solo i '
            + 'suoi condòmini.');
        await provaLaI('i-tabella', 'Una tabella che non copre la scala si può scegliere lo stesso: il controllo sotto lo dice, come per l\'assemblea, e non blocca.');
    });

    test('la delibera a mano: il numero del verbale, e l\'etichetta resta del suo campo', async () => {
        axiosGet.mockResolvedValue({ data: { palazzine: [], scale: [], frase: null, riunione: null, riunioni: [] } });
        const Finestra = (await import('./RegistraDeliberaAMano.vue')).default;
        w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3 } as never });
        await flushPromises();
        await provaLaI('i-numero-verbale', 'Il numero scritto sul verbale di carta, per esempio 3/2024. Due delibere della stessa sera con lo stesso numero stanno nella '
            + 'stessa riunione.');
        const numero = document.body.querySelector<HTMLInputElement>('[data-testid="numero"]')!;
        expect(document.body.querySelector(`label[for="${numero.id}"]`)?.textContent?.trim()).toBe('Numero del verbale');
    });

    test('le date: «Per chi» dell\'assemblea', async () => {
        const Date = (await import('./DateDellAssemblea.vue')).default;
        w = mount(Date as never, { attachTo: document.body, props: { modelValue: { tipo: 'ordinaria', perimetro: 'condominio', luogo: '', modalita: 'presenza', piattaforma: '',
            consenso_videoconferenza_il: '', consenso_videoconferenza_modo: '', sedute: [{ convocazione: 'prima', numero: 1, giorno: '', ora: '08:00' },
                { convocazione: 'seconda', numero: 1, giorno: '', ora: '18:30' }] }, scelte } as never });
        await flushPromises();
        await provaLaI('i-per-chi-assemblea', 'Un\'assemblea di tutto il condominio, di una palazzina, di una scala o dei soli condòmini di una tabella segnata «di un '
            + 'gruppo»: decide chi è convocato e la tabella del valore. Un punto della sola scala B si sceglie nel punto.');
    });

    test('il pagamento per l\'urgenza: il perché, e l\'etichetta resta del suo campo', async () => {
        const Finestra = (await import('./PagaPerLUrgenza.vue')).default;
        w = mount(Finestra, { attachTo: document.body, props: { open: false, condominioId: 3, condominioNome: 'Via Roma 12', fattura: { id: 45, numero_documento: '45',
            data_documento: '2026-11-12', fornitore_nome: 'Idraulica Bianchi', totale_documento: 180000, stato_approvazione: 'sforo_motivato', sforo_oltre: 30000,
            voce_sforo: 'Manutenzione', motivazione_sforo: null } } as never });
        await w.setProps({ open: true });
        await flushPromises();
        await provaLaI('i-perche-urgente', 'Per esempio: perdita dalla colonna dell\'acqua, riparata il 12/11 per non allagare gli appartamenti sotto.');
        const motivo = document.body.querySelector<HTMLTextAreaElement>('[data-testid="motivo-urgenza"]')!;
        expect(document.body.querySelector(`label[for="${motivo.id}"]`)?.textContent?.trim()).toBe('Perché era urgente');
    });

    test('«Scegli la delibera»: la sua «i»', async () => {
        axiosGet.mockResolvedValue({ data: { proposte: [], gruppi: [], memo: null, raccolta: null } });
        const Scegli = (await import('./ScegliLaDelibera.vue')).default;
        w = mount(Scegli, { attachTo: document.body, props: { modelValue: null, condominioId: 3, condominioNome: 'Via Roma 12', ruolo: 'delibera', oggettoTipo: 'piano_rate',
            oggettoId: 12, perQuesto: 'questo piano', info: 'Le delibere di questo condominio: in testa quelle che parlano di questo piano, con il perché.' } as never });
        await flushPromises();
        await provaLaI('i-delibera', 'Le delibere di questo condominio: in testa quelle che parlano di questo piano, con il perché.');
    });

    test('il memo: «Per quale assemblea»', async () => {
        axiosGet.mockResolvedValue({ data: { testo: 'Ratifica della spesa', origine: null, assemblee: [], collaboratori: [], gia_proposto: null, esistente: null,
            condominio: 'Via Roma 12' } });
        const Finestra = (await import('./FinestraDelMemo.vue')).default;
        w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, origineTipo: 'fattura_passiva', origineId: 45 } as never });
        await flushPromises();
        await provaLaI('i-per-quale-assemblea', '«La prossima» vuol dire la prima che prepari: il memo ti aspetta lì. Se un\'assemblea è già in preparazione, puoi sceglierla.');
    });

    test('nel sorgente del modulo nessuna «i» con il title del browser', () => {
        const cartelle = ['resources/js/components/assemblee', 'resources/js/pages/gestionale/assemblee'];
        const file = (c: string): string[] => readdirSync(resolve(process.cwd(), c)).flatMap((f) => {
            const p = join(c, f);
            return statSync(resolve(process.cwd(), p)).isDirectory() ? file(p) : p.endsWith('.vue') ? [p] : [];
        });
        const conTitle = cartelle.flatMap(file).flatMap((f) => [...readFileSync(resolve(process.cwd(), f), 'utf8').matchAll(/<[a-zA-Z]+\b[^>]*\bcursor-help\b[^>]*>/g)]
            .filter((m) => /\s:?title=/.test(m[0])).map(() => f));
        expect(conTitle).toEqual([]);
    });
});

describe('la riga della maggioranza sotto il menu del punto', () => {
    const monta = async (bozza: Record<string, unknown> | null) => {
        const Finestra = (await import('./FinestraDelPunto.vue')).default;
        w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, condominioNome: 'Via Roma 12', assembleaNome: 'Assemblea ordinaria',
            tabellaDellAssemblea: 'Proprietà', punto: null, scelte, nellaPagina: true, ...(bozza ? { bozza } : {}) } as never });
        await flushPromises();
        return document.body.querySelector('[data-testid="regola-maggioranza"]')?.textContent?.replace(/\s+/g, ' ').trim();
    };

    test('la regola scelta, a parole e con l\'articolo, le stesse parole della sala', async () => {
        expect(await monta(null)).toBe('Maggioranza degli intervenuti e almeno metà del valore in prima convocazione, un terzo in seconda (art. 1136 c. 2 e 3).');
        w!.unmount();
        document.body.innerHTML = '';
        expect(await monta({ testo: 'Il portone', testo_pubblico: null, materia: 'lavori', maggioranza: 'meta_del_valore', conduttore: null, ambito: 'condominio',
            palazzina_id: null, scala_id: null, tabella_id: null, importo: null })).toBe('Maggioranza degli intervenuti e almeno la metà del valore (art. 1136 c. 4).');
    });

    test('le parole: ogni maggioranza la sua; senza maggioranza decide il presidente', async () => {
        const { regolaDellaMaggioranza } = await import('@/lib/assemblee/assemblee') as unknown as { regolaDellaMaggioranza: (m: string | null) => string };
        expect(regolaDellaMaggioranza('due_terzi')).toBe('Maggioranza degli intervenuti e almeno due terzi del valore (art. 1136 c. 5).');
        expect(regolaDellaMaggioranza('quattro_quinti')).toBe('Quattro quinti dei condòmini e del valore (art. 1117-ter).');
        expect(regolaDellaMaggioranza('senza_voto')).toBe('Senza voto: il punto si tratta.');
        expect(regolaDellaMaggioranza(null)).toBe('La maggioranza del punto non è scelta: decide il presidente.');
    });
});

describe('la «i» è l\'icona del resto del programma', () => {
    test('`SuggerimentoDellaI` e il bottone di «Come conto» mostrano l\'icona `Info` da 14 px, non un cerchio con la lettera', async () => {
        const I = (await import('./convocazione/SuggerimentoDellaI.vue')).default;
        w = mount(I, { attachTo: document.body, props: { testo: 'Una spiegazione.', testid: 'la-i' } as never });
        const b = w.get('[data-testid="la-i"]');
        expect(b.text()).toBe('');
        expect(b.attributes('aria-label')).toBe('Una spiegazione.');
        const icona = b.get('svg');
        expect(icona.classes()).toEqual(expect.arrayContaining(['h-3.5', 'w-3.5', 'text-slate-400', 'dark:text-slate-500']));
        expect(b.classes()).not.toContain('rounded-full');
        const sorgente = readFileSync(resolve(__dirname, 'convocazione/EntroQuandoSpedire.vue'), 'utf8');
        expect(sorgente).toContain('<Info');
        expect(sorgente).not.toMatch(/>i<\/button>/);
    });
});
