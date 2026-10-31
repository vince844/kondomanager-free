// @vitest-environment jsdom

/**
 * Cosa resta scoperto: lo stato del piano (allineato, quota aperta, disallineato) lo decide il server (`PianoRateQuoteService::statoAllineamento`,
 * i test Pest di `Beta59PianoQuoteNonAssegnateTest`): qui arriva nelle props e si legge come lo manda; il colore, la larghezza a 375 px e il tema
 * scuro si guardano a video.
 *
 * Coda 414 e D143: la pagina del piano con le quote non assegnate accettate.
 *
 * - il riquadro in cima dice la cifra, l'unità e perché, e le strade che esistono (D143: niente «addebito manuale», niente «conguaglio di fine anno»);
 * - il badge di «Copertura spese» ha tre casi: niente, «Quota aperta · € 75,00 (Cantina 1)» in ambra, «Disallineato: ricalcola!» in rosso, che
 *   diventa «Disallineato» quando il piano non si ricalcola più;
 * - le sottrazioni in chiaro: la riga dello scarto (cifre del server), la riga di «Parziale» e la riga dei non assegnati sotto la voce;
 * - «1 voce inclusa» al singolare.
 *
 * Le cifre sono quelle del caso del v34 (regola del motore, `CalcoloQuoteService::distribuisciSuTabelle`): preventivo € 4.000,00, nel piano
 * € 2.500,00, Cantina 1 (30 su 1000 millesimi) € 75,00, nelle rate € 2.425,00.
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 *
 * La pagina è quella vera: si stubbano la cornice, la guida, il calendario delle date, il pannello laterale, il modale di «Sposta spesa», lo storico
 * dei movimenti e gli avvisi. Il testo si legge dal documento.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    router: { delete: vi.fn(), post: vi.fn(), get: vi.fn(), reload: vi.fn((o?: { onFinish?: () => void }) => o?.onFinish?.()) },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (c: string) => c }));
(globalThis as unknown as { route: unknown }).route = (nome: string, parametri?: Record<string, unknown>) => `/${nome}?${JSON.stringify(parametri ?? {})}`;

const PianiRateShow = (await import('@/pages/gestionale/pianiRate/PianiRateShow.vue')).default;

let pagina: VueWrapper | null = null;

const RISCALDAMENTO = { id: 1, nome: 'Riscaldamento', importo: 250000, importo_originale: 400000, is_parent: false, is_frazionato: true, tipo: 'spesa', non_assegnati_cents: 7500 };
const PULIZIE = { id: 2, nome: 'Pulizie', importo: 100000, importo_originale: 100000, is_parent: false, is_frazionato: false, tipo: 'spesa', non_assegnati_cents: 0 };
const CANTINA = {
    chiave: 'senza_titolare|3|1|2|proprietario|', motivo: null, immobile_id: 3, immobile_nome: 'Cantina 1', conto_id: 1, conto_nome: 'Riscaldamento', tabella_id: 2,
    tabella_nome: 'Riscaldamento', ruolo_richiesto: 'proprietario', importo_cents: 7500, giorni: null, riga_descrizione: null,
};
const NOTA = 'Cantina 1 senza proprietario al rogito';
const QUOTA_APERTA = { stato: 'quota_aperta', atteso_cents: 250000, generato_cents: 242500, scarto_cents: 7500, scarto_registrato_cents: 7500 };

type Opzioni = {
    capitoli?: unknown[]; voci?: unknown[] | null; scarto?: number; nota?: string | null; allineamento?: unknown; disallineato?: boolean;
    ratePure?: unknown[]; fermo?: unknown; movimenti?: unknown[];
};

async function monta(o: Opzioni = {}) {
    const voci = o.voci === undefined ? [CANTINA] : o.voci;
    pagina = mount(PianiRateShow, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never, esercizio: { id: 1, nome: '2026' } as never,
            pianoRate: {
                id: 5, nome: 'Riscaldamento 2026', stato: 'bozza', tipo: 'ordinario', numero_rate: 4, totale_spese: 250000, totale_entrate: 0, totale_da_ripartire: 250000,
                budget_movements: o.movimenti ?? [], capitoli: o.capitoli ?? [RISCALDAMENTO], nota_scoperti: o.nota === undefined ? NOTA : o.nota,
                quote_non_assegnate: voci === null ? null : { voci, scarto_cents: o.scarto ?? 7500, registrate_il: '2026-10-30T20:00:00+00:00', registrate_da: 1 },
            },
            allineamento: (o.allineamento ?? QUOTA_APERTA) as never, disallineato: o.disallineato ?? false,
            quotePerAnagrafica: [], quotePerImmobile: [], ratePure: o.ratePure ?? [], needsMigration: false, sources: [], destinations: [], copertura: null,
            fermo_del_piano: (o.fermo ?? null) as never,
        },
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, VueDatePicker: true, PianoRateSpaccatoSheet: true, ModalSpostaSpesa: true, BudgetHistoryPopover: true, DestinatariWarning: true, DestinatariWarningDefunto: true, ScopertoWarning: true, Head: { template: '<span />' }, Link: { template: '<a><slot /></a>' } }, mocks: { route: (n: string) => `/${n}` } },
    });
    await flushPromises();
}

const pulisci = (t: string | null | undefined) => (t ?? '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim();
const testo = () => pulisci(document.body.textContent);
/** Il testo del riquadro, un blocco dopo l'altro (le righe sono blocchi a sé: il documento le attacca senza spazio, lo schermo le va a capo). */
const riquadro = () => {
    const el = document.body.querySelector('[data-riquadro="quote-non-assegnate"]');
    if (!el) return '';
    return pulisci([...el.children].map((c) => (c.tagName === 'UL' ? [...c.children].map((li) => li.textContent).join(' ') : c.textContent)).join(' '));
};
/** Il badge accanto a «Copertura spese»: il titolo della sezione senza il suo nome. */
const badge = () => {
    const titolo = [...document.body.querySelectorAll('h3')].find((h) => h.textContent?.includes('Copertura spese'));
    return pulisci(titolo?.textContent).replace(/^Copertura spese\s*/, '');
};
const riga = (nome: string) => [...document.body.querySelectorAll(`[data-riga="${nome}"]`)].map((e) => pulisci(e.textContent));

