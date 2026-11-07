// @vitest-environment jsdom

/**
 * Coda 464 (2) — il riquadro «Stato Incassi» della guida in testa alla pagina del piano rate dice dove si incassano le rate.
 *
 * Diceva che le rate emesse si incassano «dalla sezione Prima Nota o importando i movimenti bancari». La Prima nota ha solo l'elenco e la stampa
 * (`registroContabilita/List.vue`: nessuna azione che registri un incasso), e l'importazione dei movimenti bancari non esiste: nessuna rotta e nessuna
 * classe. Gli incassi delle rate si registrano da «Incassi», la prima voce del menu dei movimenti (`MovimentiLayout.vue`, rotta `movimenti-rate`; la pagina si
 * chiama «Incassi rate», la voce del menu è corta).
 * Ora il riquadro lo dice, con un rimando che porta lì.
 *
 * La pagina è quella vera, con la cornice, la guida in testa e i riquadri: si stubbano le parti che non c'entrano (la cornice del gestionale, il
 * calendario delle date, il pannello laterale, il modale di «Sposta spesa», lo storico dei movimenti e gli avvisi). Del riquadro si legge il testo e dove
 * porta il rimando, come `PianiRateList.test.ts` fa con il suo.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { delete: vi.fn(), post: vi.fn(), get: vi.fn(), visit: vi.fn(), reload: vi.fn() },
    usePage: () => ({ url: '/admin/gestionale/7/esercizi/1/piani-rate/5', props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        // Come il composable vero: il prefisso del ruolo e i segnaposto del percorso riempiti (`:condominio` diventa l'id).
        generatePath: (percorso: string, parametri: Record<string, number> = {}) => '/admin/' + percorso.replace(/:(\w+)/g, (_, nome: string) => String(parametri[nome])),
        generateRoute: (nome: string) => nome,
        hasPermission: () => true,
        hasRole: () => true,
    }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (c: string) => c }));
(globalThis as unknown as { route: unknown }).route = (nome: string, parametri?: Record<string, unknown>) => `/${nome}?${JSON.stringify(parametri ?? {})}`;

const PianiRateShow = (await import('@/pages/gestionale/pianiRate/PianiRateShow.vue')).default;

let pagina: VueWrapper | null = null;

async function monta(stato: 'bozza' | 'approvato') {
    pagina = mount(PianiRateShow, {
        props: {
            condominio: { id: 7, nome: 'Condominio Demo' } as never,
            esercizio: { id: 1, nome: '2026', stato: 'aperto' } as never,
            pianoRate: { id: 5, nome: 'Preventivo 2026', stato, tipo: 'ordinario', numero_rate: 12, totale_capitoli: 144000, budget_movements: [], capitoli: [] },
            quotePerAnagrafica: [], quotePerImmobile: [], ratePure: [], needsMigration: false, sources: [], destinations: [], copertura: null, fermo_del_piano: null,
        },
        global: {
            stubs: {
                GestionaleLayout: { template: '<div><slot /></div>' }, VueDatePicker: true, PianoRateSpaccatoSheet: true, ModalSpostaSpesa: true, BudgetHistoryPopover: true,
                DestinatariWarning: true, DestinatariWarningDefunto: true, ScopertoWarning: true,
            },
            mocks: { route: (n: string, p?: Record<string, unknown>) => `/${n}?${JSON.stringify(p ?? {})}` },
        },
    });
    await flushPromises();

    return pagina;
}

/** Il riquadro della guida col suo titolo (il titolo è il primo testo del blocco). */
const riquadro = (titolo: string) => {
    const blocco = pagina!.findAll('div.space-y-3').find((d) => d.find('span.uppercase').exists() && d.find('span.uppercase').text().toLowerCase() === titolo.toLowerCase());
    if (!blocco) throw new Error(`nessun riquadro «${titolo}»`);
    return blocco;
};
const pulito = (testo: string) => testo.replace(/\s+/g, ' ').trim();

afterEach(() => {
    pagina?.unmount();
    pagina = null;
});

describe('il riquadro «Stato Incassi» dice dove si incassano le rate (Coda 464)', () => {
    test('dice che le rate emesse si incassano da «Incassi», la voce del menu dei movimenti', async () => {
        await monta('approvato');

        expect(pulito(riquadro('Stato Incassi').find('p').text())).toBe('Monitora costantemente il saldo netto. Le rate emesse si incassano da «Incassi», nei movimenti.');
    });

    test('non manda più alla Prima nota né a un\'importazione dei movimenti bancari, che non c\'è', async () => {
        await monta('approvato');

        const testo = pulito(riquadro('Stato Incassi').text());

        expect(testo).not.toContain('Prima Nota');
        expect(testo).not.toContain('Prima nota');
        expect(testo).not.toContain('importando');
        expect(testo).not.toContain('movimenti bancari');
    });

    test('il rimando porta alla pagina degli incassi delle rate del condominio, la stessa voce del menu dei movimenti', async () => {
        await monta('approvato');

        const rimando = riquadro('Stato Incassi').find('a');

        expect(rimando.exists()).toBe(true);
        expect(rimando.attributes('href')).toBe('/admin/gestionale/7/movimenti-rate');
        expect(pulito(rimando.text())).toBe('Vai agli incassi rate');
    });

    test('il riquadro è lo stesso con il piano in bozza: le rate si incasseranno da lì appena emesse', async () => {
        await monta('bozza');

        expect(pulito(riquadro('Stato Incassi').find('p').text())).toContain('Le rate emesse si incassano da «Incassi», nei movimenti.');
        expect(riquadro('Stato Incassi').find('a').attributes('href')).toBe('/admin/gestionale/7/movimenti-rate');
    });

    test('controprova: gli altri due riquadri non cambiano, nessun rimando e lo stesso testo', async () => {
        await monta('approvato');

        expect(riquadro('Verifica Dati').find('a').exists()).toBe(false);
        expect(riquadro('Approvazione').find('a').exists()).toBe(false);
        expect(pulito(riquadro('Verifica Dati').find('p').text())).toBe('Controlla che i capitoli di spesa siano allineati ai preventivi prima di procedere. Se noti discrepanze, usa la funzione "Ricalcola".');
        expect(pulito(riquadro('Approvazione').find('p').text())).toBe('Passa il piano in stato Approvato registrando i dati della delibera. Questa operazione "congela" le quote e ti permette di emettere le rate in contabilità.');
    });
});
