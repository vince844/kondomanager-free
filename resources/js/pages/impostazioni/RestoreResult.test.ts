// @vitest-environment jsdom

/**
 * Coda 343 — la pagina dell'esito del ripristino senza il dettaglio.
 *
 * La pagina si apre senza accesso. A chi non vede il dettaglio (un ospite, o chi non gestisce i backup)
 * il server manda solo la fase, se è stato annullato e che cosa riconfigurare: la pagina mostra l'esito e
 * le voci da riconfigurare, non il log tecnico (che sarebbe fatto di trattini), e dice che i dettagli si
 * vedono qui dopo l'accesso. Con il dettaglio tutto resta com'era.
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<div />' } }));
vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string, valori?: Record<string, unknown>) => (valori ? `${chiave} ${JSON.stringify(valori)}` : chiave),
}));

const PaginaEsito = (await import('./RestoreResult.vue')).default;

describe('RestoreResult', () => {
    test('Coda 343 — senza il dettaglio un ripristino completato mostra che cosa riconfigurare, e dove sono i dettagli', () => {
        const pagina = shallowMount(PaginaEsito, {
            props: {
                restore: {
                    phase: 'completed',
                    outcome: { cleanup: { two_factor_reset_users: 3, smtp_password_cleared: true }, app_key_adopted: true },
                    aborted: false,
                },
                dettaglio: false,
            },
        });
        const testo = pagina.text();

        expect(testo).toContain('impostazioni.restore_result.title_completed');
        expect(testo).toContain('impostazioni.restore_result.subtitle_completed');
        expect(testo).not.toContain('subtitle_completed_versioned');
        expect(testo).toContain('impostazioni.restore_result.reconfigure_2fa {"count":3}');
        expect(testo).toContain('impostazioni.restore_result.reconfigure_smtp');
        expect(testo).toContain('impostazioni.restore_result.reconfigure_backup_password');
        expect(pagina.find('[data-testid="dettagli-dopo-accesso"]').exists()).toBe(true);
    });

    test('Coda 343 — senza il dettaglio un ripristino fallito non mostra il log tecnico', () => {
        const pagina = shallowMount(PaginaEsito, {
            props: { restore: { phase: 'failed', outcome: {}, aborted: true }, dettaglio: false },
        });

        expect(pagina.text()).toContain('impostazioni.restore_result.title_failed');
        expect(pagina.find('details').exists()).toBe(false);
        expect(pagina.find('pre').exists()).toBe(false);
        expect(pagina.find('[data-testid="dettagli-dopo-accesso"]').exists()).toBe(true);
    });

    test('Coda 343 — con il dettaglio il log tecnico c\'è, con versione ed errore', () => {
        const pagina = shallowMount(PaginaEsito, {
            props: {
                restore: {
                    phase: 'failed',
                    outcome: {},
                    aborted: false,
                    uuid: 'r1',
                    manifest: { app: { version: '1.10.0' } },
                    error: 'Errore di prova',
                    failed_phase: 'finalizing',
                    failed_at: null,
                    app_version: '1.11.0',
                },
                dettaglio: true,
            },
        });
        const log = pagina.find('pre').text();

        expect(log).toContain('uuid: r1');
        expect(log).toContain('version: 1.11.0');
        expect(log).toContain('error: Errore di prova');
        expect(pagina.find('[data-testid="dettagli-dopo-accesso"]').exists()).toBe(false);
    });

    test('Coda 343 — con il dettaglio un ripristino completato dice la versione del backup', () => {
        const pagina = shallowMount(PaginaEsito, {
            props: {
                restore: { phase: 'completed', outcome: {}, uuid: 'r1', manifest: { app: { version: '1.10.0' } } },
                dettaglio: true,
            },
        });

        expect(pagina.text()).toContain('impostazioni.restore_result.subtitle_completed_versioned {"version":"1.10.0"}');
        expect(pagina.find('[data-testid="dettagli-dopo-accesso"]').exists()).toBe(false);
    });
});
