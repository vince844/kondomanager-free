// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Crea il piano straordinario» (decisione 622 (1), la scena 2 della bozza approvata): la finestra legge la proposta dalla delibera, la mostra nei tre gruppi
 * con i titoli piccoli (la gestione, la voce di spesa, dopo), dice nel piede che cosa nasce, e la differenza dalla delibera se l'importo cambia; confermata,
 * manda i campi com'erano scritti (l'importo in euro: lo converte il server) e apre il nuovo piano. Con la gestione già legata alla delibera, «Apri il piano».
 * Gli errori del server stanno sotto il campo. La forma delle finestre: ancorata in alto, il piede fisso. Approvata con modifiche: in testa «Che cosa ha
 * cambiato l'assemblea», l'importo da scrivere, nessuna differenza dalla cifra di prima.
 *
 * Che cosa non copre: il server (`CreaIlPianoStraordinarioTest`); i menu aperti a mano.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
const axiosPost = vi.fn();
const visita = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n, hasPermission: () => true }) }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal<typeof import('@inertiajs/vue3')>()), router: { visit: (...a: unknown[]) => visita(...a) } }));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options'], template: '<div class="v-select-finto" :data-valore="modelValue" />' } }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Finestra = (await import('./CreaIlPianoStraordinario.vue')).default;
const proposta = {
    cosa: { id: 9, testo: 'Crea il piano straordinario (€ 14.500,00)', da_fare: true },
    modifiche: null as string | null,
    delibera: { id: 4, frase: 'Dalla delibera del 15/12/2026, punto 4 (verbale n. 42): «Portone: € 14.500,00 con Serramenti Marino».', importo: 1450000 as number | null },
    gestione_esistente: null,
    gestione: { nome: 'Portone 2026', esercizio_id: 1, data_inizio: '2026-01-01', data_fine: '2026-12-31' },
    esercizi: [{ id: 1, nome: '2026', data_inizio: '2026-01-01', data_fine: '2026-12-31' }, { id: 2, nome: '2027', data_inizio: '2027-01-01', data_fine: '2027-12-31' }],
    voce: { nome: 'Portone', importo: 1450000 as number | null, tabella_id: 5, fornitore_id: null },
    tabelle: [{ id: 5, nome: 'Proprietà' }],
    fornitori: [{ id: 3, nome: 'Serramenti Marino' }],
    a_carico: 'A carico del proprietario, come ogni spesa straordinaria. Se cambi l\'importo, il riepilogo dice la differenza dalla delibera.',
};

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; axiosGet.mockReset(); axiosPost.mockReset(); visita.mockReset(); document.body.innerHTML = ''; });
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel);
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');
async function monta(p = proposta) {
    axiosGet.mockResolvedValue({ data: p });
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, assembleaId: 7, cosaId: 9 } });
    await flushPromises();
}
async function scrivi(id: string, valore: string) {
    const c = q<HTMLInputElement>(`[data-testid="${id}"]`)!;
    c.value = valore;
    c.dispatchEvent(new Event('input'));
    await flushPromises();
}

