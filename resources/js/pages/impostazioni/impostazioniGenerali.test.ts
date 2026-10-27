// @vitest-environment jsdom

/**
 * Le impostazioni generali: il ruolo predefinito dei nuovi utenti lo cambia solo un amministratore (Coda 258,
 * decisione 134.1).
 *
 * Chi gestisce le impostazioni generali con un ruolo su misura salva il resto del modulo; la tendina del ruolo
 * della registrazione gli si mostra senza farsi cambiare, con la nota che dice perché. Il server lo dice alla
 * pagina con `ruolo_registrazione_modificabile`. Aperta e mandata senza toccare niente, la pagina rimanda il
 * ruolo com'era.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { router } from '@inertiajs/core';

const pagina = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => pagina,
}));
vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const ImpostazioniGenerali = (await import('./impostazioniGenerali.vue')).default;

function monta(modificabile: boolean) {
    pagina.props = {
        flash: {},
        can_register: true,
        language: 'it',
        app_name: 'Condominio «Aurora»',
        open_condominio_on_login: false,
        default_condominio_id: null,
        condomini: [],
        default_user_role: 'utente',
        force_comment_moderation: false,
        default_per_page: 30,
        per_page_disponibili: [10, 30, 50],
        roles: [{ id: 1, name: 'utente' }, { id: 2, name: 'amministratore' }],
        ruolo_registrazione_modificabile: modificabile,
    };

    return shallowMount(ImpostazioniGenerali, {
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

/** La tendina del ruolo della registrazione: l'unica il cui valore è il ruolo. */
function tendinaDelRuolo(wrapper: ReturnType<typeof monta>) {
    return wrapper.findAllComponents({ name: 'Select' }).find((tendina) => tendina.props('modelValue') === 'utente')!;
}

beforeEach(() => {
    vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
    vi.mocked(router.post).mockClear();
});

describe('impostazioniGenerali — il ruolo della registrazione (Coda 258, decisione 134.1)', () => {
    test('a chi non è amministratore la tendina non si fa cambiare, e la nota dice perché', () => {
        const wrapper = monta(false);

        expect(tendinaDelRuolo(wrapper).props('disabled')).toBe(true);
        expect(wrapper.find('[data-test="ruolo-registrazione-bloccato"]').text())
            .toBe('impostazioni.ruolo_registrazione.solo_amministratore');
    });

    test('all\'amministratore la tendina si fa cambiare, senza la nota', () => {
        const wrapper = monta(true);

        expect(tendinaDelRuolo(wrapper).props('disabled')).toBeFalsy();
        expect(wrapper.find('[data-test="ruolo-registrazione-bloccato"]').exists()).toBe(false);
    });

    test('aperta e mandata senza toccare niente, rimanda il ruolo com\'era', () => {
        const wrapper = monta(false);

        (wrapper.vm as unknown as { $: { setupState: { submit: () => void } } }).$.setupState.submit();

        const chiamata = vi.mocked(router.post).mock.calls.at(-1)!;
        expect(chiamata[0]).toBe('/impostazioni.generali.store');
        expect(chiamata[1]).toMatchObject({ default_user_role: 'utente', app_name: 'Condominio «Aurora»', default_per_page: 30 });
    });
});
