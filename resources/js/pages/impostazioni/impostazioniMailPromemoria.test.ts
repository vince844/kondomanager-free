// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Configurazione email»: «Promemoria per email delle convocazioni da spedire», sotto le caselle dello studio.
 *
 * - L'interruttore acceso di serie, con la riga «Adesso» del server e l'esempio dei giorni.
 * - Si cambia da solo, al clic: la rotta del promemoria con `acceso`, senza salvare la casella; la pagina ricarica solo il promemoria.
 * - Senza la prop (una pagina di prima) la parte non c'è.
 * - Nel sorgente nuovo: nessun `<select>` del browser, niente scritte sotto i 12 pixel.
 *
 * Che cosa non copre: il server (`tests/Feature/Impostazioni/PromemoriaDelleConvocazioniTest.php`); l'aspetto a video, nel tema scuro e a 375 pixel.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const inertia = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn(), delete: vi.fn() }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: inertia,
    usePage: () => ({ props: { flash: {}, auth: { user: { email: 'andrea.costa@studiocosta.it' } } } }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));
vi.mock('axios', () => ({ default: { post: vi.fn() } }));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Pagina = (await import('./impostazioniMail.vue')).default;

const posta = { stato: 'provata', parola: 'Provata', riga: 'segreteria@studiocosta.it', adesso: 'Adesso: impostata.', card: { valore: 'Provata', sotto: '' },
    ultima_prova: null, sola_lettura: false };
const pec = { stato: 'da_impostare', parola: 'Da impostare', riga: null, adesso: 'Adesso: da impostare.', card: { valore: 'Da impostare', sotto: '' }, ultima_prova: null,
    valori: { indirizzo: null, server: null, porta: 465, cifratura: 'ssl', utente: null }, password_impostata: false, dalla_carta: null };
const ACCESO = { acceso: true, adesso: 'Adesso: acceso. A chi ha preparato l\'assemblea, due giorni lavorativi prima del giorno limite per spedire la convocazione e la mattina di quel giorno, se restano avvisi da spedire.' };

let w: VueWrapper | null = null;
beforeEach(() => { inertia.post.mockClear(); });
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

async function monta(promemoria: unknown = ACCESO, conLaProp = true) {
    w = mount(Pagina, {
        attachTo: document.body,
        props: { settings: { mail_enabled: true, mail_driver: 'smtp' }, password_set: true, caselle: { posta, pec }, invii: { titolo: '', valore: '', sotto: '', url: '/logs' },
            apri: null, ...(conLaProp ? { promemoria } : {}) },
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, MailSetupGuide: true } },
    });
    await flushPromises();

    return w;
}

describe('«Promemoria per email delle convocazioni da spedire»', () => {
    test('acceso di serie: il nome, la riga «Adesso» del server e l\'esempio dei giorni', async () => {
        await monta();
        const parte = w!.get('[data-testid="promemoria-convocazioni"]');
        const t = parte.text().replace(/\s+/g, ' ');
        expect(t).toContain('I promemoria per email');
        expect(t).toContain('Promemoria per email delle convocazioni da spedire');
        expect(t).toContain(ACCESO.adesso);
        expect(t).toContain('Esempio: con le raccomandate da spedire entro giovedì 03/12, l\'email arriva martedì 01/12 e giovedì 03/12, la mattina.');
        expect(w!.get('[data-testid="interruttore-promemoria"]').attributes('aria-checked')).toBe('true');
    });

    test('al clic si spegne da solo: la rotta del promemoria, e la pagina ricarica solo il promemoria', async () => {
        await monta();
        await w!.get('[data-testid="interruttore-promemoria"]').trigger('click');
        await flushPromises();
        expect(inertia.post).toHaveBeenCalledWith('/admin.settings.mail.promemoria', { acceso: false },
            expect.objectContaining({ preserveScroll: true, only: ['promemoria'] }));
    });

    test('senza la prop la parte non c\'è', async () => {
        await monta(null, false);
        expect(w!.find('[data-testid="promemoria-convocazioni"]').exists()).toBe(false);
    });

    test('nel sorgente: nessun <select> del browser, niente scritte sotto i 12 pixel', () => {
        const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/components/impostazioni/caselle/PromemoriaDelleConvocazioni.vue'), 'utf8');
        expect(sorgente).not.toMatch(/<select\b/);
        expect(sorgente).not.toMatch(/text-\[(\d|1[01])(\.\d+)?px\]/);
    });
});