describe('«Crea il piano straordinario»', () => {
    test('senza una tabella proposta il menu è vuoto e la frase dice di sceglierla: la finestra non prende la prima dell\'elenco', async () => {
        await monta({ ...proposta, voce: { ...proposta.voce, tabella_id: null }, tabelle: [{ id: 4, nome: 'Acqua — consumi' }, { id: 5, nome: 'Scale' }] } as never);
        expect(q('[data-testid="scegli-la-tabella"]')!.textContent).toContain('Scegli la tabella: nessuna è segnata come di proprietà generale.');
        expect(q('.v-select-finto')).not.toBeNull();
        expect(q<HTMLButtonElement>('[data-testid="crea-e-apri"]')!.disabled).toBe(true);
    });

    test('con la tabella proposta la frase non c\'è', async () => {
        await monta();
        expect(q('[data-testid="scegli-la-tabella"]')).toBeNull();
    });

    test('la proposta dalla delibera nei tre gruppi; il piede dice che cosa nasce; ancorata in alto', async () => {
        await monta();
        expect(axiosGet).toHaveBeenCalledWith('/gestionale.assemblee.seguito.piano-straordinario.proposta');
        const t = testo();
        expect(t).toContain('Dalla delibera del 15/12/2026, punto 4 (verbale n. 42): «Portone: € 14.500,00 con Serramenti Marino».');
        expect(t).toContain('La gestione straordinaria');
        expect(t).toContain('La voce di spesa');
        expect(t).toContain('Dopo');
        expect(t).toContain('Le rate le guardi tu: il piano nasce quando lo salvi, e allora «Crea il piano straordinario» si chiude da sola.');
        expect(q<HTMLInputElement>('[data-testid="nome-gestione"]')!.value).toBe('Portone 2026');
        expect(q<HTMLInputElement>('[data-testid="importo"]')!.value).toBe('14.500,00');
        expect(q('[data-testid="riepilogo"]')!.textContent).toContain('Nascono la gestione, il suo piano dei conti e 1 voce da € 14.500,00.');
        expect(q('[data-testid="crea-il-piano-straordinario"]')!.className).toContain('top-[5vh]');
    });

    test('l\'esercizio nella barra cambia il periodo e l\'anno del nome; l\'importo cambiato dice la differenza dalla delibera', async () => {
        await monta();
        const anni = document.body.querySelectorAll<HTMLButtonElement>('[data-testid="esercizio"] button');
        anni[1].click();
        await flushPromises();
        expect(q<HTMLInputElement>('[data-testid="nome-gestione"]')!.value).toBe('Portone 2027');
        await scrivi('importo', '9.000,00');
        expect(q('[data-testid="riepilogo"]')!.textContent).toContain('1 voce da € 9.000,00. € 5.500,00 in meno della delibera (€ 14.500,00).');
    });

    test('«Crea e apri il piano» manda i campi come sono scritti e apre il nuovo piano; gli errori del server stanno sotto il campo', async () => {
        await monta();
        await scrivi('nome-voce', 'Sostituzione del portone');
        axiosPost.mockRejectedValueOnce({ response: { status: 422, data: { errors: { importo: ['L\'importo si scrive così: 14.500,00.'] } } } });
        q<HTMLButtonElement>('[data-testid="crea-e-apri"]')!.click();
        await flushPromises();
        expect(testo()).toContain('L\'importo si scrive così: 14.500,00.');
        expect(visita).not.toHaveBeenCalled();

        axiosPost.mockResolvedValueOnce({ data: { url: '/admin/gestionale/3/esercizi/1/piani-rate/create?gestione_id=11', creata: true } });
        q<HTMLButtonElement>('[data-testid="crea-e-apri"]')!.click();
        await flushPromises();
        expect(axiosPost).toHaveBeenLastCalledWith('/gestionale.assemblee.seguito.piano-straordinario', { gestione_nome: 'Portone 2026', esercizio_id: 1,
            data_inizio: '2026-01-01', data_fine: '2026-12-31', voce_nome: 'Sostituzione del portone', importo: '14.500,00', tabella_id: 5, fornitore_id: null });
        expect(visita).toHaveBeenCalledWith('/admin/gestionale/3/esercizi/1/piani-rate/create?gestione_id=11');
        expect(w!.emitted('update:open')!.at(-1)).toEqual([false]);
    });

    test('con la gestione già legata alla delibera: niente nasce di nuovo, «Apri il piano»', async () => {
        await monta({ ...proposta, gestione_esistente: { id: 11, nome: 'Portone d\'ingresso 2027', url: '/piano-della-gestione' } });
        expect(testo()).toContain('La gestione «Portone d\'ingresso 2027» è già legata a questa delibera');
        expect(q('[data-testid="nome-gestione"]')).toBeNull();
        const apri = q<HTMLButtonElement>('[data-testid="crea-e-apri"]')!;
        expect(apri.textContent!.trim()).toBe('Apri il piano');
        apri.click();
        await flushPromises();
        expect(axiosPost).not.toHaveBeenCalled();
        expect(visita).toHaveBeenCalledWith('/piano-della-gestione');
    });

    test('approvata con modifiche: in testa che cosa ha cambiato l\'assemblea, l\'importo da scrivere, nessuna differenza dalla cifra di prima', async () => {
        await monta({ ...proposta, cosa: { ...proposta.cosa, testo: 'Crea il piano straordinario' }, modifiche: 'Importo ridotto a € 13.000,00',
            delibera: { ...proposta.delibera, importo: null }, voce: { ...proposta.voce, importo: null },
            a_carico: 'A carico del proprietario, come ogni spesa straordinaria.' });
        const corpo = q('[data-testid="corpo-finestra"]')!;
        expect(corpo.firstElementChild!.getAttribute('data-testid')).toBe('modifiche-dell-assemblea');
        expect(q('[data-testid="modifiche-dell-assemblea"]')!.textContent!.replace(/\s+/g, ' ')).toContain('Che cosa ha cambiato l\'assemblea: Importo ridotto a € 13.000,00');
        expect(q<HTMLInputElement>('[data-testid="importo"]')!.value).toBe('');
        expect(testo()).not.toContain('Importo dalla delibera');
        expect(q('[data-testid="riepilogo"]')!.textContent).toContain('Scrivi l\'importo della voce.');
        expect(q<HTMLButtonElement>('[data-testid="crea-e-apri"]')!.disabled).toBe(true);
        await scrivi('importo', '13.000,00');
        expect(q('[data-testid="riepilogo"]')!.textContent!.trim()).toBe('Nascono la gestione, il suo piano dei conti e 1 voce da € 13.000,00.');
        expect(q<HTMLButtonElement>('[data-testid="crea-e-apri"]')!.disabled).toBe(false);
    });

    test('«Crea e apri il piano» premuto due volte manda una richiesta sola', async () => {
        await monta();
        let rispondi: (v: unknown) => void = () => {};
        axiosPost.mockReturnValueOnce(new Promise((r) => { rispondi = r; }));
        const crea = q<HTMLButtonElement>('[data-testid="crea-e-apri"]')!;
        crea.click();
        crea.click();
        await flushPromises();
        expect(axiosPost).toHaveBeenCalledTimes(1);
        rispondi({ data: { url: '/piano', creata: true } });
        await flushPromises();
    });
});
