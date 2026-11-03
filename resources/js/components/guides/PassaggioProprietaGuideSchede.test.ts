// @vitest-environment jsdom

/**
 * Coda 383 — la guida «Registrare un passaggio» (`PassaggioProprietaGuide.vue`) è a sei schede e si apre su quella del tipo di passaggio
 * scelto nella pagina.
 *
 * Le schede sono «Le basi», «Vendita», «Locazione», «Usufrutto», «Successione», «Dopo»; dentro «Locazione» una seconda fila con tre parti,
 * «Inizio», «Fine», «Prospetto». La guida si apre sulla scheda del tipo (decisione 143): «Vendita» dalla vendita, «Usufrutto» dall'usufrutto,
 * «Successione» dalla successione, «Locazione» dall'inizio e dalla fine della locazione, sulla parte «Inizio» o «Fine»; senza tipo, o con un
 * tipo che la guida non conosce, sulle basi. Riaperta, torna alla scheda del tipo e non a quella in cui era rimasta. I rimandi fra le schede
 * («vedi «Vendita»») cambiano scheda, e parte nella locazione, e riportano in cima.
 *
 * Schede e sezioni chiuse restano nel DOM, nascoste (`unmount-on-hide` spento): la ricerca del browser trova anche il loro testo, e i test
 * che leggono il testo della guida (`PassaggioProprietaGuide.test.ts` e gli altri) lo leggono tutto. Che la ricerca del browser poi apra la
 * sezione (`hidden="until-found"`) in jsdom non si vede: l'attributo `hidden` vi è solo booleano.
 *
 * Il testo delle schede non è fissato qui: i test cercano frasi che stanno nella guida da prima delle schede, e controllano che la scheda
 * aperta mostri il pannello che deve, non solo l'etichetta evidenziata.
 *
 * Cosa resta scoperto: l'aspetto (larghezze, righe del telefono, tema scuro), che si guarda a video.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';

import PassaggioProprietaGuide from './PassaggioProprietaGuide.vue';

const passa = { template: '<div><slot /></div>' };
const stubs = { Sheet: passa, SheetContent: passa, SheetHeader: passa, SheetTitle: passa, SheetDescription: passa };

const montate: VueWrapper[] = [];
const scorri = vi.fn();

function monta(tipo?: string, open = true): VueWrapper {
    const w = mount(PassaggioProprietaGuide, { props: { open, ...(tipo ? { tipo } : {}) }, global: { stubs }, attachTo: document.body });
    montate.push(w);
    return w;
}

beforeEach(() => {
    scorri.mockClear();
    Element.prototype.scrollIntoView = scorri;
});

afterEach(() => {
    montate.splice(0).forEach(w => w.unmount());
    delete (Element.prototype as { scrollIntoView?: unknown }).scrollIntoView;
});

const norm = (s: string | null | undefined) => (s ?? '').replace(/\s+/g, ' ').trim();
const dentroUnPannello = (e: Element) => e.closest('[role="tabpanel"]') !== null;

/** Le etichette della fila di sopra (le schede) e di quella dentro «Locazione» (le parti). */
function etichette(w: VueWrapper) {
    const tutte = w.findAll('[role="tab"]');
    return {
        schede: tutte.filter(t => !dentroUnPannello(t.element)).map(t => t.text()),
        parti: tutte.filter(t => dentroUnPannello(t.element)).map(t => t.text()),
    };
}

/** La scheda aperta e la parte aperta nella locazione, come le dicono le etichette evidenziate. */
function aperte(w: VueWrapper) {
    const attive = w.findAll('[role="tab"][data-state="active"]');
    return {
        scheda: attive.find(t => !dentroUnPannello(t.element))?.text(),
        parte: attive.find(t => dentroUnPannello(t.element))?.text(),
    };
}

/** I pannelli aperti: quello della scheda (fila di sopra) e, nella locazione, quello della parte. */
function pannelliAperti(w: VueWrapper): { scheda?: Element; parte?: Element } {
    const attivi = w.findAll('[role="tabpanel"][data-state="active"]').map(p => p.element);
    const scheda = attivi.find(p => !dentroUnPannello(p.parentElement as Element));
    return { scheda, parte: attivi.find(p => p !== scheda && scheda?.contains(p)) };
}

/** Il testo del pannello aperto della fila di sopra, e quello della parte aperta se c'è. */
function pannelloAperto(w: VueWrapper): { scheda: string; parte: string } {
    const { scheda, parte } = pannelliAperti(w);
    return { scheda: norm(scheda?.textContent), parte: norm(parte?.textContent) };
}

