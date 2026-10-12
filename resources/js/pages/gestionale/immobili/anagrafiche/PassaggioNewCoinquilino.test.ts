// @vitest-environment jsdom

/**
 * 1.11.0-beta.50 — il modulo della fine di una locazione (`PassaggioNew.vue`) quando resta un coinquilino (decisione 79, punto 2;
 * rilievo 4 del verbale): la locazione continua, e il modulo non deve dire «l'unità resta sfitta» — né nella casella «Nessuno», né
 * nella descrizione della card «Chi entra», né in ciò che manca (`mancante`, che il pannello mostra).
 *
 * Il modulo lo può sapere da solo: la pagina riceve `titolari`, le righe in corso dell'unità con tipologia, quota e date
 * (`PassaggioController::create`), e chi esce è la riga scelta. Un'altra riga d'inquilino con una quota, in corso il giorno prima
 * dell'uscita e il giorno stesso, è il coinquilino che resta (la regola del prospetto, `coinquilinoCheResta`). Dora qui è inquilina
 * dal 2019, senza fine: lo è per qualunque data. Il pannello lo sa anche dal server (`rate.fermata_coinquilino`), ma solo quando ci
 * sono rate da fermare: la casella si legge prima, e anche senza.
 *
 * Si monta la pagina con `shallowMount` (i figli sono segnaposto), come in `PassaggioNewLocazione.test.ts`; la data si scrive dal
 * segnaposto del calendario. L'anteprima del server non serve: `axios.post` resta in attesa.
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { nextTick } from 'vue';
import VueDatePicker from '@vuepic/vue-datepicker';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));

vi.mock('axios', () => ({ default: { post: vi.fn(() => new Promise(() => {})) } }));

(globalThis as unknown as { route: unknown }).route = (name: string) => `/${name}`;

const PassaggioNew = (await import('./PassaggioNew.vue')).default;
const AnteprimaPassaggio = (await import('@/components/gestionale/immobili/AnteprimaPassaggio.vue')).default;

const UGO = { id: 10, anagrafica: { id: 1, nome: 'Ugo', codice_fiscale: null }, tipologia: 'proprietario', quota: 100, data_inizio: '2019-01-01', data_fine: null };

/** La pagina della fine locazione di Ines (riga 11, già scelta), con le righe d'inquilino date, e la data del passaggio scritta. */
async function monta(inquilini: { id: number; nome: string; quota: number }[]) {
    const w = shallowMount(PassaggioNew, {
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never,
            immobile: { id: 7, nome: 'Interno 1' } as never,
            tipo: 'fine_locazione',
            rigaPreselezionata: 11,
            titolari: [UGO, ...inquilini.map((i, n) => ({ id: i.id, anagrafica: { id: 2 + n, nome: i.nome, codice_fiscale: null }, tipologia: 'inquilino', quota: i.quota, data_inizio: '2019-01-01', data_fine: null }))],
            anagrafiche: [],
            pertinenze: [],
            rateEmesseCount: 6,
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (name: string) => `/${name}` } },
    });
    w.findAllComponents(VueDatePicker)[0].vm.$emit('update:modelValue', '2026-07-01');
    await nextTick();

    return w;
}

/** Il testo della pagina e ciò che manca (il pannello lo mostra), insieme. */
function testi(w: Awaited<ReturnType<typeof monta>>): string {
    return [w.text(), ...((w.findComponent(AnteprimaPassaggio).props('mancante') as string[]) ?? [])].join(' | ');
}

/** La casella di chi non entra: c'è, con qualunque parola cominci («Nessuno», «Nessun nuovo inquilino»). */
function casellaNessuno(w: Awaited<ReturnType<typeof monta>>) {
    return w.findAll('input[type="checkbox"]').find((c) => (c.element.closest('label')?.textContent ?? '').includes('Nessun'));
}

describe('PassaggioNew — la fine di una locazione con un coinquilino che resta (1.11.0-beta.50, decisione 79, rilievo 4)', () => {
    test('Ines esce e Dora resta: il modulo non dice «l\'unità resta sfitta», né nella casella, né nella card, né in ciò che manca', async () => {
        const w = await monta([{ id: 11, nome: 'Ines', quota: 50 }, { id: 12, nome: 'Dora', quota: 50 }]);

        // Controlli: la casella per dire che nessuno entra c'è ancora, e il pannello non sa ancora che nessuno entra.
        expect(casellaNessuno(w)).toBeDefined();
        expect(w.findComponent(AnteprimaPassaggio).props('senzaSuccessore')).toBe(false);

        expect(testi(w)).not.toContain('resta sfitta');
    });

    test('la guardia — Ines esce sola: la casella, la card e ciò che manca dicono ancora che l\'unità resta sfitta', async () => {
        const w = await monta([{ id: 11, nome: 'Ines', quota: 100 }]);

        expect(w.text()).toContain('Nessuno — l\'unità resta sfitta');
        expect(w.text()).toContain('Un nuovo inquilino, oppure nessuno: l\'unità resta sfitta.');
        expect(w.findComponent(AnteprimaPassaggio).props('mancante')).toContain('chi entra, oppure la spunta «Nessuno — l\'unità resta sfitta»');
    });
});
