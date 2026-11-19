// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'elenco delle comunicazioni nel pannello: lo scudo dell'approvazione su una programmata (P8 della lente P, Fase 1-bis della beta.12; Coda 589).
 *
 * Lo scudo cambia l'approvazione con un clic. Su una programmata (approvata, non ancora uscita, con il giorno e l'ora) togliere l'approvazione toglie
 * anche la data (la regola del model, `ProgrammateDatabaseTest`); e su una comunicazione non approvata rimettere l'approvazione la pubblica in quel
 * momento, con le email ai destinatari. Con il codice di prima tutte e due le cose partivano al primo clic, senza una parola: «decide
 * l'amministratore, e dice le conseguenze». Ora lo scudo chiede conferma e dice che cosa succede; il comando parte solo con «conferma».
 *
 * Che cosa non copre: quante email partono davvero (dipende dalle preferenze di ognuno, lo sa il server); la finestra vera di `reka-ui` (qui
 * `ConfirmDialog` è finto, e mostra titolo, testo e pulsante).
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string, sostituzioni?: Record<string, string>) =>
        sostituzioni ? `${chiave}(${Object.entries(sostituzioni).map(([k, v]) => `${k}=${v}`).join(',')})` : chiave,
    transChoice: (chiave: string, numero: number) => `${chiave}#${numero}`,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasRole: () => true, hasPermission: () => true }),
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const { columns } = await import('./columns');

type Colonna = { accessorKey?: string; cell: (contesto: unknown) => unknown };
const colonna = (columns as unknown as Colonna[]).find((c) => c.accessorKey === 'subject')!;

const ConfirmDialogFinto = {
    name: 'ConfirmDialog',
    props: ['modelValue', 'title', 'description', 'confirmText', 'variant'],
    emits: ['update:modelValue', 'confirm'],
    template: '<div v-if="modelValue" data-conferma><p data-titolo>{{ title }}</p><p data-testo>{{ description }}</p>'
        + '<button data-conferma-ok @click="$emit(\'confirm\')">{{ confirmText }}</button></div>',
};

const aggiornate: Array<Record<string, unknown>> = [];

function monta(campi: Record<string, unknown> = {}) {
    const originale: Record<string, unknown> = {
        id: 7, subject: 'Chiusura dell\'acqua', priority: 'media', is_published: false, is_approved: true, is_private: false,
        data_programmata: '2027-01-15T09:00', condomini: { options: [{ value: 3, label: 'Aurora' }], full: [] }, anagrafiche: [],
        contatori: { commenti: 0, allegati: 0, in_attesa: 0 },
        ...campi,
    };
    const contesto = {
        row: { original: originale, index: 0, getValue: (k: string) => originale[k] },
        table: { options: { meta: { updateData: (_i: number, dati: Record<string, unknown>) => aggiornate.push(dati) } } },
    };

    return mount({ render: () => colonna.cell(contesto) }, { attachTo: document.body, global: { stubs: { ConfirmDialog: ConfirmDialogFinto, Link: true } } });
}

const scudo = (w: ReturnType<typeof monta>) => w.find('[data-scudo-approvazione]');

beforeEach(() => {
    document.body.innerHTML = '';
    aggiornate.length = 0;
    vi.spyOn(router, 'put').mockImplementation(() => undefined as never);
    vi.mocked(router.put).mockClear();
});

describe('lo scudo dell\'approvazione nell\'elenco delle comunicazioni', () => {
    test('su una programmata il clic non toglie la data: chiede conferma e dice il giorno e l\'ora che si perdono', async () => {
        const w = monta();

        await scudo(w).trigger('click');
        await flushPromises();

        // Con il codice di prima il comando partiva qui, al primo clic.
        expect(router.put).not.toHaveBeenCalled();
        expect(w.find('[data-conferma]').exists()).toBe(true);
        expect(w.find('[data-titolo]').text()).toBe('comunicazioni.shield.unschedule_title');
        expect(w.find('[data-testo]').text()).toBe('comunicazioni.shield.unschedule_description(date=15/01/2027,time=09:00)');

        await w.find('[data-conferma-ok]').trigger('click');
        expect(router.put).toHaveBeenCalledTimes(1);
        expect(String(vi.mocked(router.put).mock.calls[0][0])).toContain('comunicazioni.toggle-approval');
    });

    test('su una comunicazione non approvata il clic non la pubblica: chiede conferma, e dice che partono le email ai destinatari', async () => {
        const w = monta({ is_approved: false, data_programmata: null });

        await scudo(w).trigger('click');
        await flushPromises();

        expect(router.put).not.toHaveBeenCalled();
        expect(w.find('[data-titolo]').text()).toBe('comunicazioni.shield.publish_title');
        expect(w.find('[data-testo]').text()).toBe('comunicazioni.shield.publish_description comunicazioni.shield.publish_emails');

        await w.find('[data-conferma-ok]').trigger('click');
        expect(router.put).toHaveBeenCalledTimes(1);
    });

    test('senza destinatari (nessun palazzo, nessuna persona) la conferma non promette email', async () => {
        const w = monta({ is_approved: false, data_programmata: null, condomini: { options: [], full: [] }, anagrafiche: [] });

        await scudo(w).trigger('click');
        await flushPromises();

        expect(w.find('[data-testo]').text()).toBe('comunicazioni.shield.publish_description');
    });

    test('controprova: su una pubblicata il clic toglie l\'approvazione come prima, senza conferma', async () => {
        const w = monta({ is_published: true, data_programmata: null });

        await scudo(w).trigger('click');

        expect(router.put).toHaveBeenCalledTimes(1);
        expect(w.find('[data-conferma]').exists()).toBe(false);
    });

    test('dopo la conferma la riga dice lo stato nuovo: senza approvazione e senza la data', async () => {
        vi.mocked(router.put).mockImplementation(((_u: unknown, _d: unknown, opzioni: { onSuccess?: () => void }) => { opzioni.onSuccess?.(); }) as never);
        const w = monta();

        await scudo(w).trigger('click');
        await flushPromises();
        await w.find('[data-conferma-ok]').trigger('click');

        expect(aggiornate.at(-1)).toMatchObject({ is_approved: false, is_published: false, data_programmata: null });
    });
});
