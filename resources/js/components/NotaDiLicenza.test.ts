// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La riga della licenza (Coda 493, decisione 686): le parole della decisione, i due collegamenti giusti e «Note legali» come pulsante.
 * Insieme, una guardia sulle pagine: ognuna usa il componente e nessuna tiene più la vecchia nota «Powered by … (AGPL-3.0)».
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const pagina = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => pagina,
}));

import NotaDiLicenza from './NotaDiLicenza.vue';

afterEach(() => {
    pagina.props = {};
    document.body.innerHTML = '';
});

/** La finestra sta in un portale sul body: si apre con il clic e si legge da lì. */
async function apriLeNote() {
    const riga = mount(NotaDiLicenza, { attachTo: document.body });
    await riga.get('[data-note-legali]').trigger('click');
    await new Promise((r) => setTimeout(r, 0));

    return riga;
}
const finestra = () => document.body.querySelector('[data-note-legali-finestra]') as HTMLElement;
const gruppo = (nome: string) => (finestra().querySelector(`[data-gruppo="${nome}"]`) as HTMLElement).textContent!.replace(/\s+/g, ' ').trim();

describe('NotaDiLicenza', () => {
    test('la riga dice le parole della decisione 686', () => {
        const riga = mount(NotaDiLicenza);

        // Le parti si separano con un «·» che la riga mette fra una e l'altra (con lo spazio del contenitore, non nel testo).
        const parti = riga.findAll('span, a, button').map((e) => e.text()).filter((t) => t !== '·');

        expect(parti.join(' · ')).toBe('© Vincenzo Vecchio — KondoManager · Licenza GNU AGPL-3.0 · Note legali · Codice sorgente');
    });

    test('la licenza va al testo della GNU AGPL e il codice sorgente al repository giusto', () => {
        const href = mount(NotaDiLicenza).findAll('a').map((a) => a.attributes('href'));

        expect(href).toEqual(['https://www.gnu.org/licenses/agpl-3.0.html', 'https://github.com/vince844/kondomanager-free']);
    });

    test('«Note legali» è un pulsante e lo stato si apre al clic', async () => {
        const riga = mount(NotaDiLicenza, { attachTo: document.body });

        expect(riga.get('[data-note-legali]').element.tagName).toBe('BUTTON');
        await riga.get('[data-note-legali]').trigger('click');
        expect((riga.vm as unknown as { noteAperte: boolean }).noteAperte).toBe(true);
    });
});

describe('la finestra «Note legali» (decisioni 686 e 687)', () => {
    test('chiusa non c\'è; il clic su «Note legali» la apre con le quattro cose', async () => {
        const riga = mount(NotaDiLicenza, { attachTo: document.body });
        expect(finestra()).toBeNull();
        riga.unmount();

        const aperta = await apriLeNote();
        expect(finestra()).not.toBeNull();
        expect(gruppo('copyright')).toBe('Copyright© Vincenzo Vecchio — KondoManager.');
        expect(gruppo('licenza')).toContain('si può ridistribuire e modificare secondo i termini della GNU Affero General Public License, versione 3');
        expect(gruppo('garanzia')).toContain('senza alcuna garanzia');
        expect(gruppo('lettura')).toContain('gnu.org/licenses/agpl-3.0');
        const href = Array.from(finestra().querySelectorAll('a')).map((a) => a.getAttribute('href'));
        expect(href).toEqual(['https://www.gnu.org/licenses/agpl-3.0.html', 'https://github.com/vince844/kondomanager-free']);
        aperta.unmount();
    });

    test('senza l\'impostazione dei termini del servizio la garanzia dice solo «senza alcuna garanzia»', async () => {
        pagina.props = { termini_del_servizio_url: null };
        const aperta = await apriLeNote();

        expect(gruppo('garanzia')).toContain('È fornito senza alcuna garanzia, nemmeno');
        expect(gruppo('garanzia')).not.toContain('salvo');
        expect(finestra().querySelector('[data-termini]')).toBeNull();
        aperta.unmount();
    });

    test('con l\'impostazione dei termini la garanzia dice «salvo quelle dei termini del servizio» con il collegamento', async () => {
        pagina.props = { termini_del_servizio_url: 'https://esempio.test/termini' };
        const aperta = await apriLeNote();

        expect(gruppo('garanzia')).toBe('Garanzia È fornito senza alcuna garanzia, salvo quelle dei termini del servizio.');
        expect(finestra().querySelector('[data-termini]')!.getAttribute('href')).toBe('https://esempio.test/termini');
        aperta.unmount();
    });

    test('il sottotitolo porta la versione solo se la pagina la dà, e «Chiudi» chiude la finestra', async () => {
        pagina.props = { version: '1.12.0-beta.14' };
        const aperta = await apriLeNote();

        expect(finestra().textContent).toContain('KondoManager, versione 1.12.0-beta.14');
        (finestra().querySelector('[data-chiudi]') as HTMLElement).click();
        await new Promise((r) => setTimeout(r, 0));
        expect(finestra()).toBeNull();
        aperta.unmount();
    });
});

