// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * I due componenti della conservazione (1.12.0-beta.13, §11 e decisione 550), montati nelle pagine del registro:
 *  - `ConservazioneDelRegistro`: carica da sé lo stato; nella forma «scelta» il menu 10 / 15 / 20 anni con «Adesso: …» e «Salva la durata» solo se la
 *    scelta cambia (all'amministratore; agli altri il menu spento e la frase); nella forma «riquadro» la durata, la frase e la sospensione del condominio.
 *  - `SospensioneDellaCancellazione`: «Sospendi la cancellazione» con il motivo obbligatorio; da sospesa, «Cancellazione sospesa» con lo stato a parole e
 *    «Togli la sospensione», anche lei con il motivo; a chi non è amministratore, solo lo stato.
 * Le regole delle pagine: nessuna scritta sotto i 12 pixel, nessuna pillola, nessun bordo spesso a sinistra, nessun `<select>`, nessun `v-html`.
 *
 * Che cosa non copre: le rotte (le prova `ConservazioneESospensioneTest.php`); il menu aperto a video e il tema scuro; il montaggio nelle pagine del
 * registro (lo prova `RegistroDelleModifiche.test.ts`).
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import vSelect from 'vue-select';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const rete = vi.hoisted(() => ({
    get: vi.fn(),
    put: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
}));
vi.mock('axios', () => ({ default: rete }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => `admin.${nome}`, hasPermission: () => true }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, p?: Record<string, unknown>) => `/${nome}${p && Object.keys(p).length ? '?' + new URLSearchParams(p as Record<string, string>).toString() : ''}`;

const Conservazione = (await import('./ConservazioneDelRegistro.vue')).default;
const Sospensione = (await import('./SospensioneDellaCancellazione.vue')).default;

const STATO = { anni: 10, scelte: [10, 15, 20], adesso: '10 anni', frase: 'le righe del 2026 si tolgono da sole nel 2036', puo_cambiare: true };

let w: VueWrapper | null = null;
beforeEach(() => { Object.values(rete).forEach((f) => f.mockReset()); });
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

const testo = () => document.body.textContent?.replace(/\s+/g, ' ') ?? '';

describe('la durata delle righe', () => {
    test('nella forma «scelta»: la carta della bozza, il menu su 10 anni, «Adesso: 10 anni», «Salva la durata» spento finché la scelta non cambia', async () => {
        rete.get.mockResolvedValue({ data: STATO });
        rete.put.mockResolvedValue({ data: { ...STATO, anni: 15, adesso: '15 anni', frase: 'le righe del 2026 si tolgono da sole nel 2041' } });
        w = mount(Conservazione, { attachTo: document.body });
        await flushPromises();

        expect(rete.get).toHaveBeenCalledWith('/admin.registro-modifiche.conservazione.mostra');
        expect(w.text()).toContain('Il registro delle modifiche');
        expect(w.text()).toContain('Per quanto tempo si tengono le righe');
        expect(w.find('[data-testid="adesso"]').text()).toContain('Adesso: 10 anni.');
        expect(w.find('[data-testid="salva-durata"]').attributes('disabled')).toBeDefined();

        w.findComponent(vSelect).vm.$emit('update:modelValue', 15);
        await flushPromises();
        await w.find('[data-testid="salva-durata"]').trigger('click');
        await flushPromises();

        expect(rete.put).toHaveBeenCalledWith('/admin.registro-modifiche.conservazione.cambia', { anni: 15 });
        expect(w.find('[data-testid="salvato"]').text()).toBe('Salvato: le righe si tengono 15 anni.');
        expect(w.find('[data-testid="adesso"]').text()).toContain('Adesso: 15 anni.');
    });

    test('a chi non è amministratore il menu è spento, senza «Salva», e lo dice', async () => {
        rete.get.mockResolvedValue({ data: { ...STATO, puo_cambiare: false } });
        w = mount(Conservazione, { attachTo: document.body });
        await flushPromises();

        expect(w.findComponent(vSelect).props('disabled')).toBe(true);
        expect(w.find('[data-testid="salva-durata"]').exists()).toBe(false);
        expect(w.text()).toContain('La durata la sceglie l\'amministratore.');
    });

    test('nella forma «riquadro» con il condominio: la durata, la frase e la cancellazione ferma per tutto lo studio (decisione 558)', async () => {
        rete.get.mockResolvedValue({ data: { ...STATO, sospensione: { dal: '12/03/2035', motivo: 'causa Rossi' },
            sospensioni: [{ condominio_id: 4, nome: 'Via Roma 12', dal: '12/03/2035', motivo: 'causa Rossi', da: 'Andrea Costa', eliminato: false }] } });
        w = mount(Conservazione, { props: { forma: 'riquadro', condominioId: 4 }, attachTo: document.body });
        await flushPromises();

        expect(rete.get).toHaveBeenCalledWith('/admin.registro-modifiche.conservazione.mostra?condominio=4');
        expect(w.find('.text-xl').text()).toBe('10 anni');
        expect(w.text()).toContain('le righe del 2026 si tolgono da sole nel 2036');
        expect(w.find('[data-testid="conservazione-sospesa"]').text())
            .toBe('La cancellazione è ferma per tutto lo studio finché è sospesa Via Roma 12 (dal 12/03/2035: causa Rossi).');
    });

    test('nella forma «scelta» la riga «Adesso» dice che la cancellazione è ferma per tutto lo studio (decisione 558)', async () => {
        rete.get.mockImplementation((url: string) => Promise.resolve({ data: url.includes('eliminati') ? { sospensioni: [] }
            : { ...STATO, sospensioni: [{ condominio_id: 4, nome: 'Via Roma 12', dal: '03/03/2035', motivo: 'causa Rossi', da: 'Andrea Costa', eliminato: false }] } }));
        w = mount(Conservazione, { attachTo: document.body });
        await flushPromises();

        expect(w.find('[data-testid="adesso"]').text())
            .toContain('Adesso: 10 anni. La cancellazione è ferma per tutto lo studio finché è sospesa Via Roma 12 (dal 03/03/2035: causa Rossi).');
    });

    test('nella forma «scelta», all\'amministratore, i condomini eliminati con la cancellazione sospesa e «Togli la sospensione» con il motivo', async () => {
        const eliminato = { condominio_id: 9, nome: 'Via Garibaldi 5', dal: '01/02/2035', motivo: 'causa Neri', da: 'Andrea Costa', eliminato: true };
        rete.get.mockImplementation((url: string) => Promise.resolve({ data: url.includes('eliminati') ? { sospensioni: [eliminato] }
            : { ...STATO, sospensioni: [eliminato] } }));
        rete.delete.mockResolvedValue({ data: { sospensioni: [] } });
        w = mount(Conservazione, { attachTo: document.body });
        await flushPromises();

        expect(rete.get).toHaveBeenCalledWith('/admin.registro-modifiche.sospensioni.eliminati');
        const riga = w.find('[data-testid="eliminato-9"]');
        expect(riga.text()).toContain('Via Garibaldi 5');
        expect(riga.text()).toContain('sospesa dal 01/02/2035 da Andrea Costa: causa Neri');

        await riga.find('button').trigger('click');
        await flushPromises();
        document.querySelector<HTMLFormElement>('form')!.dispatchEvent(new Event('submit'));
        await flushPromises();
        expect(testo()).toContain('Scrivi perché togli la sospensione.');
        expect(rete.delete).not.toHaveBeenCalled();

        const campo = document.querySelector<HTMLTextAreaElement>('[data-testid="motivo-eliminato"]')!;
        campo.value = 'causa chiusa';
        campo.dispatchEvent(new Event('input'));
        await flushPromises();
        document.querySelector<HTMLFormElement>('form')!.dispatchEvent(new Event('submit'));
        await flushPromises();

        expect(rete.delete).toHaveBeenCalledWith('/admin.registro-modifiche.sospensioni.togli?condominio_id=9', { data: { motivo: 'causa chiusa' } });
        expect(w.find('[data-testid="eliminato-9"]').exists()).toBe(false);
    });

    test('a chi non è amministratore l\'elenco dei condomini eliminati non si chiede', async () => {
        rete.get.mockResolvedValue({ data: { ...STATO, puo_cambiare: false } });
        w = mount(Conservazione, { attachTo: document.body });
        await flushPromises();

        expect(rete.get).toHaveBeenCalledTimes(1);
    });

    test('se lo stato non arriva, lo dice e c\'è «Riprova»', async () => {
        rete.get.mockRejectedValueOnce({ response: { status: 500 } }).mockResolvedValue({ data: STATO });
        w = mount(Conservazione, { attachTo: document.body });
        await flushPromises();

        expect(w.find('[data-testid="conservazione-errore"]').text()).toContain('Non è andata: riprova fra poco.');
        await w.find('[data-testid="conservazione-errore"] button').trigger('click');
        await flushPromises();
        expect(w.find('[data-testid="adesso"]').exists()).toBe(true);
    });
});

describe('la sospensione della cancellazione', () => {
    const SOSPESA = { sospesa: true, dal: '12/03/2035', motivo: 'causa Rossi, tribunale di Napoli', da: 'Andrea Costa', puo_cambiare: true };
    const NON_SOSPESA = { sospesa: false, dal: null, motivo: null, da: null, puo_cambiare: true };

    test('«Sospendi la cancellazione» chiede il motivo, poi sospende e il pulsante dice «Cancellazione sospesa»', async () => {
        rete.get.mockResolvedValue({ data: NON_SOSPESA });
        rete.post.mockResolvedValue({ data: SOSPESA });
        w = mount(Sospensione, { props: { condominioId: 4, condominioNome: 'Via Roma 12' }, attachTo: document.body });
        await flushPromises();

        expect(rete.get).toHaveBeenCalledWith('/admin.gestionale.registro-modifiche.sospensione.mostra?condominio=4');
        await w.find('[data-testid="sospendi"]').trigger('click');
        await flushPromises();
        expect(testo()).toContain('Le righe del registro di Via Roma 12 restano oltre la durata finché non togli la sospensione');

        document.querySelector<HTMLFormElement>('form')!.dispatchEvent(new Event('submit'));
        await flushPromises();
        expect(testo()).toContain('Scrivi perché sospendi la cancellazione.');
        expect(rete.post).not.toHaveBeenCalled();

        const campo = document.querySelector<HTMLTextAreaElement>('[data-testid="motivo"]')!;
        campo.value = 'causa Rossi, tribunale di Napoli';
        campo.dispatchEvent(new Event('input'));
        await flushPromises();
        document.querySelector<HTMLFormElement>('form')!.dispatchEvent(new Event('submit'));
        await flushPromises();

        expect(rete.post).toHaveBeenCalledWith('/admin.gestionale.registro-modifiche.sospensione.sospendi?condominio=4', { motivo: 'causa Rossi, tribunale di Napoli' });
        expect(w.find('[data-testid="sospesa"]').text()).toBe('Cancellazione sospesa');
        expect(w.emitted('cambiata')).toHaveLength(1);
    });

    test('da sospesa: lo stato a parole, chi l\'ha sospesa, e «Togli la sospensione» con il motivo', async () => {
        rete.get.mockResolvedValue({ data: SOSPESA });
        rete.delete.mockResolvedValue({ data: NON_SOSPESA });
        w = mount(Sospensione, { props: { condominioId: 4 }, attachTo: document.body });
        await flushPromises();

        await w.find('[data-testid="sospesa"]').trigger('click');
        await flushPromises();
        expect(document.querySelector('[data-testid="stato"]')!.textContent!.replace(/\s+/g, ' ').trim())
            .toBe('Cancellazione sospesa dal 12/03/2035: causa Rossi, tribunale di Napoli. Sospesa da Andrea Costa.');
        expect(document.querySelector('[data-testid="conferma"]')!.textContent).toContain('Togli la sospensione');

        const campo = document.querySelector<HTMLTextAreaElement>('[data-testid="motivo"]')!;
        campo.value = 'causa chiusa';
        campo.dispatchEvent(new Event('input'));
        await flushPromises();
        document.querySelector<HTMLFormElement>('form')!.dispatchEvent(new Event('submit'));
        await flushPromises();

        expect(rete.delete).toHaveBeenCalledWith('/admin.gestionale.registro-modifiche.sospensione.togli?condominio=4', { data: { motivo: 'causa chiusa' } });
        expect(w.find('[data-testid="sospendi"]').exists()).toBe(true);
    });

    test('il messaggio del server, se rifiuta', async () => {
        rete.get.mockResolvedValue({ data: NON_SOSPESA });
        rete.post.mockRejectedValue({ response: { status: 422, data: { errors: { motivo: ['La cancellazione è già sospesa dal 12/03/2035.'] } } } });
        w = mount(Sospensione, { props: { condominioId: 4 }, attachTo: document.body });
        await flushPromises();
        await w.find('[data-testid="sospendi"]').trigger('click');
        await flushPromises();
        const campo = document.querySelector<HTMLTextAreaElement>('[data-testid="motivo"]')!;
        campo.value = 'di nuovo';
        campo.dispatchEvent(new Event('input'));
        await flushPromises();
        document.querySelector<HTMLFormElement>('form')!.dispatchEvent(new Event('submit'));
        await flushPromises();

        expect(testo()).toContain('La cancellazione è già sospesa dal 12/03/2035.');
    });

    test('a chi non è amministratore: nessun pulsante se non è sospesa; da sospesa lo stato con «Chiudi», senza «Togli»', async () => {
        rete.get.mockResolvedValue({ data: { ...NON_SOSPESA, puo_cambiare: false } });
        w = mount(Sospensione, { props: { condominioId: 4 }, attachTo: document.body });
        await flushPromises();
        expect(w.find('button').exists()).toBe(false);
        w.unmount();

        rete.get.mockResolvedValue({ data: { ...SOSPESA, puo_cambiare: false } });
        w = mount(Sospensione, { props: { condominioId: 4 }, attachTo: document.body });
        await flushPromises();
        await w.find('[data-testid="sospesa"]').trigger('click');
        await flushPromises();
        expect(document.querySelector('[data-testid="conferma"]')).toBeNull();
        expect(document.querySelector('[data-testid="motivo"]')).toBeNull();
        expect(testo()).toContain('Chiudi');
    });
});

describe('le regole delle pagine', () => {
    test.each(['ConservazioneDelRegistro.vue', 'SospensioneDellaCancellazione.vue'])('%s: niente scritte sotto i 12 pixel, pillole, bordi spessi a sinistra, <select>, v-html', (file) => {
        const sorgente = readFileSync(resolve(__dirname, file), 'utf8');
        expect(sorgente).not.toMatch(/text-\[(\d|10|11)px\]/);
        expect(sorgente).not.toContain('rounded-full');
        expect(sorgente).not.toMatch(/border-l-[2-8]/);
        expect(sorgente).not.toContain('<select');
        expect(sorgente).not.toContain('v-html');
    });
});
