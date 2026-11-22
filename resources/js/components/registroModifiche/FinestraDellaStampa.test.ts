// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Stampa» del registro delle modifiche: il pulsante accanto al menu «Registro» e la finestra «Stampa il registro delle modifiche» (la bozza approvata
 * del 21/11/2026, nella forma delle finestre delle ricevute).
 *
 * - «Stampa» è un pulsante accanto a «Registro», non più una voce del menu; il menu tiene il resto.
 * - La finestra parte con i filtri della pagina già scritti: il mese della pagina diventa il periodo dal … al … («Questo mese» se è il mese di oggi).
 *   Cambiarli qui non cambia la pagina (nessuna visita).
 * - Le scorciatoie del periodo in una barra: «Questo mese», «L'esercizio 2026» (solo nel registro del condominio), «Dall'inizio», «Scegli le date»; i due
 *   campi del giorno; cambiando un giorno a mano la scorciatoia è «Scegli le date».
 * - A ogni cambio il conto chiesto al server (gli stessi parametri della stampa) e il piede: «Stamperai 134 righe, dal 01/11/2026 al 30/11/2026, sulle
 *   assemblee.»; mentre conta, il segno piccolo nel piede e «Stampa il PDF» spento.
 * - Oltre il limite di una stampa sola la riga arancio con l'azione («Scegli l'esercizio 2026»), e «Stampa il PDF» spento.
 * - «Al» prima di «Dal»: l'errore rosso sotto «Al», nessun conto, «Stampa il PDF» spento; un errore del server (422) sotto il suo campo.
 * - «Stampa il PDF» apre il PDF in una scheda nuova, con i filtri e il periodo nell'indirizzo.
 * - Nel registro dello studio niente «L'esercizio», e il condominio fra i filtri.
 *
 * Che cosa non copre: il conto e il PDF del server (`tests/Feature/RegistroModifiche/StampaDelRegistroTest.php`); come il browser disegna la finestra (si
 * guarda a video, anche a 375 pixel e nel tema scuro).
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const routerGet = vi.fn();
const axiosGet = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: vi.fn() } }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { get: (...a: unknown[]) => routerGet(...a), reload: vi.fn(), visit: vi.fn() },
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
// Oggi, a Roma, è il 21/11/2026: il giorno della bozza. Solo il giorno di oggi è finto, non l'orologio (con l'orologio finto i menu non si aprono).
vi.mock('@internationalized/date', async (importOriginal) => {
    const originale = await importOriginal<typeof import('@internationalized/date')>();

    return { ...originale, today: () => originale.parseDate('2026-11-21') };
});
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Pulsanti = (await import('./PulsantiDelRegistro.vue')).default;
const CampoGiorno = (await import('@/components/CampoGiorno.vue')).default;

const contenitore = { template: '<div><slot /></div>' };
const voce = { emits: ['select'], template: '<div role="menuitem" @click="$emit(\'select\', $event)"><slot /></div>' };
const MENU = { DropdownMenu: contenitore, DropdownMenuTrigger: contenitore, DropdownMenuContent: contenitore, DropdownMenuItem: voce };

const scelta = (valore: string, nome: string) => ({ valore, nome });
const stampa = (extra: Record<string, unknown> = {}) => ({
    indirizzo: '/stampa',
    conto: '/conto',
    filtri: { chi: null, area: 'assemblee', azione: null, mese: '2026-11', cerca: null, condominio: null },
    scelte: {
        chi: [scelta('', 'Tutti'), scelta('2', 'Marco Galli'), scelta('programma', 'Il programma')],
        aree: [scelta('', 'Tutto'), scelta('comunicazioni', 'Comunicazioni'), scelta('assemblee', 'Assemblee')],
        azioni: [scelta('', 'Tutto'), scelta('gesto', 'Un\'operazione')],
        mesi: [scelta('2026-11', 'Novembre 2026'), scelta('2026-10', 'Ottobre 2026'), scelta('2024-03', 'Marzo 2024'), scelta('tutti', 'Tutti i mesi')],
        condomini: [],
    },
    esercizio: { dal: '2026-01-01', al: '2026-12-31' },
    esercizi: [
        { id: 7, nome: 'Esercizio 2026', dal: '2026-01-01', al: '2026-12-31', stato: 'aperto' },
        { id: 5, nome: 'Esercizio 2025', dal: '2025-01-01', al: '2025-12-31', stato: 'chiuso' },
    ],
    delloStudio: false,
    luogo: 'Condominio Demo Foto',
    ...extra,
});

