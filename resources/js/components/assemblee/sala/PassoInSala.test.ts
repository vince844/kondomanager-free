// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il passo 4 «In sala» nella pagina dell'assemblea (scena 1 della bozza delle scene della tappa 3; decisione 589, T2): prima del giorno «Deleghe ricevute»
 * (chi delega chi, quando, la scansione, lo stato con il motivo), il conto del quinto per ogni delegato, il posto vuoto «Dal portale dei condòmini» spiegato,
 * «Il foglio della sala» da stampare; «Segna una delega ricevuta» con il quinto che si conta mentre la segni. Dal giorno «Apri la sala», «Segna deserta la
 * prima convocazione» e «Registra dal foglio della sala»; sospesa «Sospesa · prosegue …»; chiusa «Le presenze e i voti» e la sala in sola lettura.
 *
 * Che cosa non copre: le stampe stesse (le loro prove); la regola del passo, che scrive il server (`PassoInSalaTest`).
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import type { SalaDellAssemblea } from '@/types/assemblee';
import { delega, salaAperta, salaDaAprire } from './salaDiProva';

const inertia = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn(), rotte: new Set<string>() }));
vi.mock('@inertiajs/vue3', () => ({
    router: inertia,
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('vue-select', async () => {
    const { defineComponent, h } = await import('vue');
    return {
        default: defineComponent({
            name: 'VSelect', props: ['modelValue', 'options', 'reduce', 'label', 'multiple'], emits: ['update:modelValue'],
            setup(p, { emit, attrs }) {
                return () => h('div', { class: 'v-select-finto', 'data-testid': attrs['data-testid'] }, (p.options ?? []).map((o: unknown, i: number) => h('button', {
                    type: 'button', 'data-opzione': i, onClick: () => {
                        const v = p.reduce ? p.reduce(o) : o;
                        emit('update:modelValue', p.multiple !== undefined && p.multiple !== false ? [...((p.modelValue as unknown[]) ?? []), v] : v);
                    },
                }, String((o as Record<string, unknown>)[p.label ?? 'label']))));
            },
        }),
    };
});
(globalThis as Record<string, unknown>).route = (nome: string, p?: Record<string, unknown>) => {
    if (nome.includes('sala.foglio') || nome.includes('sala.prospetto')) {
        if (!inertia.rotte.has(nome)) throw new Error(`Route ${nome} not found`);
    }
    return `/${nome}/${p?.assemblea ?? ''}`;
};
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Passo = (await import('./PassoInSala.vue')).default;
let w: VueWrapper | null = null;
beforeEach(() => { inertia.post.mockClear(); inertia.rotte = new Set(['admin.gestionale.assemblee.sala.foglio', 'admin.gestionale.assemblee.sala.prospetto']); });
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

/** Giovedì 10/12: la convocazione spedita, quattro deleghe valide e una rifiutata, la sala che si apre lunedì 14/12. */
function primaDelGiorno(piu: Partial<SalaDellAssemblea> = {}): SalaDellAssemblea {
    const s = salaDaAprire();
    return {
        ...s,
        sedute: s.sedute.map((x) => ({ ...x, esito: null })),
        da_aprire: { seduta_id: 1, convocazione: 'prima', numero: 1, si_apre: false, perche: 'La sala si apre il giorno della seduta, il 14/12/2026.' },
        controllo: null,
        deleghe: [delega(1, 3, 2, 'Bianchi Giorgio'), delega(2, 4, 2, 'Bianchi Giorgio', { ricevuta_il: '2026-12-04', documento_id: null }),
            delega(3, 5, 2, 'Bianchi Giorgio', { ricevuta_il: '2026-12-09', documento_id: 31, scansione: '/deleghe/3/scansione' }), delega(4, 30, null, 'Ricci Stefano', { quinto: '1 condòmino su 5 · 32,07 su 200,00' }),
            delega(5, 1, 2, 'Bianchi Giorgio', { stato: 'rifiutata', ricevuta_il: '2026-12-10', motivo: 'Con questa delega Bianchi Giorgio rappresenterebbe 4 condòmini su 5 · 224,00 su 200,00: oltre un quinto del valore (art. 67 c. 1 disp. att.).' })],
        ...piu,
    };
}
async function monta(sala: SalaDellAssemblea, apri = { attiva: false, perche: 'La sala si apre il giorno della prima convocazione, il 14/12/2026.' } as Record<string, unknown>) {
    w = mount(Passo as never, { attachTo: document.body, props: { sala, apriLaSala: apri, puoGestire: true, condominioId: 3, assembleaId: 9 } as never });
    await flushPromises();

    return w;
}
const testo = (sel: string) => (document.body.querySelector(sel)?.textContent ?? '').replace(/\s+/g, ' ').trim();
const scegli = async (testid: string, etichetta: string) => {
    const voce = [...document.body.querySelectorAll(`[data-testid="${testid}"] [data-opzione]`)].find((b) => (b.textContent ?? '').trim().startsWith(etichetta));
    if (!voce) throw new Error(`Non trovo «${etichetta}» in ${testid}`);
    (voce as HTMLElement).click();
    await flushPromises();
};

describe('il passo «In sala» prima del giorno', () => {
    test('le deleghe ricevute con lo stato, il conto del quinto, il posto del portale, il foglio della sala', async () => {
        await monta(primaDelGiorno());
        // Il riquadro in testa non ripete la card: dice l'ora della prima convocazione, come l'ha scritta l'amministratore, con due cifre.
        expect(testo('[data-testid="riquadro-della-sala"]')).toBe('La prima convocazione è lunedì 14/12/2026 alle 08:00: quel giorno qui si apre la sala');
        expect(testo('[data-testid="passo-in-sala"]')).not.toContain('Prima del giorno:');
        expect(document.body.querySelector('#deleghe-ricevute')).not.toBeNull();
        // L'elenco delle deleghe è il componente che usa anche il cassetto: le due viste non possono dire cose diverse.
        expect(document.body.querySelector('#deleghe-ricevute [data-testid="elenco-deleghe"][data-forma="sezione"]')).not.toBeNull();
        expect(document.body.querySelectorAll('[data-testid="riga-delega"]')).toHaveLength(5);
        expect(testo('[data-delega="5"]')).toContain('Rifiutata');
        expect(testo('[data-delega="5"]')).toContain('oltre un quinto del valore');
        expect(testo('[data-delega="4"]')).toContain('non è un condòmino');
        expect(testo('[data-delega="3"] [data-testid="scansione"]')).toBe('Apri');
        const apri = document.body.querySelector('[data-delega="3"] [data-testid="scansione"] a')!;
        expect([apri.getAttribute('href'), apri.getAttribute('target'), apri.getAttribute('rel')]).toEqual(['/deleghe/3/scansione', '_blank', 'noopener']);
        expect(testo('[data-delega="1"] [data-testid="scansione"]')).toBe('—');
        // «Revoca» sta nella sua colonna, a destra, come un pulsante piccolo: non attaccato all'etichetta dello stato.
        const revoca = document.body.querySelector('[data-delega="1"] [data-testid="azione-delega"] button')!;
        expect(revoca.textContent!.trim()).toBe('Revoca');
        expect(revoca.className).toContain('border');
        expect(document.body.querySelector('[data-delega="1"] [data-testid="stato-delega"] button')).toBeNull();
        expect(testo('[data-testid="conto-dei-delegati"]')).toContain('Bianchi Giorgio');
        expect(testo('[data-testid="conto-dei-delegati"]')).toContain('3 condòmini su 5 · 125,70 su 200,00');
        expect(testo('[data-testid="conto-dei-delegati"]')).toContain('L\'amministratore non può ricevere deleghe (art. 67 c. 5 disp. att.)');
        expect(testo('[data-testid="dal-portale"]')).toContain('Nessuna');
        expect(document.body.querySelector('#foglio-della-sala')).not.toBeNull();
        expect(document.body.querySelector('[data-testid="stampa-foglio"]')?.getAttribute('href')).toBe('/sala/foglio');
        expect(testo('[data-testid="foglio-stampato"]')).toBe('Non ancora stampato');
        expect(document.body.querySelector('[data-testid="apri-la-sala"]')).toBeNull();
    });

    test('il foglio stampato dice quando; se una delega è cambiata dopo la stampa, «ristampa il foglio»', async () => {
        await monta(primaDelGiorno({ foglio: { stampato_il: '2026-12-13T16:00:00Z', da_ristampare: false } }));
        expect(testo('[data-testid="foglio-stampato"]')).toBe('Stampato il 13/12/2026 alle 17:00');
        expect(document.body.querySelector('[data-testid="ristampa-il-foglio"]')).toBeNull();
        w?.unmount();
        await monta(primaDelGiorno({ foglio: { stampato_il: '2026-12-13T16:00:00Z', da_ristampare: true } }));
        expect(testo('[data-testid="ristampa-il-foglio"]')).toBe('Una delega è cambiata dopo la stampa: ristampa il foglio.');
        expect(testo('[data-testid="stampa-foglio"]')).toBe('Ristampa il foglio');
    });

    test('senza la rotta della stampa il pulsante è spento e dice perché', async () => {
        const s = primaDelGiorno();
        await monta({ ...s, rotte: { ...s.rotte, foglio: undefined } });
        const b = document.body.querySelector('[data-testid="stampa-foglio"]') as HTMLButtonElement;
        expect(b.tagName).toBe('BUTTON');
        expect(b.disabled).toBe(true);
    });

    // Vincenzo, 21/11/2026 sera («come possiamo sistemare questo layout?»): il pulsante scuro andava a capo sotto la frase. La testa: il titolo con il conto
    // in un badge, la frase sotto, a destra sulla stessa riga un pulsante piccolo e chiaro; al telefono sotto, a tutta riga.
    test('la testa delle deleghe ricevute: il conto nel badge accanto al titolo, il pulsante piccolo e chiaro sulla stessa riga', async () => {
        await monta(primaDelGiorno({ deleghe: primaDelGiorno().deleghe.slice(0, 1) }));
        const testa = document.body.querySelector('[data-testid="testa-deleghe"]') as HTMLElement;
        expect(testa).not.toBeNull();
        expect(testo('[data-testid="testa-deleghe"] h3')).toContain('Deleghe ricevute');
        expect(testa.querySelector('h3 [data-testid="riepilogo-deleghe"]')?.textContent?.trim()).toMatch(/valid/);
        const b = testa.querySelector('[data-testid="segna-delega-ricevuta"]') as HTMLElement;
        expect(b.textContent?.replace(/\s+/g, ' ').trim()).toBe('Segna una delega');
        expect(b.className).toContain('h-7');
        expect(b.className.split(' ')).not.toContain('bg-slate-900');
        expect(testa.className).toContain('sm:flex-nowrap');
    });

    test('«Segna una delega ricevuta»: il quinto mentre la segni; oltre, con l\'impostazione di serie, si registra rifiutata', async () => {
        await monta(primaDelGiorno({ deleghe: primaDelGiorno().deleghe.slice(0, 4) }));
        (document.body.querySelector('[data-testid="segna-delega-ricevuta"]') as HTMLElement).click();
        await flushPromises();
        await scegli('chi-delega', 'Rossi Mario');
        await scegli('a-chi', 'Bianchi Giorgio');
        expect(testo('[data-testid="quinto-ricevuta"]')).toContain('4 condòmini su 5 · 224,00 su 200,00');
        expect(testo('[data-testid="oltre-ricevuta"]')).toContain('Non si accetta.');
        expect(testo('[data-testid="oltre-ricevuta"]')).toContain('Puoi dirlo a Rossi Mario: può delegare un\'altra persona, o venire.');
        const registra = document.body.querySelector('[data-testid="registra-ricevuta"]') as HTMLButtonElement;
        expect(registra.textContent).toContain('Registra rifiutata');
        registra.click();
        await flushPromises();
        expect(inertia.post).toHaveBeenCalledTimes(1);
        const [url, corpo, opzioni] = inertia.post.mock.calls[0];
        expect(url).toBe('/deleghe');
        expect(corpo).toMatchObject({ delegante_anagrafica_id: 1, delegato: { anagrafica_id: 2 } });
        expect(corpo.ricevuta_il).toMatch(/^\d{4}-\d{2}-\d{2}$/);
        expect(opzioni).toMatchObject({ forceFormData: true, only: ['sala', 'passi', 'flash'] });
    });

    test('la delega a chi non è un condòmino, per i soli punti del conduttore', async () => {
        await monta(primaDelGiorno());
        (document.body.querySelector('[data-testid="segna-delega-ricevuta"]') as HTMLElement).click();
        await flushPromises();
        await scegli('chi-delega', 'Colombo Rita');
        await scegli('a-chi', 'Un\'altra persona');
        const nome = document.body.querySelector('[data-testid="nome-ricevuta"]') as HTMLInputElement;
        nome.value = 'Verdi Paolo';
        nome.dispatchEvent(new Event('input'));
        await flushPromises();
        await scegli('punti-ricevuta', '4.');
        (document.body.querySelector('[data-testid="registra-ricevuta"]') as HTMLElement).click();
        await flushPromises();
        expect(inertia.post.mock.calls[0][1]).toMatchObject({ delegante_anagrafica_id: 10, delegato: { nome: 'Verdi Paolo' }, punti: [44] });
    });
});

describe('il passo «In sala» dal giorno, durante e dopo', () => {
    test('dal giorno: «Apri la sala», «Segna deserta la prima convocazione», «Registra dal foglio della sala»', async () => {
        await monta(primaDelGiorno({ da_aprire: { seduta_id: 1, convocazione: 'prima', numero: 1, si_apre: true, perche: null } }),
            { attiva: true, perche: 'Oggi è il giorno della prima convocazione.', url: '/sala', testo: 'Apri la sala' });
        expect(document.body.querySelector('[data-testid="apri-la-sala"]')?.getAttribute('href')).toBe('/sala');
        expect(document.body.querySelector('[data-testid="segna-deserta"]')?.getAttribute('href')).toBe('/sala?deserta=1');
        expect(document.body.querySelector('[data-testid="registra-dal-foglio"]')?.getAttribute('href')).toBe('/sala?foglio=1');
    });

    test('sospesa: «Sospesa · prosegue …», e «Riapri la sala» si accende quel giorno', async () => {
        const s = salaAperta();
        await monta({ ...s, seduta_id: null, sedute: [...s.sedute.map((x) => (x.id === 2 ? { ...x, esito: 'sospesa' as const, chiusa_il: '2026-12-15T20:30:00Z' } : x)),
            { id: 3, convocazione: 'seconda', numero: 2, giorno: '2026-12-17', ora: '18:30', aperta_il: null, chiusa_il: null, esito: null, presidente: null, segretario: null,
                dal_foglio: false }], da_aprire: { seduta_id: 3, convocazione: 'seconda', numero: 2, si_apre: false, perche: 'La sala si apre il giorno della seduta, il 17/12/2026.' } },
        { attiva: true, perche: 'L\'assemblea è in corso.', url: '/sala', testo: 'Torna in sala' });
        expect(testo('[data-testid="passo-in-sala"]')).toContain('Sospesa · prosegue giovedì 17/12/2026 alle 18:30');
        expect(testo('[data-testid="passo-in-sala"]')).toContain('«Riapri la sala» si accende giovedì 17/12/2026.');
    });

    test('chiusa: «Le presenze e i voti» e la sala in sola lettura', async () => {
        const s = salaAperta();
        await monta({ ...s, assemblea: { ...s.assemblea, stato: 'chiusa', stato_parola: 'Chiusa' }, seduta_id: null,
            sedute: s.sedute.map((x) => (x.id === 2 ? { ...x, esito: 'tenuta' as const, chiusa_il: '2026-12-15T19:40:00Z' } : x)) },
        { attiva: true, perche: 'L\'assemblea è chiusa.', url: '/sala', testo: 'La sala' });
        expect(testo('[data-testid="passo-in-sala"]')).toContain('Chiusa alle 20:40');
        expect(document.body.querySelector('[data-testid="presenze-e-voti"]')?.getAttribute('href')).toBe('/sala/prospetto');
        expect(testo('[data-testid="apri-la-sala"]')).toBe('Torna in sala, in sola lettura');
        expect(document.body.querySelector('[data-testid="segna-delega-ricevuta"]')).toBeNull();
    });
});
