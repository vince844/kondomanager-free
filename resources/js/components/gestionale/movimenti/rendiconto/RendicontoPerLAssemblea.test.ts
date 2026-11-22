// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Il rendiconto per l'assemblea» nella Sintesi: senza versioni lo dice; «Ferma per l'assemblea» chiede conferma e manda le parti scelte del fascicolo;
 * le versioni con i loro documenti e lo stato; «Registra l'approvazione» su una versione presentata, con lo stato di riparto da dire (né sì né no
 * prima: il pulsante resta spento finché non si sceglie); la versione senza PDF offre le parti da conservare una per una e il PDF da caricare. Nessuna
 * delle parole che il rendiconto non dice di sé.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';

const post = vi.fn();
const elimina = vi.fn();
const reload = vi.fn();
// Le props della pagina: le assemblee di «Per quale assemblea» (vuote di serie).
const pagina: { props: Record<string, unknown> } = { props: {} };
vi.mock('@inertiajs/vue3', () => ({ router: { post: (...a: unknown[]) => post(...a), delete: (...a: unknown[]) => elimina(...a), reload: (...a: unknown[]) => reload(...a) },
    usePage: () => pagina }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, p: Record<string, unknown> = {}) =>
    `/${nome}/${p.condominio}/${p.esercizio}${p.versione ? `/${p.versione}` : ''}${p.documento ? `/${p.documento}` : ''}`;

const RendicontoPerLAssemblea = (await import('./RendicontoPerLAssemblea.vue')).default;

const NOMI = { riepilogo: 'Riepilogo finanziario', situazione: 'Situazione patrimoniale', conguaglio: 'Riparto del consuntivo e conguaglio' };
const versione = (extra: Record<string, unknown> = {}) => ({
    id: 4, numero: 1, stato: 'presentata', emessa_il: '2027-03-01 10:00', creata_da: 'Anna', data_delibera: null, numero_verbale: null, riparto_approvato: null,
    nota_approvazione: null, approvata_da: null, parti: ['riepilogo', 'situazione', 'conguaglio'], troppo: null, parti_conservate: [],
    documenti: [{ id: 31, parte: 'fascicolo', nome: 'rendiconto-via-verdi-2026-versione-1.pdf', impronta: 'abc' }], verbale: null, ...extra,
});
let w: VueWrapper | null = null;
const monta = (versioni: unknown[], extra: Record<string, unknown> = {}) => {
    w?.unmount();
    w = mount(RendicontoPerLAssemblea, {
        props: { condominioId: 3, esercizioId: 9, versioni, nomiParti: NOMI, oggi: '2027-03-15', scelte: ['riepilogo', 'conguaglio'], dettaglio: true, lettere: false, ...extra } as any,
    });

    return w;
};
beforeEach(() => { post.mockReset(); elimina.mockReset(); reload.mockReset(); pagina.props = {}; });

/** Il confronto di una versione con le cifre di oggi, come lo manda il server. */
const confrontoDi = (extra: Record<string, unknown> = {}) => ({
    versione_id: 4, numero: 1, stato: 'approvata', giorno: '15/03/2027', cambiato: true, frasi: ['nel giornale 1 registrazione nuova'],
    frase: 'Dopo l\'approvazione del 15/03/2027 sono cambiati i conti dell\'esercizio: nel giornale 1 registrazione nuova; le spese dell\'esercizio erano € 4.200,00, oggi sono € 4.260,00. Il rendiconto approvato resta quello del PDF.',
    scritture: [{ scrittura_id: 51, cosa: 'nuova', data: '2026-10-01', protocollo: 'CPM-3', causale: 'fattura' }],
    persone: [{ nome: 'Neri', unita: 'Int. U1', gestione: 'Ordinaria 2026', prima: 10000, oggi: 13000, saldo_prima: 10000, saldo_oggi: 13000 }], persone_cambiate: 1, ...extra,
});
afterEach(() => { w?.unmount(); w = null; });

describe('il rendiconto per l\'assemblea', () => {
    test('senza versioni il rendiconto è in bozza; «Ferma per l\'assemblea» chiede conferma e manda le parti scelte', async () => {
        const p = monta([]);
        expect(p.find('[data-test="nessuna-versione"]').text()).toContain('il rendiconto è in bozza');
        await p.find('[data-test="ferma"]').trigger('click');
        expect(p.find('[data-test="conferma-ferma"]').text()).toContain('Riepilogo finanziario, Riparto del consuntivo e conguaglio');
        await p.find('[data-test="ferma-conferma"]').trigger('click');
        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/gestionale.esercizi.rendiconto.versioni.store/3/9');
        expect(post.mock.calls[0][1]).toEqual({ parti: ['riepilogo', 'conguaglio'], dettaglio: true, lettere: false });
    });

    test('una versione presentata: chi l\'ha fermata e quando, il PDF del fascicolo, «Registra l\'approvazione»', () => {
        const v = monta([versione()]).find('[data-versione="1"]');
        // Il programma sa quando la versione è stata fermata, non quando è stata presentata all'assemblea.
        expect(v.text()).toContain('Versione 1 · Fermata il 01/03/2027 alle 10:00 da Anna');
        expect(v.text()).not.toContain('Presentata');
        expect(v.find('[data-documento="fascicolo"]').attributes('href')).toBe('/gestionale.esercizi.rendiconto.versioni.documento/3/9/4/31');
        expect(v.find('[data-test="apri-approvazione"]').exists()).toBe(true);
        expect(v.find('[data-test="riparto"]').exists()).toBe(false);
    });

    test('l\'approvazione: lo stato di riparto non è scelto prima, e senza la scelta non si registra; con la scelta parte con il file', async () => {
        const p = monta([versione()]);
        await p.find('[data-test="apri-approvazione"]').trigger('click');
        const radio = p.findAll('[data-test="riparto-scelta"] input[type="radio"]');
        expect(radio.map((r) => (r.element as HTMLInputElement).checked)).toEqual([false, false]);
        await p.find('[data-test="approvazione"] input[type="date"]').setValue('2027-03-15');
        expect(p.find('[data-test="registra-approvazione"]').attributes('disabled')).toBeDefined();
        await radio[1].setValue(true);
        await nextTick();
        expect(p.find('[data-test="registra-approvazione"]').attributes('disabled')).toBeUndefined();
        await p.find('[data-test="approvazione"]').trigger('submit');
        expect(post.mock.calls[0][0]).toBe('/gestionale.esercizi.rendiconto.versioni.approva/3/9/4');
        expect(post.mock.calls[0][1]).toEqual({ data_delibera: '2027-03-15', numero_verbale: '', riparto_approvato: '0', nota_approvazione: '' });
        expect(post.mock.calls[0][2]).toMatchObject({ forceFormData: true });
    });

    test('approvata e sostituita: la data, il verbale, lo stato di riparto detto', () => {
        const p = monta([
            versione({ id: 5, numero: 2, stato: 'approvata', data_delibera: '2027-03-15', numero_verbale: '3', riparto_approvato: false, verbale: { id: 40, nome: 'verbale.pdf' } }),
            versione({ stato: 'sostituita', data_delibera: '2027-03-15', numero_verbale: '2', riparto_approvato: true }),
        ]);
        expect(p.find('[data-versione="2"]').text()).toContain('Approvata il 15/03/2027, verbale n. 3');
        expect(p.find('[data-versione="2"] [data-test="riparto"]').text()).toBe('Anche lo stato di riparto: no, non approvato.');
        expect(p.find('[data-versione="2"] [data-documento="verbale"]').attributes('href')).toBe('/gestionale.esercizi.rendiconto.versioni.documento/3/9/5/40');
        expect(p.find('[data-versione="1"]').text()).toContain('poi sostituita da un\'approvazione successiva');
        expect(p.find('[data-versione="1"] [data-test="riparto"]').text()).toBe('Anche lo stato di riparto: sì, approvato.');
        expect(p.findAll('[data-test="apri-approvazione"]')).toHaveLength(0);
    });

    test('senza PDF: la frase del server, le parti ancora da conservare una per una, il PDF da caricare', async () => {
        const frase = 'Il PDF del fascicolo non è conservato: è troppo grande per farlo in una volta. Conserva le parti una per una, o carica il PDF che hai mandato.';
        const p = monta([versione({ documenti: [{ id: 32, parte: 'riepilogo', nome: 'r.pdf', impronta: 'x' }], parti_conservate: ['riepilogo'], troppo: frase })]);
        expect(p.find('[data-test="senza-pdf"]').text()).toContain(frase);
        expect(p.findAll('[data-conserva]').map((b) => b.attributes('data-conserva'))).toEqual(['situazione', 'conguaglio']);
        await p.find('[data-conserva="conguaglio"]').trigger('click');
        expect(post.mock.calls[0][0]).toBe('/gestionale.esercizi.rendiconto.versioni.parte/3/9/4');
        expect(post.mock.calls[0][1]).toEqual({ parte: 'conguaglio' });
        expect(p.find('[data-test="carica-pdf"]').exists()).toBe(true);
    });

    test('«Elimina la versione» solo per una fermata e mai approvata, con la conferma che dice che il PDF se ne va con lei', async () => {
        const p = monta([versione({ id: 5, numero: 2, stato: 'approvata', data_delibera: '2027-03-15', riparto_approvato: true }), versione()]);
        expect(p.find('[data-versione="2"] [data-test="apri-elimina"]').exists()).toBe(false);
        await p.find('[data-versione="1"] [data-test="apri-elimina"]').trigger('click');
        expect(p.find('[data-test="conferma-elimina"]').text()).toContain('Il PDF conservato se ne va con lei');
        expect(elimina).not.toHaveBeenCalled();
        await p.find('[data-test="elimina-conferma"]').trigger('click');
        expect(elimina.mock.calls[0][0]).toBe('/gestionale.esercizi.rendiconto.versioni.destroy/3/9/4');
    });

    test('le cifre fermate che non tornano: la frase del server sulla versione', () => {
        const frase = 'Le cifre del conguaglio fermate non tornano per «Pulizie»: …';
        expect(monta([versione({ cifre_non_tornano: frase })]).find('[data-test="cifre-non-tornano"]').text()).toBe(frase);
        expect(monta([versione()]).find('[data-test="cifre-non-tornano"]').exists()).toBe(false);
    });

    test('dopo la prima parte conservata, la forma che il server manda: la frase con le parti, le altre da conservare, il PDF da caricare', () => {
        const frase = 'Il PDF del fascicolo non è conservato intero: conservata 1 parte su 3. Conserva le altre una per una, o carica il PDF che hai mandato.';
        const p = monta([versione({ documenti: [{ id: 32, parte: 'riepilogo', nome: 'r.pdf', impronta: 'x' }], parti_conservate: ['riepilogo'], troppo: frase })]);
        expect(p.find('[data-test="senza-pdf"]').text()).toContain(frase);
        expect(p.findAll('[data-conserva]').map((b) => b.attributes('data-conserva'))).toEqual(['situazione', 'conguaglio']);
    });

    test('nessuna delle parole che il rendiconto non dice di sé', async () => {
        const p = monta([versione(), versione({ id: 5, numero: 2, stato: 'approvata', data_delibera: '2027-03-15', riparto_approvato: true, troppo: 'x' })]);
        await p.find('[data-test="ferma"]').trigger('click');
        await p.find('[data-test="apri-approvazione"]').trigger('click');
        const t = p.text().toLowerCase();
        for (const parola of ['pronto', 'conforme', 'corretto', 'approvabile', 'in regola', 'dovuto', 'esigibile', 'regolare']) {
            expect(t).not.toMatch(new RegExp(`(^|[^\\p{L}])${parola}($|[^\\p{L}])`, 'u'));
        }
    });

    test('dopo l\'approvazione: l\'avviso con le cifre, «Vedi che cosa è cambiato» con le registrazioni e le persone, «Ferma una versione nuova»; niente si blocca', async () => {
        const approvata = versione({ stato: 'approvata', data_delibera: '2027-03-15', numero_verbale: '2', riparto_approvato: true });
        const p = monta([approvata], { confronto: { automatico: true, fatto: true, approvata: confrontoDi(), presentate: [], giornale_cambiato: null } });
        expect(p.find('#confronto').exists()).toBe(true);
        expect(p.find('[data-test="confronto-frase"]').text()).toContain('le spese dell\'esercizio erano € 4.200,00, oggi sono € 4.260,00');
        expect(p.find('[data-test="elenco-cambiato"]').exists()).toBe(false);
        await p.find('[data-test="vedi-cambiato"]').trigger('click');
        const elenco = p.find('[data-test="elenco-cambiato"]');
        expect(elenco.find('[data-scrittura="51"]').text()).toContain('01/10/2026');
        expect(elenco.find('[data-scrittura="51"]').text()).toContain('nuova');
        expect(elenco.findAll('[data-test="persona-cambiata"] td').map((c) => c.text())).toEqual(['Neri', 'Int. U1', '€\u00a0100,00', '€\u00a0130,00', '€\u00a0100,00', '€\u00a0130,00']);
        await p.find('[data-test="ferma-nuova"]').trigger('click');
        expect(p.find('[data-test="conferma-ferma"]').exists()).toBe(true);
        expect(p.text()).not.toMatch(/errore|bloccat/i);
    });

    test('un incasso dopo l\'approvazione: il conguaglio della persona non cambia, il saldo finale sì; la tabella dice tutte e due, con le migliaia', async () => {
        const approvata = versione({ stato: 'approvata', data_delibera: '2027-03-15', numero_verbale: '2', riparto_approvato: true });
        const p = monta([approvata], { confronto: { automatico: true, fatto: true, presentate: [], giornale_cambiato: null, approvata: confrontoDi({
            persone: [{ nome: 'Rossi', unita: 'Int. 1', gestione: 'Ordinaria 2026', prima: -171612, oggi: -171612, saldo_prima: 28388, saldo_oggi: -31612 }] }) } });
        await p.find('[data-test="vedi-cambiato"]').trigger('click');
        const riga = p.find('[data-test="persona-cambiata"]');
        expect(riga.findAll('td').map((c) => c.text())).toEqual(['Rossi', 'Int. 1', '€\u00a0-1.716,12', '€\u00a0-1.716,12', '€\u00a0283,88', '€\u00a0-316,12']);
        // La coppia uguale è grigia e lo dice; quella cambiata no.
        expect(riga.findAll('[data-uguale="si"]')).toHaveLength(2);
        expect(riga.findAll('td')[2].attributes('data-uguale')).toBe('si');
        expect(riga.findAll('td')[4].attributes('data-uguale')).toBeUndefined();
        const intestazione = p.find('[data-test="tabella-persone"] thead').text();
        expect(intestazione).toContain('Conguaglio');
        expect(intestazione).toContain('Saldo finale');
    });

    test('«Registra l\'approvazione»: il verbale si sceglie con il campo del programma, in italiano, e parte con l\'approvazione', async () => {
        const p = monta([versione()]);
        await p.find('[data-test="apri-approvazione"]').trigger('click');
        const modulo = p.find('[data-test="approvazione"]');
        // Nessun campo del browser a vista: il suo pulsante parla la lingua del browser.
        expect(modulo.findAll('input[type="file"]').every((i) => i.classes().includes('hidden'))).toBe(true);
        expect(modulo.find('[data-testid="scegli-file"]').text()).toBe('Scegli il file');
        expect(modulo.find('[data-testid="nome-file"]').text()).toBe('Nessun file scelto');
        const campo = modulo.find('[data-testid="file-verbale-approvazione"]');
        expect(campo.attributes('accept')).toBe('application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png');
        const file = new File(['%PDF-1.4'], 'verbale.pdf', { type: 'application/pdf' });
        Object.defineProperty(campo.element, 'files', { value: [file] });
        await campo.trigger('change');
        expect(modulo.find('[data-testid="nome-file"]').text()).toBe('verbale.pdf');
        await modulo.find('input[type="date"]').setValue('2027-03-15');
        await modulo.findAll('[data-test="riparto-scelta"] input[type="radio"]')[0].setValue(true);
        await modulo.trigger('submit');
        expect(post.mock.calls[0][1]).toEqual({ data_delibera: '2027-03-15', numero_verbale: '', riparto_approvato: '1', nota_approvazione: '', verbale: file });
    });

    test('le cifre di oggi uguali a quelle approvate: lo dice; sopra la soglia di peso il confronto si chiede con un pulsante', async () => {
        const approvata = versione({ stato: 'approvata', data_delibera: '2027-03-15', numero_verbale: '2', riparto_approvato: true });
        expect(monta([approvata], { confronto: { automatico: true, fatto: true, approvata: confrontoDi({ cambiato: false, frase: null, frasi: [] }), presentate: [], giornale_cambiato: null } })
            .find('[data-test="confronto-uguale"]').text()).toContain('Dall\'approvazione del 15/03/2027 le cifre dell\'esercizio non sono cambiate.');
        const p = monta([approvata], { confronto: { automatico: false, fatto: false, approvata: null, presentate: [], giornale_cambiato: true } });
        expect(p.find('[data-test="confronto-a-richiesta"]').text()).toContain('il giornale dell\'esercizio è cambiato');
        // «Vedi che cosa è cambiato» in testa alle schede porta qui anche sopra la soglia.
        expect(p.find('[data-test="confronto-a-richiesta"]').attributes('id')).toBe('confronto');
        await p.find('[data-test="confronta"]').trigger('click');
        expect(reload).toHaveBeenCalledTimes(1);
        expect(reload.mock.calls[0][0]).toMatchObject({ data: { confronta: 1 }, only: ['confronto'] });
    });

    test('la finestra dell\'approvazione dice se le cifre sono cambiate dal giorno della versione, e offre di fermarne una nuova', async () => {
        const p = monta([versione()], { confronto: { automatico: true, fatto: true, approvata: null, giornale_cambiato: null,
            presentate: [confrontoDi({ stato: 'presentata', giorno: '01/03/2027', frase: 'Dal 01/03/2027, il giorno della versione 1, sono cambiati i conti dell\'esercizio: nel giornale 1 registrazione nuova.' })] } });
        await p.find('[data-test="apri-approvazione"]').trigger('click');
        expect(p.find('[data-test="approvazione-cambiato"]').text()).toContain('Dal 01/03/2027, il giorno della versione 1, sono cambiati i conti');
        expect(p.find('[data-test="registra-approvazione"]').exists()).toBe(true);
        await p.find('[data-test="approvazione-ferma-nuova"]').trigger('click');
        expect(p.find('[data-test="approvazione"]').exists()).toBe(false);
        expect(p.find('[data-test="conferma-ferma"]').exists()).toBe(true);
    });

    test('il file del PDF della versione tolto o cambiato: la frase e «Carica il PDF che hai mandato»', () => {
        const v = monta([versione({ pdf_frase: 'Il file del PDF «x.pdf» non c\'è più sul disco dei documenti. Carica il PDF che hai mandato ai condòmini.' })]).find('[data-test="pdf-frase"]');
        expect(v.text()).toContain('non c\'è più sul disco');
        expect(v.find('input[type="file"]').exists()).toBe(true);
    });

    test('sopra la soglia, una versione solo fermata: la finestra dell\'approvazione dice se il giornale è cambiato, che il confronto non si è fatto, e lo chiede', async () => {
        const p = monta([versione()], { confronto: { automatico: false, fatto: false, approvata: null, giornale_cambiato: null,
            presentate: [{ versione_id: 4, numero: 1, giornale_cambiato: true }] } });
        // Il pulsante del confronto c'è anche senza una versione approvata.
        expect(p.find('[data-test="confronta"]').text()).toContain('Confronta con la versione fermata');
        await p.find('[data-test="apri-approvazione"]').trigger('click');
        expect(p.find('[data-test="approvazione-giornale"]').text()).toContain('Dal giorno della versione 1 il giornale è cambiato');
        await p.find('[data-test="approvazione-confronta"]').trigger('click');
        expect(reload).toHaveBeenCalledTimes(1);
        expect(reload.mock.calls[0][0]).toMatchObject({ data: { confronta: 1 }, only: ['confronto'] });
        expect(p.find('[data-test="approvazione-giornale"]').text()).toContain('il confronto delle cifre non si fa da sé');
        // Il giornale uguale: un passaggio può cambiare lo stesso il conguaglio, e il confronto si chiede lo stesso.
        const q = monta([versione()], { confronto: { automatico: false, fatto: false, approvata: null, giornale_cambiato: null,
            presentate: [{ versione_id: 4, numero: 1, giornale_cambiato: false }] } });
        await q.find('[data-test="apri-approvazione"]').trigger('click');
        expect(q.find('[data-test="approvazione-giornale"]').text()).toContain('Dal giorno della versione 1 il giornale non è cambiato; un passaggio');
        expect(q.find('[data-test="approvazione-giornale"]').text()).toContain('il confronto delle cifre non si fa da sé');
        expect(q.find('[data-test="approvazione-confronta"]').exists()).toBe(true);
        // Con il confronto fatto e niente di cambiato: niente da dire.
        const r = monta([versione()], { confronto: { automatico: false, fatto: true, approvata: null, giornale_cambiato: null,
            presentate: [confrontoDi({ stato: 'presentata', giorno: '01/03/2027', cambiato: false, frase: null })] } });
        await r.find('[data-test="apri-approvazione"]').trigger('click');
        expect(r.find('[data-test="approvazione-giornale"]').exists()).toBe(false);
        expect(r.find('[data-test="approvazione-cambiato"]').exists()).toBe(false);
    });
});

describe('«Ferma per l\'assemblea» collegato al punto del rendiconto', () => {
    test('con un\'assemblea in preparazione chiede «Per quale assemblea», di serie la prima, e la manda; senza, non chiede', async () => {
        pagina.props = { assemblee_del_rendiconto: [{ id: 21, punto_id: 70, nome: 'Assemblea ordinaria del 15/12/2026, punto 1' }] };
        const w = monta([]);
        await w.get('[data-test="ferma"]').trigger('click');
        expect(w.find('[data-test="per-quale-assemblea"]').exists()).toBe(true);
        expect(w.text()).toContain('Per quale assemblea');
        await w.get('[data-test="ferma-conferma"]').trigger('click');
        expect(post.mock.calls[0][1]).toMatchObject({ assemblea_id: 21 });

        pagina.props = {};
        const w2 = monta([]);
        await w2.get('[data-test="ferma"]').trigger('click');
        expect(w2.find('[data-test="per-quale-assemblea"]').exists()).toBe(false);
    });

    test('la versione allegata a una convocazione spedita dice dove è, e non offre «Elimina la versione»', () => {
        const w = monta([versione({ in_assemblea: { frase: 'In assemblea il 15/12, punto 1 · convocazione spedita il 03/12', spedita: true } })]);
        expect(w.get('[data-test="in-assemblea"]').text()).toBe('In assemblea il 15/12, punto 1 · convocazione spedita il 03/12');
        expect(w.find('[data-test="apri-elimina"]').exists()).toBe(false);
        const w2 = monta([versione({ in_assemblea: { frase: 'In assemblea il 15/12, punto 1 · convocazione non ancora spedita', spedita: false } })]);
        expect(w2.find('[data-test="apri-elimina"]').exists()).toBe(true);
    });
});
