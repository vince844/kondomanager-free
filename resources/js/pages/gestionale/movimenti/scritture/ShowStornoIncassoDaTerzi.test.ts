// @vitest-environment jsdom

/**
 * Lo storno di un incasso da terzi dalla pagina della scrittura: chiede la natura (registrazione sbagliata, o incasso tornato indietro) con il giorno
 * da cui varrebbe lo storno per ognuna (dal server, `aaaa-mm-gg`), e il motivo; manda tutti e due alla rotta dello storno dell'incasso da terzi.
 * La regolazione immediata resta com'era: il solo motivo, alla sua rotta. Un incasso già stornato non ha il pulsante.
 *
 * Il lato del server è in `tests/Feature/Gestionale/IncassoDaTerziTest.php`.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';

const post = vi.fn();
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    router: { post: (...a: unknown[]) => post(...a), visit: vi.fn() },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import Show from './Show.vue';

(globalThis as any).route = (nome: string, p: Record<string, unknown> = {}) => `/${nome}/${p.condominio ?? ''}/${p.scrittura ?? ''}`;
const mocks = { route: (globalThis as any).route };
const stubs = { GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: { template: '<div><slot name="actions" /></div>' }, Head: { template: '<span />' } };

const scrittura = (tipo: string, figlie: unknown[] = []) => ({
    id: 77, data_registrazione: '07/11/2026', data_competenza: '15/02/2026', numero_protocollo: 'ENT-2026-00001', causale: 'Affitto antenna 2026',
    descrizione: null, tipo_movimento: tipo, tipo_movimento_label: 'Incasso da terzi', stato: 'registrata', note: null, created_at: '07/11/2026 10:00',
    esercizio: { id: 41, nome: 'Esercizio 2026' }, gestione: { id: 10, nome: 'Ordinaria' }, righe: [], totale_dare: 24000, totale_avere: 24000,
    is_quadrata: true, padre: null, figlie, pagamento_fornitore: null, fatture: [],
});

let pagina: VueWrapper | null = null;

async function apri(tipo = 'incasso_diverso', figlie: unknown[] = []) {
    post.mockReset();
    pagina = mount(Show, {
        attachTo: document.body,
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' }, condomini: [], scrittura: scrittura(tipo, figlie),
            storno_vale_dal: tipo === 'incasso_diverso' ? { errore_registrazione: '2026-02-15', versamento_tornato: '2026-11-07' } : null,
        } as never,
        global: { stubs, mocks },
    });
    await nextTick();

    return pagina.vm as any;
}

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('lo storno di un incasso da terzi', () => {
    test('chiede la natura, con il giorno da cui vale per ognuna', async () => {
        const stato = await apri();
        stato.mostraModaleStorno = true;
        await nextTick();
        const natura = document.body.querySelector('[data-blocco="natura-storno"]')!.textContent!.replace(/\s+/g, ' ');

        expect(natura).toContain('Registrazione sbagliata');
        expect(natura).toContain('Lo storno vale dal 15/02/2026.');
        expect(natura).toContain('tornato indietro');
        expect(natura).toContain('Lo storno vale dal 07/11/2026.');
    });

    test('senza la natura non parte; con la natura e il motivo va alla rotta dell\'incasso da terzi', async () => {
        const stato = await apri();
        stato.motivoStorno = 'Bonifico richiamato dal gestore';
        stato.confermaStorno();
        expect(post).not.toHaveBeenCalled();

        stato.naturaStorno = 'versamento_tornato';
        stato.confermaStorno();
        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/admin.gestionale.incassi-da-terzi.storno/28/77');
        expect(post.mock.calls[0][1]).toEqual({ natura_storno: 'versamento_tornato', motivo: 'Bonifico richiamato dal gestore' });
    });

    test('la regolazione immediata resta com\'era: il solo motivo, alla sua rotta', async () => {
        const stato = await apri('regolazione_immediata');
        stato.motivoStorno = 'Capitolo di spesa sbagliato';
        stato.confermaStorno();

        expect(post.mock.calls[0][0]).toBe('/admin.gestionale.regolazioni-immediate.storno/28/77');
        expect(post.mock.calls[0][1]).toEqual({ motivo: 'Capitolo di spesa sbagliato' });
    });

    test('un incasso già stornato non ha il pulsante «Storna»', async () => {
        const stato = await apri('incasso_diverso', [{ id: 78, causale: 'Storno', tipo_movimento: 'storno_incasso_diverso', tipo_movimento_label: 'Storno incasso da terzi', stato: 'registrata', created_at: null }]);

        expect(stato.puoStornare).toBe(false);
    });
});
