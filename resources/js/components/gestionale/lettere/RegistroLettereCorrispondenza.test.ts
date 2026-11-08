// @vitest-environment jsdom

/**
 * La scheda «Corrispondenza» (Coda 533) montata con lettere e documenti come li mandano `RegistroLettere::perPersona()` e
 * `CorrispondenzaDellaPersona::documenti()`: l'ordine misto, i filtri al clic (e la pagina che torna alla prima), le pagine da dieci, la frase
 * dei filtri senza risultati diversa dal vuoto vero, il collegamento alla pagina delle ricevute della persona, le ricevute senza azioni.
 *
 * Che cosa NON copre: il nome della scheda con il numero, che sta nella pagina (`EstrattoContoAnagraficaCorrispondenza.test.ts`); i dati del
 * server (`CorrispondenzaDellaPersonaTest.php`).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('axios', () => ({ default: { post: vi.fn(async () => ({ data: {} })) } }));
vi.mock('@inertiajs/vue3', () => ({ router: { reload: vi.fn(), post: vi.fn(), delete: vi.fn() }, usePage: () => ({ props: { errors: {} } }) }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, p: Record<string, unknown> = {}) => `/${nome}/${p.condominio ?? ''}${p.scrittura ? `/${p.scrittura}` : ''}`;
const { router } = await import('@inertiajs/vue3');

const RegistroLettere = (await import('./RegistroLettere.vue')).default;

const lettera = (chiave: string, tipo: string, emessa_il: string, id: number) => ({
    lettera: chiave, tipo, emessa_il, ora: '10:00', emesso_da: 'Anna Studio', totale: 18000, residuo_oggi: 18000, alla: emessa_il, termine: 15, stato: 'inviata',
    righe: [{ id, canale: 'email', formato: null, destinatario: 'ugo@x.it', stato: 'inviata', quando: `${emessa_il} 10:00`, errore: null, spedizione: null, spedibile: false, rimandabile: false }],
    pdf: null, prove: [], eventi: [],
});
const ricevuta = (id: number, emessa_il: string, altro: Record<string, unknown> = {}) => ({
    chiave: `ricevuta-${id}`, id, quando: `${emessa_il}T09:00:00+00:00`, tipo: 'ricevuta', non_da_comunicare: false, emessa_il, ora: '10:00', emesso_da: 'Anna Studio',
    numero: String(id), versamento_del: '2026-02-01', importo: 17000, annullato: false, copia_del: null, incasso_id: 500 + id, per_conto_di: null,
    righe: [{ id, canale: 'stampa', destinatario: null, stato: 'stampata', quando: `${emessa_il} 10:00`, errore: null }],
    ...altro,
});

let w: VueWrapper | null = null;
const monta = (lettere: unknown[], documenti: unknown[]) => {
    w = mount(RegistroLettere, { props: { condominioId: 3, lettere: lettere as any, documenti: documenti as any, oggi: '2026-03-26', personaId: 7 }, attachTo: document.body });

    return w;
};
afterEach(() => { w?.unmount(); w = null; });
const titoli = () => w!.findAll('article').map((a) => a.find('p').text().replace(/\s+/g, ' ').trim());

describe('la scheda «Corrispondenza»', () => {
    test('lettere e ricevute insieme, dalla più recente; la ricevuta si legge e basta: nessun pulsante', () => {
        monta([lettera('a', 'sollecito', '2026-03-05', 20)], [ricevuta(12, '2026-03-10'), ricevuta(11, '2026-03-01', { copia_del: '2026-02-20' })]);
        expect(titoli()).toEqual([
            'Ricevuta n. 12 del 10/03/2026 alle 10:00, da Anna Studio',
            'Sollecito del 05/03/2026 alle 10:00, da Anna Studio',
            'Ricevuta n. 11 del 01/03/2026 alle 10:00, da Anna Studio',
        ]);
        const documento = w!.findAll('[data-testid="documento"]')[1];
        expect(documento.text()).toContain('Versamento del 01/02/2026, € 170,00; copia della ricevuta del 20/02/2026.');
        expect(documento.text()).toContain('Stampata il 01/03/2026 alle 10:00');
        expect(documento.text()).not.toMatch(/spedizion/i);
        expect(documento.findAll('button')).toHaveLength(0);
        expect(w!.get('[data-testid="apri-ricevute"]').attributes('href')).toBe('/gestionale.ricevute.index/3?ricevuta=tutte&persone[]=7');
    });

    test('il filtro per tipo e per periodo; «Togli i filtri»; con i filtri senza risultati una frase diversa dal vuoto vero', async () => {
        monta([lettera('a', 'sollecito', '2026-03-05', 20), lettera('b', 'promemoria', '2026-02-05', 5)], [ricevuta(12, '2026-03-10')]);
        await w!.get('[data-testid="filtro-tipo"]').setValue('lettere');
        expect(titoli()).toEqual(['Sollecito del 05/03/2026 alle 10:00, da Anna Studio', 'Promemoria del 05/02/2026 alle 10:00, da Anna Studio']);
        await w!.get('[data-testid="filtro-dal"]').setValue('2026-03-01');
        expect(titoli()).toEqual(['Sollecito del 05/03/2026 alle 10:00, da Anna Studio']);
        await w!.get('[data-testid="filtro-tipo"]').setValue('diffida');
        expect(titoli()).toEqual([]);
        expect(w!.text()).toContain('Nessun documento con questi filtri.');
        expect(w!.text()).not.toContain('Nessuna lettera, ricevuta o avviso');
        await w!.get('[data-testid="togli-filtri"]').trigger('click');
        await flushPromises();
        expect(titoli()).toHaveLength(3);
        expect(w!.find('[data-testid="togli-filtri"]').exists()).toBe(false);
    });

    test('dieci per pagina: con undici voci la seconda pagina ne ha una; un filtro torna alla prima pagina', async () => {
        const ricevute = Array.from({ length: 11 }, (_, i) => ricevuta(100 + i, `2026-03-${String(i + 1).padStart(2, '0')}`));
        monta([], ricevute);
        expect(w!.findAll('[data-testid="documento"]')).toHaveLength(10);
        expect(w!.text()).toContain('Pagina 1 di 2');
        expect(w!.get('[data-testid="pagina-precedente"]').attributes('disabled')).toBeDefined();
        await w!.get('[data-testid="pagina-successiva"]').trigger('click');
        expect(titoli()).toEqual(['Ricevuta n. 100 del 01/03/2026 alle 10:00, da Anna Studio']);
        expect(w!.text()).toContain('Pagina 2 di 2');
        await w!.get('[data-testid="filtro-al"]').setValue('2026-03-05');
        await flushPromises();
        expect(w!.findAll('[data-testid="documento"]')).toHaveLength(5);
        expect(w!.find('[data-testid="pagine-corrispondenza"]').exists()).toBe(false);
        await w!.get('[data-testid="filtro-al"]').setValue('');
        await flushPromises();
        expect(w!.text()).toContain('Pagina 1 di 2');
    });

    test('con dieci voci o meno non ci sono le pagine', () => {
        monta([], Array.from({ length: 10 }, (_, i) => ricevuta(100 + i, '2026-03-01')));
        expect(w!.find('[data-testid="pagine-corrispondenza"]').exists()).toBe(false);
    });

    test('ogni ricevuta e ogni avviso porta al suo incasso, dove si ristampa e si rimanda; senza l\'incasso il collegamento non c\'è', () => {
        monta([], [ricevuta(12, '2026-03-10', { annullato: true }), ricevuta(13, '2026-03-09', { tipo: 'avviso_compensazione', chiave: 'avviso-13' }),
            ricevuta(14, '2026-03-08', { incasso_id: null })]);
        const voci = w!.findAll('[data-testid="documento"]');
        expect(voci[0].get('[data-testid="apri-incasso"]').text()).toBe('Apri l\'incasso n. 12');
        expect(voci[0].get('[data-testid="apri-incasso"]').attributes('href')).toBe('/gestionale.movimenti-rate.show/3/512');
        expect(voci[1].get('[data-testid="apri-incasso"]').attributes('href')).toBe('/gestionale.movimenti-rate.show/3/513');
        expect(voci[2].find('[data-testid="apri-incasso"]').exists()).toBe(false);
    });

    test('con «dal» dopo «al» la frase dice perché non trova niente', async () => {
        monta([], [ricevuta(12, '2026-03-10')]);
        await w!.get('[data-testid="filtro-dal"]').setValue('2026-04-01');
        await w!.get('[data-testid="filtro-al"]').setValue('2026-03-01');
        expect(w!.text()).toContain('«Dal» è dopo «al»: nessun giorno è in mezzo.');
        expect(w!.text()).not.toContain('Nessun documento con questi filtri.');
    });

    test('dopo un\'azione della scheda si ricaricano le lettere e l\'ultima lettera in testa', async () => {
        const conSpedizione = { ...lettera('a', 'diffida', '2026-03-05', 20), righe: [{ id: 20, canale: 'stampa', formato: null, destinatario: null, stato: 'stampata', quando: '2026-03-05 10:00',
            errore: null, spedizione: null, spedibile: true, rimandabile: false }] };
        monta([conSpedizione], []);
        await w!.findAll('button').find((b) => b.text() === 'Registra la spedizione')!.trigger('click');
        await w!.find('select:not([data-testid])').setValue('raccomandata');
        await w!.findAll('button').find((b) => b.text() === 'Registra')!.trigger('click');
        await flushPromises();
        expect(router.reload).toHaveBeenCalledWith({ only: ['lettere', 'ultima_lettera'] });
    });
});
