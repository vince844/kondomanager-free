// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Modifica associazione tabella» riapre le percentuali come sono salvate.
 *
 * Il server non salva le righe a zero; la finestra rileggeva il proprietario assente come 100: dopo «Proprietario 0 %, Inquilino 100 %» riapriva
 * 100/100/0, il totale diventava 200 % e «Salva modifiche» restava spento. La regola è quella del motore e di «Modifica voce»: una riga assente vale
 * 0 se ce n'è un'altra; senza nessuna riga la voce è tutta del proprietario.
 *
 * Si monta il componente vero, aperto come lo apre la pagina (prima chiuso, poi `show` con la tabella da modificare).
 *
 * Che cosa non copre: il salvataggio sul server e la rilettura dalle props della pagina (`Beta15RipartoPerSoggettoTest.php`).
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    useForm: (dati: Record<string, unknown>) =>
        reactive({ ...dati, errors: {}, processing: false, clearErrors: vi.fn(), setError: vi.fn(), reset: vi.fn() }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));
vi.mock('vue-select', () => ({ default: { name: 'VSelectFinto', template: '<div />' } }));
vi.mock('@/composables/useTabelle', () => ({ useTabelle: () => ({ tabelle: { value: [] }, isLoading: { value: false }, fetchTabelle: vi.fn() }) }));

const ModalAssociaTabella = (await import('./ModalAssociaTabella.vue')).default;

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

async function riapri(ripartizioni: Array<{ soggetto: string; percentuale: number }>) {
    w = mount(ModalAssociaTabella, {
        props: { show: false, conto: { id: 7, nome: 'Pulizie', tipo: 'spesa' } as never, condominioId: 1, residuoDisponibile: 0, tabelleGiaAssociateIds: [3] },
        attachTo: document.body,
    });
    await w.setProps({ show: true, tabellaEsistente: { id: 3, nome: 'Proprietà', coefficiente: 100, ripartizioni } });
    await nextTick();
    await new Promise((r) => setTimeout(r, 0));

    const campo = (id: string) => (document.querySelector(`input#${id}`) as HTMLInputElement).value;
    const salva = document.querySelector('button[type="submit"]') as HTMLButtonElement;

    return { terna: [campo('proprietario'), campo('inquilino'), campo('usufruttuario')], salva };
}

describe('ModalAssociaTabella — le percentuali salvate si riaprono uguali', () => {
    test.each([
        ['0/100/0', [{ soggetto: 'inquilino', percentuale: 100 }], ['0', '100', '0']],
        ['0/0/100', [{ soggetto: 'usufruttuario', percentuale: 100 }], ['0', '0', '100']],
        ['0/50/50', [{ soggetto: 'inquilino', percentuale: 50 }, { soggetto: 'usufruttuario', percentuale: 50 }], ['0', '50', '50']],
        ['50/50/0', [{ soggetto: 'inquilino', percentuale: 50 }, { soggetto: 'proprietario', percentuale: 50 }], ['50', '50', '0']],
        ['100/0/0', [{ soggetto: 'proprietario', percentuale: 100 }], ['100', '0', '0']],
        ['nessuna riga = tutto del proprietario', [], ['100', '0', '0']],
    ])('%s: i tre campi mostrano il salvato, il totale è 100 %% e «Salva modifiche» è acceso', async (_nome, ripartizioni, attese) => {
        const { terna, salva } = await riapri(ripartizioni);

        expect(terna).toEqual(attese);
        expect(document.body.textContent).toContain('Totale: 100%');
        expect(salva.disabled).toBe(false);
        expect(salva.textContent?.trim()).toBe('Salva modifiche');
    });

    test('salvando, la terna riaperta torna com\'era', async () => {
        const { salva } = await riapri([{ soggetto: 'inquilino', percentuale: 100 }]);
        salva.closest('form')!.dispatchEvent(new Event('submit', { cancelable: true }));
        await nextTick();

        const inviato = w!.emitted('success')?.[0]?.[0] as Record<string, unknown>;
        expect([inviato.percentuale_proprietario, inviato.percentuale_inquilino, inviato.percentuale_usufruttuario]).toEqual([0, 100, 0]);
    });
});
