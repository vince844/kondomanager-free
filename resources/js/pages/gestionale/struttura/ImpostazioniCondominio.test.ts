// @vitest-environment jsdom

/**
 * La scheda «Impostazioni» del condominio: la descrizione sotto il titolo, le tre card, «Guida» nell'intestazione con il cassetto; la sezione «Il recupero dei
 * crediti» con la sua descrizione e due righe con l'interruttore, come le impostazioni generali: a sinistra il titolo (una frase che si accende), la «i» con un
 * esempio e la spiegazione, a destra l'interruttore; sotto ogni riga «Adesso: …», che cambia appena si muove l'interruttore, prima di salvare, e dice «di serie»
 * solo lì. «Salva» manda le due scelte; chi non modifica il condominio vede gli interruttori ma non li muove. Le parole sono «la prossima azione», mai «il passo».
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

const post = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: (...a: unknown[]) => post(...a), on: () => () => undefined },
    usePage: () => ({ props: { flash: {} } }),
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome, generatePath: (p: string) => `/${p}` }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, p?: unknown) => `/${nome}${p ? `/${JSON.stringify(p)}` : ''}`;

const Pagina = (await import('./ImpostazioniCondominio.vue')).default;

const passa = { template: '<div><slot /></div>' };
let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; post.mockReset(); });
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();

function monta(impostazioni = { recupero_salita: 'avanti', recupero_suggerisce_azione: true }, puo = true) {
    w = mount(Pagina, {
        props: { condominio: { id: 16, nome: 'Via Verdi 12' }, condomini: [], esercizio: null, impostazioni, puo_modificare: puo } as never,
        global: { stubs: {
            GestionaleLayout: passa, StrutturaLayout: passa,
            PageHeaderGuide: { props: { pageTitle: String, pageSubtitle: String, guides: Array, hasTextGuide: Boolean, textGuideTitle: String }, emits: ['open-text-guide'],
                template: '<header><h1>{{ pageTitle }}</h1><p data-testid="sottotitolo">{{ pageSubtitle }}</p><div v-for="g in guides" :key="g.title" data-testid="card"><b>{{ g.title }}</b> {{ g.description }}</div>'
                    + '<button v-if="hasTextGuide" type="button" data-testid="apri-guida" @click="$emit(\'open-text-guide\')">{{ textGuideTitle }}</button></header>' },
            ImpostazioniCondominioGuide: { props: ['open'], template: '<div v-if="open" data-testid="guida-aperta" />' },
            TooltipProvider: passa, Tooltip: passa, TooltipTrigger: passa, TooltipContent: { template: '<div data-testid="suggerimento"><slot /></div>' },
            Switch: { props: ['modelValue', 'disabled'], emits: ['update:modelValue'],
                template: '<button type="button" role="switch" :aria-checked="modelValue ? \'true\' : \'false\'" :disabled="disabled" @click="$emit(\'update:modelValue\', !modelValue)" />' },
        } },
    });

    return w;
}

describe('la scheda «Impostazioni» del condominio', () => {
    test('il titolo con la descrizione, le tre card, «Guida» che apre il cassetto', async () => {
        const p = monta();
        expect(pulito(p.find('h1').text())).toBe('Impostazioni del condominio');
        expect(pulito(p.get('[data-testid="sottotitolo"]').text())).toBe('Le scelte che valgono solo per questo condominio. Decidono che cosa il programma ti suggerisce: da solo non manda niente.');
        expect(p.findAll('[data-testid="card"]').map((c) => pulito(c.find('b').text()))).toEqual(['Valgono solo qui', 'Suggerimenti, non azioni', 'Di serie']);
        expect(p.find('[data-testid="guida-aperta"]').exists()).toBe(false);
        await p.get('[data-testid="apri-guida"]').trigger('click');
        expect(p.find('[data-testid="guida-aperta"]').exists()).toBe(true);
    });

    test('la sezione «Il recupero dei crediti» con la sua descrizione e due righe con l\'interruttore, accesi di serie; niente schede di scelta', () => {
        const p = monta();
        const sezione = p.get('[data-testid="sezione-recupero"]');
        expect(pulito(sezione.find('h3').text())).toBe('Il recupero dei crediti');
        expect(pulito(sezione.get('[data-testid="descrizione-sezione"]').text())).toContain('nel riquadro «Sei mesi per agire» della dashboard del condominio');
        expect(sezione.findAll('[data-testid="riga-interruttore"] [data-testid="titolo-scelta"]').map((t) => pulito(t.text())))
            .toEqual(['Una rata nuova non fa tornare indietro', 'Il programma suggerisce la prossima azione']);
        expect(sezione.findAll('button[role="switch"]').map((s) => s.attributes('aria-checked'))).toEqual(['true', 'true']);
        expect(sezione.findAll('[data-testid="spiegazione"]').every((s) => pulito(s.text()).startsWith('Acceso: ') && pulito(s.text()).includes('. Spento: '))).toBe(true);
        expect(p.find('button[role="radio"]').exists()).toBe(false);
        expect(p.find('select').exists()).toBe(false);
    });

    test('sotto ogni riga «Adesso: …» con un esempio, che cambia appena si muove l\'interruttore; «di serie» solo lì, solo con la scelta di serie', async () => {
        const p = monta();
        const adesso = () => p.findAll('[data-testid="adesso"]').map((a) => pulito(a.text()));
        expect(adesso()).toEqual([
            'Adesso: con un sollecito di ottobre, il termine passato e una rata nuova a novembre, il programma suggerisce «Manda la diffida», che chiede anche la rata nuova. Di serie.',
            'Adesso: per Rossi, con il sollecito spedito e il termine passato, c\'è il pulsante «Manda la diffida», con la freccia per le altre azioni. Di serie.',
        ]);
        await p.get('[data-testid="interruttore-salita"]').trigger('click');
        await p.get('[data-testid="interruttore-azione"]').trigger('click');
        expect(adesso()).toEqual([
            'Adesso: con un sollecito di ottobre, il termine passato e una rata nuova a novembre, il programma suggerisce «Manda un sollecito» per la rata nuova.',
            'Adesso: per Rossi c\'è «Scegli l\'azione», con tutte le azioni possibili e nessuna evidenziata.',
        ]);
        // Nella sezione «di serie» c'è solo nella riga «Adesso», e solo con la scelta di serie: niente cartellini sulle righe.
        expect(pulito(p.get('[data-testid="sezione-recupero"]').text()).toLowerCase()).not.toContain('di serie');
        await p.get('[data-testid="interruttore-salita"]').trigger('click');
        expect(pulito(p.get('[data-testid="sezione-recupero"]').text()).toLowerCase().split('di serie').length - 1).toBe(1);
    });

    test('la riga «Adesso» sta dentro la riga dell\'interruttore, sotto la spiegazione, su un fondo leggero con gli angoli rounded-md, a 12 px', () => {
        const p = monta();
        const righe = p.findAll('[data-testid="riga-interruttore"]');
        expect(righe.every((r) => r.find('[data-testid="adesso"]').exists())).toBe(true);
        for (const r of righe) {
            const adesso = r.get('[data-testid="adesso"]');
            const testo = r.get('[data-testid="spiegazione"]');
            // Dopo la spiegazione, nello stesso blocco a sinistra dell'interruttore.
            expect(adesso.element.parentElement).toBe(testo.element.parentElement);
            expect(testo.element.compareDocumentPosition(adesso.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
            expect(adesso.classes()).toEqual(expect.arrayContaining(['bg-slate-50', 'dark:bg-slate-800/50', 'rounded-md', 'text-xs']));
        }
    });

    test('una «i» con un esempio concreto accanto a ogni scelta', () => {
        const p = monta();
        const suggerimenti = p.findAll('[data-testid="suggerimento"]').map((s) => pulito(s.text()));
        expect(suggerimenti).toHaveLength(2);
        expect(suggerimenti[0]).toContain('Per esempio');
        expect(suggerimenti[0]).toContain('sollecito');
        expect(suggerimenti[1]).toContain('«Sei mesi per agire»');
        expect(p.findAll('[data-testid^="info-"]')).toHaveLength(2);
    });

    test('«Salva» manda le due scelte del condominio; spento finché non cambia niente', async () => {
        const p = monta();
        const salva = p.get('[data-testid="salva"]');
        expect(salva.attributes('disabled')).toBeDefined();
        await p.get('[data-testid="interruttore-salita"]').trigger('click');
        await p.get('[data-testid="interruttore-azione"]').trigger('click');
        expect(p.get('[data-testid="interruttore-salita"]').attributes('aria-checked')).toBe('false');
        expect(salva.attributes('disabled')).toBeUndefined();
        await salva.trigger('click');
        expect(post).toHaveBeenCalledWith('/gestionale.impostazioni.update/{"condominio":16}', { recupero_salita: 'ricomincia', recupero_suggerisce_azione: false }, expect.anything());
    });

    test('le scelte salvate si vedono negli interruttori: spenti, senza «di serie»', () => {
        const p = monta({ recupero_salita: 'ricomincia', recupero_suggerisce_azione: false });
        expect(p.findAll('button[role="switch"]').map((s) => s.attributes('aria-checked'))).toEqual(['false', 'false']);
        expect(pulito(p.get('[data-testid="sezione-recupero"]').text()).toLowerCase()).not.toContain('di serie');
    });

    test('chi non modifica il condominio vede gli interruttori, fermi, e la frase; niente «Salva»', () => {
        const p = monta(undefined, false);
        expect(p.findAll('button[role="switch"]').every((s) => s.attributes('disabled') !== undefined)).toBe(true);
        expect(p.find('[data-testid="salva"]').exists()).toBe(false);
        expect(pulito(p.text())).toContain('Le cambia chi può modificare il condominio.');
    });

    test('al telefono il titolo sopra e l\'interruttore sotto, come le impostazioni generali; tema scuro', () => {
        const p = monta();
        const righe = p.findAll('[data-testid="riga-interruttore"]');
        expect(righe).toHaveLength(2);
        expect(righe.every((r) => r.classes().includes('flex-col') && r.classes().includes('sm:flex-row') && r.classes().includes('border'))).toBe(true);
        expect(p.get('[data-testid="sezione-recupero"]').classes().some((c) => c.startsWith('dark:'))).toBe(true);
    });
});