const apriLeVoci = async () => {
    const intestazione = [...document.body.querySelectorAll('button')].find((b) => b.textContent?.includes('Copertura spese')) as HTMLButtonElement;
    intestazione.click();
    await flushPromises();
    await new Promise((r) => setTimeout(r, 150));
    await flushPromises();
};

const EMESSA = [{ id: 11, numero_rata: 1, is_emessa: true, is_published: false, totale_rata: 606.25 }];
const FERMO = { ragioni: ['ha già quote a giornale'], passaggi: [], rimedi: ['annulla le emissioni dalla pagina del piano, se non hanno incassi'] };

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('il riquadro in cima: cifra, unità e perché (Coda 414)', () => {
    test('il v34: € 75,00, la Cantina 1 sulla voce del riscaldamento, nessun proprietario, la motivazione e la strada', async () => {
        await monta();

        expect(riquadro()).toBe('Questo piano rate contiene quote non assegnate: € 75,00. Le rate le hanno lasciate fuori: non sono addebitate a nessuno. '
            + 'Cantina 1 · Riscaldamento: € 75,00 — nessun proprietario. Motivazione registrata: "Cantina 1 senza proprietario al rogito" '
            + '→ Censisci il titolare che manca, poi usa il tasto Ricalcola per includere la quota nel piano.');
    });

    test('una voce senza tabella: la quota è della voce, e la strada è collegare una tabella', async () => {
        await monta({ voci: [{ ...CANTINA, chiave: 'conto_senza_tabella||4|||', motivo: 'conto_senza_tabella', immobile_id: null, immobile_nome: null, conto_id: 4, conto_nome: 'Ascensore', tabella_id: null, tabella_nome: null, ruolo_richiesto: null, importo_cents: 60000 }], scarto: 60000,
            allineamento: { ...QUOTA_APERTA, scarto_cents: 60000, scarto_registrato_cents: 60000 } });

        expect(riquadro()).toContain('Ascensore: € 600,00 — la voce non ha una tabella millesimale collegata.');
        expect(riquadro()).toContain('→ Collega una tabella millesimale alla voce, poi usa il tasto Ricalcola');
    });

    test('il solo millesimo non compilato non è una quota senza destinatario: lo dice, senza cifra', async () => {
        await monta({ voci: [{ ...CANTINA, motivo: 'millesimo_non_compilato', ruolo_richiesto: null, importo_cents: 0 }], scarto: 0,
            allineamento: { stato: 'allineato', atteso_cents: 250000, generato_cents: 250000, scarto_cents: 0, scarto_registrato_cents: null } });

        expect(riquadro()).toContain('Nessuna quota è rimasta senza destinatario, ma un\'unità non ha un millesimo: la sua parte l\'hanno pagata le altre.');
        expect(riquadro()).toContain('Cantina 1 · Riscaldamento — non ha un millesimo nella tabella «Riscaldamento»: la sua parte è stata ripartita fra le altre unità.');
        expect(riquadro()).not.toContain('contiene quote non assegnate');
        expect(badge()).toBe('');
    });

    test('un piano accettato prima dell\'aggiornamento (la nota senza l\'elenco): non promette che «Ricalcola» sistemi', async () => {
        await monta({ voci: null, allineamento: { stato: 'disallineato', atteso_cents: 250000, generato_cents: 242500, scarto_cents: 7500, scarto_registrato_cents: null }, disallineato: true });

        expect(riquadro()).toContain('Questo piano rate contiene quote non assegnate. Motivazione registrata: "Cantina 1 senza proprietario al rogito"');
        expect(riquadro()).toContain('La cifra e le unità non sono registrate: il piano è stato accettato prima che il programma le salvasse, e il riquadro «Copertura spese» lo dice disallineato. '
            + '«Ricalcola» non assegna queste quote a nessuno: se qualcosa resta senza destinatario chiede di nuovo la presa d\'atto, e questa volta registra cifra e unità.');
    });

    test('la nota di prima dell\'aggiornamento su un piano che oggi è allineato (censito e ricalcolato con la versione di prima): dice che la quota non c\'è più, senza strade da fare', async () => {
        await monta({ voci: null, allineamento: { stato: 'allineato', atteso_cents: 250000, generato_cents: 250000, scarto_cents: 0, scarto_registrato_cents: null } });

        expect(riquadro()).toBe('Questo piano rate aveva quote non assegnate. Motivazione registrata: "Cantina 1 senza proprietario al rogito" '
            + 'La motivazione è di una generazione di prima di questo aggiornamento: oggi le rate mettono in conto tutto quello che il piano chiede. '
            + 'Il riquadro si spegne al prossimo Ricalcola, se il piano si ricalcola ancora.');
        expect(badge()).toBe('');
    });
});

