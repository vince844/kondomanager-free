// @vitest-environment jsdom

/**
 * Il pannello della fattura di un debito ereditato, quando il pagamento porterebbe il conto in negativo: il consiglio non dice «morosi» e porta
 * all'elenco delle rate scadute del condominio. Letto montato.
 */
import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as any).route = (nome: string, p?: Record<string, unknown>) => `/${nome}/${Object.values(p ?? {}).join('/')}`;

const WidgetDoubleLock = (await import('./WidgetDoubleLock.vue')).default;

const proprieta = (extra: Record<string, unknown> = {}) => ({
    form: { saldo_patrimoniale_id: 5, data_competenza_originaria: null }, fornitoreId: 1,
    debitiPatrimoniali: [{ id: 5, fornitore_id: 1, importo_disponibile: 100000, descrizione: 'Debito 2025' }],
    contiSpesa: [], fondiRiserva: [], capienzaRataZero: 100000, incassatoRataZero: 0, totaleFatturaLordoCents: 50000,
    bankForecast: { attuale_cents: 10000, post_cents: -40000, isRed: true }, fatturePregresseRegistrate: [], condominioId: 7, ...extra,
});

describe('il consiglio quando il conto andrebbe in negativo', () => {
    test('non dice «morosi» e porta alle rate scadute del condominio', () => {
        const w = mount(WidgetDoubleLock, { props: proprieta() as any, global: { stubs: { vSelect: true, 'v-select': true } } });
        const testo = w.text().replace(/\s+/g, ' ');
        expect(testo).toContain('di scrivere a chi ha rate scadute');
        expect(testo.toLowerCase()).not.toContain('moros');
        const link = w.get('[data-testid="vedi-rate-scadute"]');
        expect(link.text()).toBe('Vedi le rate scadute');
        expect(link.attributes('href')).toBe('/gestionale.rate-scadute.index/7');
    });

    test('senza il condominio la frase resta, il collegamento no', () => {
        const w = mount(WidgetDoubleLock, { props: proprieta({ condominioId: undefined }) as any, global: { stubs: { vSelect: true, 'v-select': true } } });
        expect(w.text()).toContain('di scrivere a chi ha rate scadute');
        expect(w.find('[data-testid="vedi-rate-scadute"]').exists()).toBe(false);
    });
});
