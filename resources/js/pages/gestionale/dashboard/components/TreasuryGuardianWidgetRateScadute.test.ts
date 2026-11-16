// @vitest-environment jsdom

/**
 * La tesoreria a 30 giorni e l'elenco delle rate scadute: «Vedi tutte le rate scadute →» in fondo al cassetto delle persone, sempre, anche con il semaforo
 * verde; il ritardo con le parole del server (un pregresso di anzianità non nota non è mai «in ritardo di N giorni»); lo stato del livello rosso parla della
 * liquidità, senza parole di effetto legale; a che punto è l'accordo di rientro lo dice la frase della regola, una riga sola. Il testo si legge montato.
 */
import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { visit: vi.fn(), reload: vi.fn() },
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => `admin.${nome}`, hasPermission: () => true }) }));
(globalThis as any).route = (nome: string, p?: Record<string, unknown>) => `/${nome}/${Object.values(p ?? {}).join('/')}`;

import TreasuryGuardianWidget from './TreasuryGuardianWidget.vue';
import { persona as personaDiProva, tesoreria } from './tesoreriaDiProva';

const stubs = { LettereDialog: true, DaDoveViene: true, Popover: { template: '<div><slot /></div>' }, PopoverTrigger: { template: '<div><slot /></div>' },
    PopoverContent: { template: '<div><slot /></div>' } };
const persona = (n: number, giorni: number | null, ritardoTesto: string, altro: Record<string, unknown> = {}) => personaDiProva(n, { giorniRitardo: giorni, ritardoTesto, ...altro });
async function monta(altro: Record<string, unknown>) {
    const w = mount(TreasuryGuardianWidget, { props: { treasury: tesoreria({ livello: 'verde', giornoScopertoPrevisto: null, ...altro }) as any }, global: { stubs } });
    await w.get('[data-testid="cassetto-persone"] button').trigger('click');

    return w;
}
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();

describe('«Vedi tutte le rate scadute →»', () => {
    test('c\'è in fondo al cassetto delle persone anche con il semaforo verde, e porta all\'elenco del condominio', async () => {
        const w = await monta({ morositaImpattanti: [persona(1, 74, 'in ritardo di 74 giorni')], morositaPersone: 1 });
        const link = w.get('[data-testid="fondo-persone"] a');
        expect(link.text()).toBe('Vedi tutte le rate scadute →');
        expect(link.attributes('href')).toBe('/admin.gestionale.rate-scadute.index/7');
    });
});

describe('il ritardo con le parole del server', () => {
    test('un pregresso di anzianità non nota non è «in ritardo di N giorni»; con una rata di 12 giorni, il ritardo massimo noto', async () => {
        const w = await monta({ morositaPersone: 2, morositaImpattanti: [
            persona(1, null, 'c\'è un pregresso di anzianità non nota'),
            persona(2, 12, 'ritardo massimo noto: 12 giorni; c\'è un pregresso di anzianità non nota'),
        ] });
        const righe = w.findAll('[data-testid="riga-persona"]').map((r) => pulito(r.text()));
        expect(righe[0]).toContain('c\'è un pregresso di anzianità non nota');
        expect(righe[1]).toContain('ritardo massimo noto: 12 giorni; c\'è un pregresso di anzianità non nota');
        expect(pulito(w.text())).not.toMatch(/in ritardo di (null|0) giorni/);
    });
});

describe('lo stato del livello rosso', () => {
    test('parla della liquidità, e nella tesoreria montata non c\'è «ingiunzione» né «moros»', async () => {
        const testo = pulito((await monta({ livello: 'rosso', giornoScopertoPrevisto: '2026-11-20', morositaImpattanti: [persona(1, 74, 'in ritardo di 74 giorni')], morositaPersone: 1 })).text());
        expect(testo).toContain('Le uscite non sono coperte');
        expect(testo).toContain('Sotto zero dal');
        expect(testo.toLowerCase()).not.toContain('ingiunzion');
        expect(testo.toLowerCase()).not.toContain('moros');
    });
});

describe('l\'accordo di rientro nella riga della persona', () => {
    test('la riga dice a che punto è l\'accordo con la frase della regola, una riga sola; senza accordo niente', async () => {
        const accordo = 'La rata dell\'accordo del 31/08/2026 non è versata.';
        const w = await monta({ morositaPersone: 2, morositaImpattanti: [
            persona(1, 158, 'in ritardo di 158 giorni', { passo: { caso: 'R3', passo: null, frase: accordo, frase_dei_fatti: accordo, note: [], rata_compensa: null } }),
            persona(2, 12, 'in ritardo di 12 giorni'),
        ] });
        const righe = w.findAll('[data-testid="riga-persona"]');
        expect(righe[0].text().split(accordo)).toHaveLength(2);
        expect(righe[1].text()).not.toContain('accordo');
        expect(w.find('[data-testid="accordo-tesoreria"]').exists()).toBe(false);
    });
});