describe('il riquadro in cima: le strade che esistono (D143)', () => {
    test('rate emesse: riaprire il piano con i passi del pulsante, sistemare la causa, «Ricalcola»; niente addebito manuale né conguaglio di fine anno', async () => {
        await monta({ ratePure: EMESSA, fermo: FERMO });

        expect(riquadro()).toContain('→ Le rate sono già emesse. Per includere la quota bisogna riaprire il piano con i passi elencati sul pulsante Ricalcola '
            + '(annullare le emissioni, e prima stornare gli incassi se ce ne sono), poi censire il titolare che manca e premere Ricalcola. Il programma non ha un addebito individuale.');
        expect(testo()).not.toContain('addebito manuale');
        expect(testo()).not.toContain('conguaglio di fine anno');
    });

    test('fermo senza emissioni (un incasso, un passaggio): il motivo è sul pulsante, e nessuna promessa', async () => {
        await monta({ fermo: { ragioni: ['ha un incasso su una sua quota'], passaggi: [], rimedi: ['annulla quel movimento (lo registri di nuovo dopo il ricalcolo)'] } });

        expect(riquadro()).toContain('→ Il piano non si ricalcola più: il motivo e come riaprirlo sono sul pulsante Ricalcola. Finché resta così, la quota non è addebitata a nessuno; il programma non ha un addebito individuale.');
        expect(testo()).not.toContain('addebito manuale');
        expect(testo()).not.toContain('conguaglio di fine anno');
    });
});

