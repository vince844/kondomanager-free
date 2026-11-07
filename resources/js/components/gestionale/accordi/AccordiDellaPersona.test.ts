// @vitest-environment jsdom

/**
 * Gli accordi di rientro nella pagina dell'estratto conto della persona: ci sono anche quando la persona non ha più rate scadute (l'accordo
 * riuscito, con i € 17,67 versati oltre il capitale riconosciuto), con il segno e l'autorizzazione, e si apre la finestra dell'accordo.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: vi.fn() } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, parametri: Record<string, unknown> = {}) =>
    `/${nome}?${new URLSearchParams(Object.entries(parametri).map(([k, v]) => [k, String(v)])).toString()}`;

const Sezione = (await import('./AccordiDellaPersona.vue')).default;

const riuscito = { id: 4, data_accordo: '2026-06-26', fino_al: '2026-12-15', riconosciuti: 150000, oggi: 0, invariato: false, fino_al_passato: true, versati_oltre: 1767,
    autorizzato_assemblea: false, data_autorizzazione: null, verbale_autorizzazione: null, nota: null, chiuso_il: null, nota_chiusura: null, in_corso: true };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; axiosGet.mockReset(); document.body.innerHTML = ''; });
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();

describe('gli accordi di rientro della persona', () => {
    test('l\'accordo riuscito si legge con i versati oltre il capitale, e la finestra si apre per la persona', async () => {
        axiosGet.mockResolvedValue({ data: { persona: { id: 7, nome: 'Giulia' }, data: '2026-12-16', oggi: '2026-12-16', rate: [], accordi: [] } });
        w = mount(Sezione, { attachTo: document.body, props: { condominioId: 3, persona: { id: 7, nome: 'Giulia' }, accordi: [riuscito], oggi: '2026-12-16' } });
        const testo = pulito(w.text());
        expect(testo).toContain('Accordi di rientro');
        expect(testo).toContain('Accordo di rientro del 26/06/2026, fino al 15/12/2026 (data passata): riconosciuti € 1.500,00, oggi € 0,00.');
        expect(testo).toContain('Versati € 17,67 oltre il capitale riconosciuto');
        expect(testo).toContain('Non autorizzato dall\'assemblea.');

        await w.get('[data-testid="apri-accordo"]').trigger('click');
        await flushPromises();
        expect(axiosGet).toHaveBeenCalledWith('/gestionale.accordi-rientro.index?condominio=3', { params: { anagrafica_id: 7, data: '2026-12-16' } });
        expect(pulito(document.body.textContent ?? '')).toContain('Accordo di rientro di Giulia');
    });

    test('un accordo chiuso dice quando, e senza accordi la sezione non c\'è', () => {
        w = mount(Sezione, { props: { condominioId: 3, persona: { id: 7, nome: 'Giulia' }, accordi: [{ ...riuscito, chiuso_il: '2026-11-04', nota_chiusura: 'Rate non versate', in_corso: false }], oggi: '2026-12-16' } });
        expect(pulito(w.text())).toContain('Chiuso il 04/11/2026: Rate non versate');
        w.unmount();
        w = mount(Sezione, { props: { condominioId: 3, persona: { id: 7, nome: 'Giulia' }, accordi: [], oggi: '2026-12-16' } });
        expect(w.find('[data-testid="accordi-della-persona"]').exists()).toBe(false);
    });
});

describe('la finestra dell\'accordo e le rate non ancora scadute', () => {
    test('la finestra dice che le rate non ancora scadute non entrano, e come aggiungerle quando scadono', async () => {
        axiosGet.mockResolvedValue({ data: { persona: { id: 7, nome: 'Giulia' }, data: '2026-06-26', oggi: '2026-06-26', rate: [], accordi: [] } });
        const Finestra = (await import('./AccordoRientroDialog.vue')).default;
        w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, persona: { id: 7, nome: 'Giulia' }, oggi: '2026-06-26' } });
        await flushPromises();
        expect(pulito(document.body.textContent ?? '')).toContain('Le rate non ancora scadute non entrano. Quando scadono, chiudi questo accordo e registrane uno nuovo con tutte le rate, con la data della chiusura.');
    });
});
