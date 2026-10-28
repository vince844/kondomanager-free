// @vitest-environment jsdom

/**
 * Le tre card sopra l'elenco utenti (Coda 398, decisione 141.3): totale, da verificare, sospesi.
 *
 * I numeri arrivano dal server e contano l'archivio intero: la card li mostra come sono, zero compreso. Come le card
 * degli eventi, un clic applica il filtro che la card racconta, e **sostituisce** quelli che la barra ha acceso: «da
 * verificare» mostra i non verificati, «sospesi» i sospesi, «totale» toglie ogni filtro. Chi clicca vede esattamente
 * le persone che il numero conta.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));

const UtentiStats = (await import('./UtentiStats.vue')).default;

const stats = { totale: 7, da_verificare: 3, sospesi: 2 };

function monta(valori = stats) {
    return mount(UtentiStats, { props: { stats: valori } });
}

/** Il numero e il titolo di una card, come li legge chi guarda. */
function card(pagina: ReturnType<typeof monta>, chiave: 'totale' | 'da_verificare' | 'sospesi') {
    return pagina.get(`[data-card="${chiave}"]`);
}

describe('UtentiStats — le tre card (Coda 398)', () => {
    test('mostrano il totale, i da verificare e i sospesi, con il titolo di ognuna', () => {
        const pagina = monta();

        expect(pagina.findAll('[data-card]')).toHaveLength(3);
        expect(card(pagina, 'totale').text()).toContain('users.stats.total');
        expect(card(pagina, 'totale').text()).toContain('7');
        expect(card(pagina, 'da_verificare').text()).toContain('users.stats.to_verify');
        expect(card(pagina, 'da_verificare').text()).toContain('3');
        expect(card(pagina, 'sospesi').text()).toContain('users.stats.suspended');
        expect(card(pagina, 'sospesi').text()).toContain('2');
    });

    test('con nessun sospeso e nessun account da verificare mostrano zero, non un vuoto', () => {
        const pagina = monta({ totale: 1, da_verificare: 0, sospesi: 0 });

        expect(card(pagina, 'da_verificare').find('span').text()).toBe('0');
        expect(card(pagina, 'sospesi').find('span').text()).toBe('0');
        expect(card(pagina, 'totale').find('span').text()).toBe('1');
    });

    test('ogni card dice con un suggerimento che cosa fa il clic', () => {
        const pagina = monta();

        expect(card(pagina, 'totale').attributes('title')).toBe('users.stats.total_hint');
        expect(card(pagina, 'da_verificare').attributes('title')).toBe('users.stats.to_verify_hint');
        expect(card(pagina, 'sospesi').attributes('title')).toBe('users.stats.suspended_hint');
    });
});

describe('UtentiStats — il clic applica il filtro della card (Coda 398, come EventiStats)', () => {
    test('«da verificare» chiede i non verificati e toglie ogni altro filtro', async () => {
        const pagina = monta();

        await card(pagina, 'da_verificare').trigger('click');

        expect(pagina.emitted('setFilter')).toEqual([[{ name: null, roles: null, stato: null, verifica: ['non_verificato'] }]]);
    });

    test('«sospesi» chiede lo stato sospeso e toglie ogni altro filtro', async () => {
        const pagina = monta();

        await card(pagina, 'sospesi').trigger('click');

        expect(pagina.emitted('setFilter')).toEqual([[{ name: null, roles: null, stato: ['sospeso'], verifica: null }]]);
    });

    test('«totale» non chiede nessun filtro: li toglie tutti', async () => {
        const pagina = monta();

        await card(pagina, 'totale').trigger('click');

        expect(pagina.emitted('setFilter')).toEqual([[{ name: null, roles: null, stato: null, verifica: null }]]);
    });

    test('un clic su una card non cambia ciò che manda il clic successivo', async () => {
        const pagina = monta();

        await card(pagina, 'sospesi').trigger('click');
        (pagina.emitted('setFilter')![0][0] as { stato: string[] }).stato.push('attivo');
        await card(pagina, 'sospesi').trigger('click');

        expect(pagina.emitted('setFilter')![1]).toEqual([{ name: null, roles: null, stato: ['sospeso'], verifica: null }]);
    });

    test('le card sono pulsanti: si raggiungono e si attivano anche da tastiera', () => {
        const pagina = monta();

        for (const chiave of ['totale', 'da_verificare', 'sospesi'] as const) {
            expect(card(pagina, chiave).element.tagName).toBe('BUTTON');
            expect(card(pagina, chiave).attributes('type')).toBe('button');
        }
    });
});