describe('il badge di «Copertura spese»: tre casi (Coda 414)', () => {
    test('quota aperta: in ambra, con la cifra e l\'unità; «Disallineato» non c\'è', async () => {
        await monta();

        expect(badge()).toBe('Quota aperta · € 75,00 (Cantina 1)');
        expect(testo()).not.toContain('Disallineato');
    });

    test('controprova — disallineato, e il piano si ricalcola: «Disallineato: ricalcola!»', async () => {
        await monta({ voci: null, nota: null, allineamento: { stato: 'disallineato', atteso_cents: 340000, generato_cents: 350000, scarto_cents: -10000, scarto_registrato_cents: null }, disallineato: true });

        expect(badge()).toBe('Disallineato: ricalcola!');
    });

    test('disallineato con le rate emesse: «Disallineato», senza l\'invito a un ricalcolo che il pulsante rifiuta', async () => {
        await monta({ voci: null, nota: null, allineamento: { stato: 'disallineato', atteso_cents: 340000, generato_cents: 350000, scarto_cents: -10000, scarto_registrato_cents: null }, disallineato: true, ratePure: EMESSA, fermo: FERMO });

        expect(badge()).toBe('Disallineato');
        expect(testo()).not.toContain('ricalcola!');
    });

    test('due quote: «2 quote» al posto dell\'unità', async () => {
        await monta({ voci: [CANTINA, { ...CANTINA, chiave: 'senza_titolare|9|1|2|proprietario|', immobile_id: 9, immobile_nome: 'Cantina 2', importo_cents: 5000 }], scarto: 12500,
            allineamento: { ...QUOTA_APERTA, generato_cents: 237500, scarto_cents: 12500, scarto_registrato_cents: 12500 } });

        expect(badge()).toBe('Quota aperta · € 125,00 (2 quote)');
    });
});

describe('le sottrazioni in chiaro (Coda 414)', () => {
    test('la riga dello scarto: da mettere in rata € 2.500,00 − nelle rate € 2.425,00 = € 75,00, le quote non assegnate accettate', async () => {
        await monta();

        expect(riga('scarto')).toEqual(['Da mettere in rata € 2.500,00 − nelle rate € 2.425,00 = € 75,00: le quote non assegnate accettate.']);
    });

    test('con due quote sulla stessa voce la riga dice l\'arrotondamento: € 4,89 lo scarto, € 4,90 le quote', async () => {
        await monta({ voci: [{ ...CANTINA, importo_cents: 245 }, { ...CANTINA, chiave: 'b', immobile_nome: 'Cantina 2', importo_cents: 245 }], scarto: 489,
            allineamento: { stato: 'quota_aperta', atteso_cents: 250368, generato_cents: 249879, scarto_cents: 489, scarto_registrato_cents: 489 } });

        expect(riga('scarto')).toEqual(['Da mettere in rata € 2.503,68 − nelle rate € 2.498,79 = € 4,89: le quote non assegnate accettate (€ 4,90 le singole quote, la differenza è l\'arrotondamento).']);
    });

    test('disallineato dopo una quota aperta: la riga dice la quota della generazione e che da allora qualcosa è cambiato', async () => {
        await monta({ allineamento: { stato: 'disallineato', atteso_cents: 340000, generato_cents: 342500, scarto_cents: -2500, scarto_registrato_cents: 7500 }, disallineato: true });

        expect(riga('scarto')).toEqual(['Da mettere in rata € 3.400,00 − nelle rate € 3.425,00 = € -25,00: alla generazione erano € 75,00 di quote non assegnate accettate, e da allora qualcosa è cambiato.']);
    });

    test('controprova — allineato: nessuna riga dello scarto', async () => {
        await monta({ voci: null, nota: null, allineamento: { stato: 'allineato', atteso_cents: 250000, generato_cents: 250000, scarto_cents: 0, scarto_registrato_cents: null } });

        expect(riga('scarto')).toEqual([]);
    });

    test('sotto la voce: la riga di «Parziale» con preventivo, parte fuori e parte nel piano, e la riga dei non assegnati', async () => {
        await monta();
        await apriLeVoci();

        expect(riga('parziale')).toEqual(['Preventivo € 4.000,00 − non in questo piano € 1.500,00 = nel piano € 2.500,00.']);
        expect(riga('non-assegnati')).toEqual(['Non assegnati € 75,00 (Cantina 1: nessun proprietario): restano fuori dalle rate.']);
    });

    test('una voce che ha ceduto con «Sposta spesa»: la riga di «Parziale» dice quanto', async () => {
        const ceduta = { ...PULIZIE, importo: 80000, is_frazionato: true };
        // il riscaldamento ha ricevuto € 200,00 dalle pulizie: è «Integra», non «Parziale», e la sua riga non c'è
        await monta({ movimenti: [{ id: 1, source_conto_id: 2, destination_conto_id: 1, amount: 20000 }], capitoli: [{ ...RISCALDAMENTO, importo: 270000 }, ceduta] });
        await apriLeVoci();

        expect(riga('parziale')).toEqual(['Preventivo € 1.000,00 − non in questo piano € 200,00 (di cui € 200,00 ceduti ad altre voci con «Sposta spesa») = nel piano € 800,00.']);
    });
});