/** Un rimando della guida: il pulsante con quel testo dentro il pannello aperto (la parte, se la scheda ne ha). */
function rimando(w: VueWrapper, testo: string) {
    const { scheda, parte } = pannelliAperti(w);
    const radice = parte ?? scheda!;
    const trovati = w.findAll('button[type="button"]').filter(b => radice.contains(b.element) && b.text() === testo);
    expect(trovati, `rimando ${testo} nella scheda aperta`).toHaveLength(1);
    return trovati[0];
}

describe('Coda 383 — le schede della guida dei passaggi', () => {
    test('Coda 383 — la guida ha sei schede nell\'ordine delle basi, dei tipi e di ciò che viene dopo, e la locazione tre parti; ne è aperta una sola', () => {
        const w = monta();
        const { schede, parti } = etichette(w);

        expect(schede).toEqual(['Le basi', 'Vendita', 'Locazione', 'Usufrutto', 'Successione', 'Dopo']);
        expect(parti).toEqual(['Inizio', 'Fine', 'Prospetto']);

        // Le altre schede restano nel DOM ma nascoste: un solo pannello della fila di sopra è visibile.
        const pannelli = w.findAll('[role="tabpanel"]').filter(p => !dentroUnPannello(p.element.parentElement as Element));
        expect(pannelli).toHaveLength(6);
        expect(pannelli.filter(p => !p.element.hasAttribute('hidden'))).toHaveLength(1);
    });

    test('Coda 383 — senza un tipo, o con un tipo che la guida non conosce, si apre su «Le basi»', () => {
        for (const tipo of [undefined, 'un_tipo_che_non_esiste']) {
            const w = monta(tipo);
            expect(aperte(w).scheda).toBe('Le basi');
            expect(pannelloAperto(w).scheda).toContain('Chi esce, chi entra');
        }
    });

    test.each([
        ['vendita → «Vendita»', 'vendita', 'Vendita', undefined, 'Nella vendita le rate in bozza passano a chi entra.'],
        ['usufrutto → «Usufrutto»', 'usufrutto', 'Usufrutto', undefined, 'Quando finisce un usufrutto'],
        ['successione → «Successione»', 'successione', 'Successione', undefined, 'Una data sola, quella del decesso.'],
        ['inizio_locazione → «Locazione», parte «Inizio»', 'inizio_locazione', 'Locazione', 'Inizio', 'La locazione si aggiunge, non sostituisce'],
        ['fine_locazione → «Locazione», parte «Fine»', 'fine_locazione', 'Locazione', 'Fine', 'Alla fine di una locazione, con un nuovo inquilino o senza, il pannello «Cosa cambierà»'],
    ])('Coda 383 — la guida si apre sulla scheda del tipo di passaggio e ne mostra il pannello (%s)', (_, tipo, scheda, parte, frase) => {
        const w = monta(tipo);

        expect(aperte(w).scheda).toBe(scheda);
        if (parte) expect(aperte(w).parte).toBe(parte);

        // Il pannello aperto è quello della scheda: la frase sta nel pannello della scheda (e della parte, nella locazione).
        const aperto = pannelloAperto(w);
        expect(parte ? aperto.parte : aperto.scheda).toContain(frase);
    });

    test('Coda 383 — le tre parti della locazione mostrano ciascuna il proprio pannello', async () => {
        const w = monta('inizio_locazione');
        const frasi: Record<string, string> = {
            Inizio: 'La locazione si aggiunge, non sostituisce',
            Fine: 'Alla fine di una locazione, con un nuovo inquilino o senza, il pannello «Cosa cambierà»',
            Prospetto: 'Il prospetto degli oneri accessori',
        };

        for (const [parte, frase] of Object.entries(frasi)) {
            await w.findAll('[role="tab"]').find(t => t.text() === parte)!.trigger('mousedown', { button: 0 });
            expect(aperte(w)).toEqual({ scheda: 'Locazione', parte });
            expect(pannelloAperto(w).parte).toContain(frase);
        }
        // Dopo aver girato per tutte e tre, la parte «Fine» non mostra più le frasi dell'inizio.
        await w.findAll('[role="tab"]').find(t => t.text() === 'Fine')!.trigger('mousedown', { button: 0 });
        expect(pannelloAperto(w).parte).not.toContain(frasi.Inizio);
    });

    test('Coda 383 — riaperta, la guida torna alla scheda del tipo e alla sua parte, non a quella in cui era rimasta', async () => {
        const w = monta('fine_locazione');
        await w.findAll('[role="tab"]').find(t => t.text() === 'Prospetto')!.trigger('mousedown', { button: 0 });
        await w.findAll('[role="tab"]').find(t => t.text() === 'Dopo')!.trigger('mousedown', { button: 0 });
        expect(aperte(w).scheda).toBe('Dopo');

        await w.setProps({ open: false });
        await w.setProps({ open: true });

        expect(aperte(w)).toEqual({ scheda: 'Locazione', parte: 'Fine' });
    });

    test('Coda 383 — a guida aperta, scegliere un\'altra scheda la apre e ne mostra il pannello', async () => {
        const w = monta('vendita');
        await w.findAll('[role="tab"]').find(t => t.text() === 'Successione')!.trigger('mousedown', { button: 0 });

        expect(aperte(w).scheda).toBe('Successione');
        expect(pannelloAperto(w).scheda).toContain('Una data sola, quella del decesso.');
    });
});