let w: VueWrapper | null = null;
beforeEach(() => {
    axiosGet.mockResolvedValue({ data: { righe: 134, limite: 5000 } });
});
afterEach(() => {
    w?.unmount();
    w = null;
    routerGet.mockReset();
    axiosGet.mockReset();
    document.body.innerHTML = '';
});

const corpo = () => document.body;
const qs = (testid: string) => corpo().querySelector(`[data-testid="${testid}"]`) as HTMLElement | null;
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
const monta = async (extra: Record<string, unknown> = {}) => {
    w = mount(Pulsanti, { attachTo: document.body, props: { stampa: stampa(extra) } as never, global: { stubs: MENU } });
    await flushPromises();
};
const apri = async (extra: Record<string, unknown> = {}) => {
    await monta(extra);
    qs('stampa-registro')!.click();
    await flushPromises();
};
const menu = (testid: string) => corpo().querySelector(`[data-testid="${testid}"] .v-select`) as HTMLElement;
const nelMenu = async (testid: string, testo: string) => {
    menu(testid).querySelector('.vs__search')!.dispatchEvent(new FocusEvent('focus'));
    await flushPromises();
    ([...menu(testid).querySelectorAll('.vs__dropdown-option')].find((o) => pulito(o.textContent) === testo) as HTMLElement).click();
    await flushPromises();
};
const scorciatoiaAccesa = () => pulito(corpo().querySelector('[data-testid="scorciatoie-periodo"] [data-acceso="si"]')?.textContent);
const scegliScorciatoia = async (nome: string) => {
    ([...corpo().querySelectorAll('[data-testid="scorciatoie-periodo"] [role="radio"]')].find((b) => pulito(b.textContent) === nome) as HTMLElement).click();
    await flushPromises();
};
const giorni = () => [pulito(qs('stampa-dal')?.textContent), pulito(qs('stampa-al')?.textContent)];
const riepilogo = () => pulito(qs('riepilogo-stampa')?.textContent);
const ultimoConto = () => axiosGet.mock.calls.at(-1);
/** Il campo del giorno «Dal» o «Al» della finestra: lo cambia come il calendario. */
const cambiaGiorno = async (quale: 0 | 1, giorno: string) => {
    w!.findAllComponents(CampoGiorno)[quale].vm.$emit('update:modelValue', giorno);
    await flushPromises();
};