describe('«N voci incluse» (Coda 414)', () => {
    test('una voce: «1 voce inclusa»', async () => {
        await monta();

        expect(testo()).toContain('1 voce inclusa •');
        expect(testo()).not.toContain('1 voci');
    });

    test('controprova — due voci: «2 voci incluse»', async () => {
        await monta({ capitoli: [RISCALDAMENTO, PULIZIE] });

        expect(testo()).toContain('2 voci incluse •');
    });
});

describe('le quote delle voci di entrata contano con il loro segno (Coda 414)', () => {
    const ANTENNA = { id: 2, nome: 'Antenna', importo: 100000, importo_originale: 100000, is_parent: false, is_frazionato: false, tipo: 'entrata', non_assegnati_cents: 3000 };
    const CANTINA_ANTENNA = { ...CANTINA, chiave: 'senza_titolare|3|2|2|proprietario|', conto_id: 2, conto_nome: 'Antenna', importo_cents: 3000, segno: -1 };

    test('una sola quota su una voce di entrata: lo scarto è − € 30,00 e la quota lo spiega, senza nessun «arrotondamento»', async () => {
        await monta({
            capitoli: [ANTENNA], voci: [CANTINA_ANTENNA], scarto: -3000,
            allineamento: { stato: 'quota_aperta', atteso_cents: -100000, generato_cents: -97000, scarto_cents: -3000, scarto_registrato_cents: -3000 },
        });

        // Il secondo operando negativo sta fra parentesi: «− nelle rate € -970,00» leggeva un meno accanto all'altro.
        expect(riga('scarto')).toEqual(['Da mettere in rata € -1.000,00 − nelle rate (€ -970,00) = € -30,00: le quote non assegnate accettate.']);
    });

    test('una spesa e un\'entrata: € 75,00 − € 30,00 = € 45,00 di scarto, spiegati dalle due quote', async () => {
        await monta({
            capitoli: [RISCALDAMENTO, ANTENNA], voci: [{ ...CANTINA, segno: 1 }, CANTINA_ANTENNA], scarto: 4500,
            allineamento: { stato: 'quota_aperta', atteso_cents: 150000, generato_cents: 145500, scarto_cents: 4500, scarto_registrato_cents: 4500 },
        });

        expect(riga('scarto')).toEqual(['Da mettere in rata € 1.500,00 − nelle rate € 1.455,00 = € 45,00: le quote non assegnate accettate.']);
    });

    test('controprova — due cantine sulla stessa voce: la differenza di un centesimo è l\'arrotondamento', async () => {
        await monta({
            voci: [{ ...CANTINA, importo_cents: 245, segno: 1 }, { ...CANTINA, chiave: 'k2', immobile_nome: 'Cantina 2', importo_cents: 245, segno: 1 }], scarto: 489,
            allineamento: { stato: 'quota_aperta', atteso_cents: 250368, generato_cents: 249879, scarto_cents: 489, scarto_registrato_cents: 489 },
        });

        expect(riga('scarto')).toEqual(['Da mettere in rata € 2.503,68 − nelle rate € 2.498,79 = € 4,89: le quote non assegnate accettate (€ 4,90 le singole quote, la differenza è l\'arrotondamento).']);
    });
});
describe('bassi della pagina del piano con le quote non assegnate (Coda 414)', () => {
    const ANTENNA = { id: 2, nome: 'Antenna', importo: 100000, importo_originale: 100000, is_parent: false, is_frazionato: false, tipo: 'entrata', non_assegnati_cents: 3000 };
    const CANTINA_ANTENNA = { ...CANTINA, chiave: 'senza_titolare|3|2|2|proprietario|', conto_id: 2, conto_nome: 'Antenna', importo_cents: 3000, segno: -1 };

    test('la quota di una voce di entrata non è «addebitata a nessuno»: è un credito che nessuno riceve', async () => {
        await monta({
            capitoli: [ANTENNA], voci: [CANTINA_ANTENNA], scarto: -3000,
            allineamento: { stato: 'quota_aperta', atteso_cents: -100000, generato_cents: -97000, scarto_cents: -3000, scarto_registrato_cents: -3000 },
        });

        expect(riquadro()).toContain('Le rate le hanno lasciate fuori: nessuno le riceve come credito.');
        expect(riquadro()).not.toContain('non sono addebitate a nessuno');
    });

    test('una spesa e un\'entrata nello stesso piano: la frase dice le due cose, ognuna per la sua', async () => {
        await monta({
            capitoli: [RISCALDAMENTO, ANTENNA], voci: [{ ...CANTINA, segno: 1 }, CANTINA_ANTENNA], scarto: 4500,
            allineamento: { stato: 'quota_aperta', atteso_cents: 150000, generato_cents: 145500, scarto_cents: 4500, scarto_registrato_cents: 4500 },
        });

        expect(riquadro()).toContain('Le rate le hanno lasciate fuori: le spese non sono addebitate a nessuno e le entrate nessuno le riceve come credito.');
    });

    test('un piano disallineato con una quota registrata: «Ricalcola» le rifà con i dati di adesso e, se restano quote senza destinatario, ne chiede la presa d\'atto', async () => {
        await monta({ allineamento: { stato: 'disallineato', atteso_cents: 250000, generato_cents: 242500, scarto_cents: 7500, scarto_registrato_cents: null }, disallineato: true });

        expect(riga('scarto')).toEqual(['Da mettere in rata € 2.500,00 − nelle rate € 2.425,00 = € 75,00: le rate non corrispondono a quello che il piano chiede; «Ricalcola», se il piano si ricalcola ancora, '
            + 'le rifà con i dati di adesso e, se restano quote senza destinatario, ne chiede la presa d\'atto.']);
    });

    test('controprova — nelle rate zero o positivo: la cifra dopo il meno non ha parentesi', async () => {
        await monta({ scarto: 7500, allineamento: { stato: 'quota_aperta', atteso_cents: 7500, generato_cents: 0, scarto_cents: 7500, scarto_registrato_cents: 7500 } });

        expect(riga('scarto')).toEqual(['Da mettere in rata € 75,00 − nelle rate € 0,00 = € 75,00: le quote non assegnate accettate.']);
    });

    test('i giorni senza titolare: il rimedio è registrare il passaggio solo se qualcuno è entrato', async () => {
        const giorni = { ...CANTINA, motivo: 'giorni_senza_titolare', giorni: 184 };
        await monta({ voci: [giorni] });

        expect(riquadro()).toContain('Cantina 1 · Riscaldamento: € 75,00 — per 184 giorni del periodo di competenza nessun titolare è in vigore.');
        expect(riquadro()).toContain('→ Registra il passaggio a chi è entrato (se qualcuno è entrato), poi usa il tasto Ricalcola per includere la quota nel piano.');
        pagina?.unmount();
        pagina = null;
        document.body.innerHTML = '';

        // Con le rate emesse il rimedio sta in mezzo alla frase, all'infinito.
        await monta({ voci: [giorni], ratePure: EMESSA, fermo: FERMO });
        expect(riquadro()).toContain('poi registrare il passaggio a chi è entrato (se qualcuno è entrato) e premere Ricalcola.');
    });
});

