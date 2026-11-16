// @vitest-environment jsdom

/**
 * La finestra «Fatto» dei sei mesi: le persone aperte della riga, tutte spuntate di serie; la ricerca per nome e il filtro per la situazione (un menu vSelect);
 * «Tutte» e «Nessuna» valgono per le persone che la ricerca e il filtro mostrano; il conteggio dice anche le spuntate che il filtro nasconde; il pulsante dice per
 * quante persone si registra e si spegne senza spunte.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({ router: { post: vi.fn() }, usePage: () => ({ props: {} }) }));

const Fatto = (await import('./SeiMesiFattoDialog.vue')).default;
const { personeDellaFinestra, SITUAZIONI } = await import('@/lib/gestionale/debiti/seiMesiPerAgire');

const persone = [
    { anagrafica_id: 1, nome: 'Rossi Mario', cifra: 124000, situazione: 'senza_lettera' }, { anagrafica_id: 2, nome: 'Bianchi Anna', cifra: 90000, situazione: 'diffida' },
    { anagrafica_id: 3, nome: 'Verdi Luca', cifra: 64000, situazione: 'accordo' }, { anagrafica_id: 4, nome: 'Neri Ugo', cifra: 46000, situazione: 'senza_lettera' },
];

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');

async function monta() {
    w = mount(Fatto, { attachTo: document.body, props: { open: true, esercizio: { id: 25, nome: 'Esercizio 2025' }, persone, oggi: '2026-05-20', errori: {} } });
    await flushPromises();

    return w;
}
const spunta = (id: number) => (document.body.querySelector(`[data-testid="persona-${id}"] button[role="checkbox"]`) as HTMLElement);
const pulsante = () => document.body.querySelector('[data-testid="registra-fatto"]') as HTMLButtonElement;

describe('il filtro e la ricerca, puri', () => {
    test('per nome e per situazione', () => {
        expect(personeDellaFinestra(persone, 'ross', null).map((p) => p.anagrafica_id)).toEqual([1]);
        expect(personeDellaFinestra(persone, '', 'senza_lettera').map((p) => p.anagrafica_id)).toEqual([1, 4]);
        expect(personeDellaFinestra(persone, 'neri', 'senza_lettera').map((p) => p.anagrafica_id)).toEqual([4]);
    });

    test('il filtro ha anche «Con il promemoria»', () => {
        expect(SITUAZIONI.map((s) => s.etichetta)).toEqual(['Tutte le situazioni', 'Senza lettera', 'Con il promemoria', 'Con il sollecito', 'Con la diffida', 'Con l\'accordo']);
    });
});

describe('la finestra «Fatto»', () => {
    test('di serie tutte spuntate; il titolo, la frase, il conteggio e il pulsante che conta', async () => {
        await monta();
        expect(testo()).toContain('Fatto: esercizio 2025');
        expect(testo()).toContain('Registri che hai agito per le persone spuntate.');
        expect(testo()).toContain('4 spuntate su 4');
        expect(pulsante().textContent).toContain('Registra per 4 persone');
        expect(document.body.querySelector('.menu-situazione')).not.toBeNull();
        expect(document.body.querySelector('select')).toBeNull();
    });

    test('«Nessuna» toglie le spunte delle persone mostrate; il pulsante si spegne e dice perché', async () => {
        await monta();
        (document.body.querySelector('[data-testid="nessuna"]') as HTMLButtonElement).click();
        await flushPromises();
        expect(testo()).toContain('0 spuntate su 4');
        expect(pulsante().disabled).toBe(true);
        expect(testo()).toContain('Spunta almeno una persona.');
        (document.body.querySelector('[data-testid="tutte"]') as HTMLButtonElement).click();
        await flushPromises();
        expect(testo()).toContain('4 spuntate su 4');
    });

    test('con la ricerca «Tutte» e «Nessuna» valgono per le persone mostrate, e il conteggio dice le spuntate nascoste', async () => {
        const f = await monta();
        const cerca = document.body.querySelector('[data-testid="cerca"]') as HTMLInputElement;
        cerca.value = 'neri';
        cerca.dispatchEvent(new Event('input'));
        await flushPromises();
        expect(document.body.querySelectorAll('[data-testid^="persona-"]').length).toBe(1);
        (document.body.querySelector('[data-testid="nessuna"]') as HTMLButtonElement).click();
        await flushPromises();
        expect(testo()).toContain('3 spuntate su 4');
        expect(testo()).toContain('3 spuntate non si vedono con il filtro');
        expect(f.emitted('registra')).toBeUndefined();
    });

    test('togliere una spunta dice che chi non è spuntato resta, e il pulsante manda solo le spuntate', async () => {
        const f = await monta();
        spunta(3).click();
        await flushPromises();
        expect(testo()).toContain('Chi non spunti resta nel riquadro, e il compito resta aperto per lui.');
        expect(testo()).toContain('3 spuntate su 4');
        const nota = document.body.querySelector('textarea') as HTMLTextAreaElement;
        nota.value = 'diffide mandate';
        nota.dispatchEvent(new Event('input'));
        await flushPromises();
        pulsante().click();
        expect(f.emitted('registra')![0][0]).toEqual({ persone: [1, 2, 4], giorno: '2026-05-20', nota: 'diffide mandate' });
    });
});