describe('«Stampa» accanto al menu «Registro»', () => {
    test('«Stampa» è un pulsante, prima del menu; nel menu non c\'è più, e il menu tiene il resto', async () => {
        await monta();
        const pulsante = qs('stampa-registro')!;
        expect(pulsante.tagName).toBe('BUTTON');
        expect(pulito(pulsante.textContent)).toBe('Stampa');
        expect(pulsante.compareDocumentPosition(qs('menu-registro')!) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect([...corpo().querySelectorAll('[role="menuitem"]')].map((v) => pulito(v.textContent))).toEqual(['Verifica l\'integrità']);
        expect(qs('finestra-stampa')).toBeNull();
        expect(axiosGet).not.toHaveBeenCalled();
    });
});

describe('la finestra «Stampa il registro delle modifiche»', () => {
    test('si apre con i filtri della pagina e il periodo del mese, chiede il conto e dice nel piede quante righe usciranno', async () => {
        await apri();
        const finestra = qs('finestra-stampa')!;
        expect(pulito(finestra.querySelector('h2')?.textContent)).toBe('Stampa il registro delle modifiche');
        expect(pulito(finestra.textContent)).toContain('Condominio Demo Foto · il PDF con la carta dello studio e l\'impronta in fondo');
        // La forma delle finestre: ancorata in alto, i gruppi con il titolo piccolo e grigio, il riepilogo nel piede accanto ai pulsanti.
        expect(finestra.className).toContain('top-[5vh]');
        expect(finestra.className).toContain('translate-y-0');
        expect([...finestra.querySelectorAll('[data-testid="titolo-gruppo"]')].map((t) => pulito(t.textContent))).toEqual(['Il periodo', 'Che cosa']);
        expect(qs('piede-finestra')!.contains(qs('riepilogo-stampa'))).toBe(true);
        expect(qs('riepilogo-stampa')!.getAttribute('role')).toBe('status');

        expect(scorciatoiaAccesa()).toBe('Questo mese');
        expect(giorni()).toEqual(['01/11/2026', '30/11/2026']);
        expect(pulito(menu('stampa-area').querySelector('.vs__selected')?.textContent)).toBe('Assemblee');
        expect(pulito(menu('stampa-chi').querySelector('.vs__selected')?.textContent)).toBe('Tutti');
        expect(corpo().querySelector('.vs--open')).toBeNull();
        expect(finestra.querySelector('select')).toBeNull();

        expect(ultimoConto()).toEqual(['/conto', { params: { area: 'assemblee', dal: '2026-11-01', al: '2026-11-30' } }]);
        expect(riepilogo()).toBe('Stamperai 134 righe, dal 01/11/2026 al 30/11/2026, sulle assemblee.');
        const pdf = qs('stampa-il-pdf') as HTMLAnchorElement;
        expect(pdf.tagName).toBe('A');
        expect(pdf.getAttribute('href')).toBe('/stampa?area=assemblee&dal=2026-11-01&al=2026-11-30');
        expect(pdf.getAttribute('target')).toBe('_blank');
        expect(pulito(pdf.textContent)).toBe('Stampa il PDF');
        expect(routerGet).not.toHaveBeenCalled();
    });

    test('«L\'esercizio» è un menu con gli esercizi dal più recente, col nome e lo stato; scelto uno, «Dal» e «Al» prendono le sue date', async () => {
        await apri();
        expect([...corpo().querySelectorAll('[data-testid="scorciatoie-periodo"] [role="radio"]')].map((b) => pulito(b.textContent)))
            .toEqual(['Questo mese', 'Dall\'inizio', 'Scegli le date']);
        const menuEsercizio = qs('stampa-esercizio')!;
        expect(pulito(menuEsercizio.querySelector('label, span')?.textContent)).toBe('L\'esercizio');
        menu('stampa-esercizio').querySelector('.vs__search')!.dispatchEvent(new FocusEvent('focus'));
        await flushPromises();
        expect([...menu('stampa-esercizio').querySelectorAll('.vs__dropdown-option')].map((o) => pulito(o.textContent)))
            .toEqual(['Esercizio 2026 · aperto', 'Esercizio 2025 · chiuso']);
        await nelMenu('stampa-esercizio', 'Esercizio 2025 · chiuso');
        expect(giorni()).toEqual(['01/01/2025', '31/12/2025']);
        expect(ultimoConto()).toEqual(['/conto', { params: { area: 'assemblee', dal: '2025-01-01', al: '2025-12-31' } }]);
        expect(pulito(menu('stampa-esercizio').querySelector('.vs__selected')?.textContent)).toBe('Esercizio 2025 · chiuso');
        // Un giorno cambiato a mano: il menu si svuota.
        await cambiaGiorno(1, '2025-06-30');
        expect(scorciatoiaAccesa()).toBe('Scegli le date');
        expect(menu('stampa-esercizio').querySelector('.vs__selected')).toBeNull();
    });

    test('«Dall\'inizio» cambia le date e il conto; oltre il limite la riga arancio con l\'azione (l\'esercizio di oggi), e «Stampa il PDF» spento', async () => {
        await apri();

        axiosGet.mockResolvedValue({ data: { righe: 1, limite: 5000 } });
        await nelMenu('stampa-esercizio', 'Esercizio 2026 · aperto');
        expect(giorni()).toEqual(['01/01/2026', '31/12/2026']);
        expect(ultimoConto()).toEqual(['/conto', { params: { area: 'assemblee', dal: '2026-01-01', al: '2026-12-31' } }]);

        axiosGet.mockResolvedValue({ data: { righe: 6240, limite: 5000 } });
        await scegliScorciatoia('Dall\'inizio');
        expect(giorni()).toEqual(['01/03/2024', '30/11/2026']);
        expect(pulito(qs('oltre-il-limite')?.textContent)).toContain('Con «Dall\'inizio» le righe sarebbero 6.240: troppe per una stampa sola (al più 5.000). Scegli un periodo più breve, o stampa un esercizio alla volta.');
        expect(qs('oltre-il-limite')!.className).toContain('bg-amber-50');
        expect(riepilogo()).toBe('6.240 righe, dal 01/03/2024 al 30/11/2026: troppe per una stampa sola.');
        expect(qs('stampa-il-pdf')!.tagName).toBe('BUTTON');
        expect((qs('stampa-il-pdf') as HTMLButtonElement).disabled).toBe(true);

        // L'azione giusta: un esercizio alla volta.
        axiosGet.mockResolvedValue({ data: { righe: 1210, limite: 5000 } });
        (qs('oltre-il-limite')!.querySelector('button') as HTMLButtonElement).click();
        await flushPromises();
        expect(pulito(menu('stampa-esercizio').querySelector('.vs__selected')?.textContent)).toBe('Esercizio 2026 · aperto');
        expect(giorni()).toEqual(['01/01/2026', '31/12/2026']);
        expect(qs('oltre-il-limite')).toBeNull();
        expect(riepilogo()).toBe('Stamperai 1.210 righe, dal 01/01/2026 al 31/12/2026, sulle assemblee.');
        expect(qs('stampa-il-pdf')!.tagName).toBe('A');
    });

    test('cambiare i filtri nella finestra cambia il conto e la stampa, non la pagina; il testo da cercare dopo una breve pausa', async () => {
        await apri();
        await nelMenu('stampa-chi', 'Il programma');
        expect(ultimoConto()).toEqual(['/conto', { params: { chi: 'programma', area: 'assemblee', dal: '2026-11-01', al: '2026-11-30' } }]);
        await nelMenu('stampa-area', 'Tutto');
        await nelMenu('stampa-azione', 'Un\'operazione');
        expect(ultimoConto()).toEqual(['/conto', { params: { chi: 'programma', azione: 'gesto', dal: '2026-11-01', al: '2026-11-30' } }]);
        expect(riepilogo()).toBe('Stamperai 134 righe, dal 01/11/2026 al 30/11/2026, del programma.');

        const cerca = qs('stampa-cerca') as HTMLInputElement;
        expect(cerca.getAttribute('placeholder')).toBe('per esempio «delega»');
        cerca.value = 'delega';
        cerca.dispatchEvent(new Event('input'));
        await new Promise((r) => setTimeout(r, 450));
        await flushPromises();
        expect(ultimoConto()![1].params.cerca).toBe('delega');
        expect(qs('stampa-il-pdf')!.getAttribute('href')).toBe('/stampa?chi=programma&azione=gesto&cerca=delega&dal=2026-11-01&al=2026-11-30');
        expect(routerGet).not.toHaveBeenCalled();
    });

    test('«Al» prima di «Dal»: l\'errore rosso sotto «Al», nessun conto, «Stampa il PDF» spento; un giorno cambiato a mano è «Scegli le date»', async () => {
        await apri();
        const chieste = axiosGet.mock.calls.length;
        await cambiaGiorno(1, '2026-10-15');
        expect(scorciatoiaAccesa()).toBe('Scegli le date');
        const errore = qs('errore-al')!;
        expect(pulito(errore.textContent)).toBe('«Al» viene prima di «Dal»: scegli un giorno uguale o successivo.');
        expect(errore.className).toContain('text-red-600');
        expect(errore.previousElementSibling?.contains(qs('stampa-al'))).toBe(true);
        expect(axiosGet.mock.calls.length).toBe(chieste);
        expect((qs('stampa-il-pdf') as HTMLButtonElement).disabled).toBe(true);

        await cambiaGiorno(1, '2026-11-15');
        expect(qs('errore-al')).toBeNull();
        expect(ultimoConto()).toEqual(['/conto', { params: { area: 'assemblee', dal: '2026-11-01', al: '2026-11-15' } }]);
        expect(riepilogo()).toBe('Stamperai 134 righe, dal 01/11/2026 al 15/11/2026, sulle assemblee.');
    });

    test('mentre conta, il segno piccolo nel piede e «Stampa il PDF» spento; un errore del server sotto il suo campo', async () => {
        let rispondi: (v: unknown) => void = () => {};
        axiosGet.mockImplementation(() => new Promise((r) => { rispondi = r; }));
        await apri();
        expect(qs('aggiorno')).not.toBeNull();
        expect((qs('stampa-il-pdf') as HTMLButtonElement).disabled).toBe(true);
        rispondi({ data: { righe: 3, limite: 5000 } });
        await flushPromises();
        expect(qs('aggiorno')).toBeNull();
        expect(qs('stampa-il-pdf')!.tagName).toBe('A');

        axiosGet.mockRejectedValue({ response: { status: 422, data: { message: 'x', errors: { dal: ['Il giorno non è valido.'] } } } });
        await cambiaGiorno(0, '2026-11-02');
        expect(pulito(qs('errore-dal')?.textContent)).toBe('Il giorno non è valido.');
        expect((qs('stampa-il-pdf') as HTMLButtonElement).disabled).toBe(true);
    });

    test('con 0 righe «Stampa il PDF» è spento e la frase lo dice sotto il conto', async () => {
        axiosGet.mockResolvedValue({ data: { righe: 0, limite: 5000 } });
        await apri();
        expect((qs('stampa-il-pdf') as HTMLButtonElement).disabled).toBe(true);
        expect(qs('stampa-il-pdf')!.tagName).toBe('BUTTON');
        expect(pulito(qs('nessuna-riga')?.textContent)).toBe('Nessuna riga con questi filtri: non c\'è niente da stampare.');
        axiosGet.mockResolvedValue({ data: { righe: 3, limite: 5000 } });
        await nelMenu('stampa-area', 'Tutto');
        expect(qs('nessuna-riga')).toBeNull();
        expect(qs('stampa-il-pdf')!.tagName).toBe('A');
    });

    test('nel registro dello studio niente menu degli esercizi, e il condominio fra i filtri', async () => {
        await apri({
            delloStudio: true, esercizio: null, esercizi: [], luogo: 'Tutti i condomini e lo studio',
            filtri: { chi: null, area: null, azione: null, mese: 'tutti', cerca: null, condominio: 4 },
            scelte: { ...stampa().scelte, condomini: [scelta('', 'Tutti i condomini'), scelta('4', 'Via Roma 12')] },
        });
        expect([...corpo().querySelectorAll('[data-testid="scorciatoie-periodo"] [role="radio"]')].map((b) => pulito(b.textContent)))
            .toEqual(['Questo mese', 'Dall\'inizio', 'Scegli le date']);
        expect(qs('stampa-esercizio')).toBeNull();
        expect(scorciatoiaAccesa()).toBe('Dall\'inizio');
        expect(pulito(qs('finestra-stampa')!.textContent)).toContain('Tutti i condomini e lo studio · il PDF con la carta dello studio e l\'impronta in fondo');
        expect(pulito(menu('stampa-condominio').querySelector('.vs__selected')?.textContent)).toBe('Via Roma 12');
        expect(ultimoConto()).toEqual(['/conto', { params: { condominio: '4', dal: '2024-03-01', al: '2026-11-30' } }]);
        expect(riepilogo()).toBe('Stamperai 134 righe, dal 01/03/2024 al 30/11/2026, nel condominio Via Roma 12.');
    });
});