describe('le pagine che mostrano la nota', () => {
    const radice = resolve(__dirname, '..');
    const file = [
        'layouts/auth/AuthCardLayout.vue',
        'pages/Welcome.vue', 'components/Footer.vue', 'layouts/app/AppSidebarLayout.vue',
        // Le pagine dell'aggiornamento e l'esito del ripristino non hanno layout: la nota è nella loro carta.
        // `system/upgrade/Launch.vue` no: è il passaggio di un secondo e mezzo che invia da solo il modulo all'installatore, senza un piè di pagina.
        'pages/system/upgrade/Index.vue', 'pages/system/upgrade/Confirm.vue', 'pages/system/upgrade/Changelog.vue',
        'pages/system/upgrade/Disabled.vue', 'pages/impostazioni/RestoreResult.vue',
    ];

    test.each(file)('%s monta NotaDiLicenza e non ha la vecchia nota', (percorso) => {
        const sorgente = readFileSync(resolve(radice, percorso), 'utf8');

        expect(sorgente).toContain('<NotaDiLicenza');
        expect(sorgente).not.toContain('Powered by');
        expect(sorgente).not.toContain('github.com/KondoManager');
    });
});

describe('le pagine d\'accesso', () => {
    const radice = resolve(__dirname, '..');
    const pagine = readdirSync(resolve(radice, 'pages/auth')).filter((f) => f.endsWith('.vue'));

    test('ci sono tutte e nove, e nessuna ha un layout suo', () => {
        expect(pagine.length).toBe(9);
    });

    test.each(pagine)('pages/auth/%s prende la nota dal layout d\'accesso e non la monta due volte', (nome) => {
        const sorgente = readFileSync(resolve(radice, 'pages/auth', nome), 'utf8');

        expect(sorgente).toContain("@/layouts/AuthLayout.vue");
        expect(sorgente).not.toContain('<NotaDiLicenza');
    });
});

describe('i collegamenti esterni si aprono in una scheda nuova', () => {
    const tutti = (radice: ParentNode) => Array.from(radice.querySelectorAll('a[href]')) as HTMLAnchorElement[];

    test('la riga e la finestra «Note legali» (con i termini del servizio): ogni collegamento ha target _blank e rel con noopener', async () => {
        pagina.props = { termini_del_servizio_url: 'https://example.test/termini' };
        const riga = await apriLeNote();
        const link = [...tutti(riga.element), ...tutti(finestra())];

        // la riga: licenza e codice; la finestra: i termini, gnu.org e GitHub
        expect(link.length).toBeGreaterThanOrEqual(5);
        for (const a of link) {
            expect(a.getAttribute('target'), a.href).toBe('_blank');
            expect(a.getAttribute('rel'), a.href).toContain('noopener');
        }
        // «Note legali» resta il pulsante che apre la finestra
        expect(riga.get('[data-note-legali]').element.tagName).toBe('BUTTON');
    });

    test('l\'installatore: i collegamenti della nota hanno target _blank e rel con noopener, «Note legali» è un pulsante', () => {
        const blade = readFileSync(resolve(__dirname, '../../views/installer/installer-wizard.blade.php'), 'utf8');
        const link = blade.match(/<a [^>]*href="https:\/\/(?:www\.gnu\.org|github\.com)[^>]*>/g) ?? [];

        expect(link).toHaveLength(2);
        for (const a of link) {
            expect(a).toContain('target="_blank"');
            expect(a).toMatch(/rel="[^"]*noopener/);
        }
    });
});