describe('Coda 383 — i rimandi fra le schede', () => {
    test.each([
        // etichetta, dove si parte (il tipo della pagina), che cosa si preme, dove si arriva
        ['senza tipo: «Vendita» → «Vendita»', undefined, '«Vendita»', 'Vendita', undefined],
        ['senza tipo: «Dopo» → «Dopo»', undefined, '«Dopo»', 'Dopo', undefined],
        ['vendita: «Le basi» → «Le basi»', 'vendita', '«Le basi»', 'Le basi', undefined],
        ['vendita: «Locazione» → «Locazione», parte «Fine»', 'vendita', '«Locazione»', 'Locazione', 'Fine'],
        ['inizio_locazione: «Fine» → «Locazione», parte «Fine»', 'inizio_locazione', '«Fine»', 'Locazione', 'Fine'],
        ['fine_locazione: «Inizio» → «Locazione», parte «Inizio»', 'fine_locazione', '«Inizio»', 'Locazione', 'Inizio'],
        ['fine_locazione: «Dopo» → «Dopo»', 'fine_locazione', '«Dopo»', 'Dopo', undefined],
        ['usufrutto: «Vendita» → «Vendita»', 'usufrutto', '«Vendita»', 'Vendita', undefined],
        ['successione: «Locazione» → «Locazione», parte «Fine»', 'successione', '«Locazione»', 'Locazione', 'Fine'],
    ])('Coda 383 — un rimando cambia scheda, e parte nella locazione, e riporta in cima (%s)', async (_, tipo, testo, scheda, parte) => {
        const w = monta(tipo);

        await rimando(w, testo).trigger('click');

        expect(aperte(w).scheda).toBe(scheda);
        if (parte) expect(aperte(w).parte).toBe(parte);
        expect(scorri).toHaveBeenCalledWith({ block: 'start' });
    });
});

describe('Coda 383 — schede e sezioni chiuse restano nel DOM', () => {
    test('Coda 383 — il testo di una scheda non aperta è nel DOM, nascosto', () => {
        const w = monta('vendita');

        // La scheda aperta è «Vendita»; la successione è nel DOM ma nascosta.
        const pannelli = w.findAll('[role="tabpanel"]').filter(p => !dentroUnPannello(p.element.parentElement as Element));
        const successione = pannelli.find(p => p.attributes('id')?.endsWith('-content-successione'))!;
        expect(successione.element.hasAttribute('hidden')).toBe(true);
        expect(norm(successione.element.textContent)).toContain('Una data sola, quella del decesso.');
        expect(norm(w.text())).toContain('Una data sola, quella del decesso.');
    });

    test('Coda 383 — anche le parti della locazione non aperte restano nel DOM, nascoste', () => {
        const w = monta('inizio_locazione');
        const parti = w.findAll('[role="tabpanel"]').filter(p => p.attributes('id')?.match(/-content-(inizio|fine|prospetto)$/));

        expect(parti.map(p => p.attributes('id')!.replace(/^.*-content-/, ''))).toEqual(['inizio', 'fine', 'prospetto']);
        expect(parti.map(p => p.element.hasAttribute('hidden'))).toEqual([false, true, true]);
        expect(norm(parti[1].element.textContent)).toContain('Alla fine di una locazione, con un nuovo inquilino o senza, il pannello «Cosa cambierà»');
        expect(norm(parti[2].element.textContent)).toContain('Il prospetto degli oneri accessori');
    });

    test('Coda 383 — una sezione chiusa ha il suo testo nel DOM, nascosto finché non si apre; premuto il titolo si apre', async () => {
        const w = monta('vendita');
        await nextTick();
        const titolo = w.findAll('button[aria-expanded="false"]').find(b => b.text() === 'Chi resta obbligato con la riserva')!;
        expect(titolo, 'titolo della sezione chiusa').toBeDefined();

        const sezione = () => titolo.element.closest('h3')!.parentElement!.querySelector('[role="region"]')!;
        expect(sezione().hasAttribute('hidden')).toBe(true);
        expect(norm(sezione().textContent)).toContain('Nella vendita o donazione con riserva d\'usufrutto, dal giorno dell\'atto nudo proprietario e usufruttuario rispondono in solido');

        await titolo.trigger('click');

        expect(titolo.attributes('aria-expanded')).toBe('true');
        expect(sezione().hasAttribute('hidden')).toBe(false);
    });
});
