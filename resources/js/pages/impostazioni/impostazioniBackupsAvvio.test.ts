// @vitest-environment jsdom

/**
 * Coda 400 — un backup che non può partire, perché la cartella dei suoi temporanei non si crea, risulta fallito al primo
 * passo con la sua ragione: la finestra dei backup la mostra come per ogni passo che non riesce, e il backup non resta
 * «in corso».
 *
 * L'avvio (`impostazioni.backups.store`) crea il backup ed esegue il primo passo nella stessa richiesta: la risposta
 * porta già il backup fallito, con l'errore.
 */

import { describe, expect, test, vi } from 'vitest';
import { reactive } from 'vue';
import { flushPromises, shallowMount } from '@vue/test-utils';

const post = vi.fn();
const get = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    router: { visit: vi.fn(), delete: vi.fn(), post: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { flash: {} } }),
    useForm: (dati: Record<string, unknown>) =>
        reactive({ ...dati, errors: {}, processing: false, post: vi.fn(), reset: vi.fn(), data: () => ({ ...dati }) }),
}));

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    transChoice: (chiave: string, quanti: number) => `${chiave} ${quanti}`,
}));
vi.mock('axios', () => ({ default: { post, get } }));

(globalThis as Record<string, unknown>).route = (nome: string, parametro?: string) => (parametro ? `/${nome}/${parametro}` : `/${nome}`);

const PaginaBackup = (await import('./impostazioniBackups.vue')).default;

type Stato = {
    createBackup: () => Promise<void>;
    localError: string | null;
    running: Record<string, unknown> | null;
};

function monta(): Stato {
    const pagina = shallowMount(PaginaBackup, {
        props: {
            backups: { data: [] },
            runningBackup: null,
            preflight: { encryption_supported: true, checks: [] },
            retention_keep_last: 5,
            backup_has_password: false,
        },
    });

    return (pagina.vm as unknown as { $: { setupState: Stato } }).$.setupState;
}

describe('impostazioniBackups — l\'avvio di un backup che non può partire', () => {
    test('Coda 400 — la risposta dell\'avvio con il backup già fallito: la finestra mostra la ragione, non chiede altri passi e non lo tiene in corso', async () => {
        post.mockReset();
        post.mockResolvedValueOnce({ data: { backup: { uuid: 'b-1', status: 'failed', type: 'db_only', error: 'mkdir(): Permission denied' } } });
        const stato = monta();

        await stato.createBackup();
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(1);
        expect(stato.localError).toBe('mkdir(): Permission denied');
        expect(stato.running).toBeNull();
    });

    test('Coda 400 — senza la ragione nella risposta la finestra dice la frase generica', async () => {
        post.mockReset();
        post.mockResolvedValueOnce({ data: { backup: { uuid: 'b-1', status: 'failed', type: 'db_only', error: null } } });
        const stato = monta();

        await stato.createBackup();
        await flushPromises();

        expect(stato.localError).toBe('impostazioni.backup_error_generic');
        expect(stato.running).toBeNull();
    });
});
