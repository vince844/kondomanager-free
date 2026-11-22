// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Coda 693 (decisioni 532 e 533): la finestra «Configura nuova voce di spesa» del pulsante «Fuori preventivo».
 *
 * - (a) Con «Intervento d'urgenza» il «Giustificativo dell'urgenza (art. 1135 c.c.)» si vede ed è obbligatorio con ogni strategia, anche con «Usa fondo
 *   riserva», con sotto la frase della decisione 533; la motivazione che la finestra emette è quella scritta, e con la delibera non emette un testo scritto
 *   prima e poi nascosto. Dopo un rifiuto del server la pagina la riapre con i dati di prima (`precedente`).
 * - (c) In fondo alla finestra, al posto di «Destinazione: Piano ORDINARIO/STRAORDINARIO», la gestione della fattura e che cosa succede, con le frasi della
 *   decisione 533, in testo normale.
 * - Seconda passata (risposte di chi coordina, 18/11/2026): la finestra agli standard delle pagine (maiuscole solo a inizio frase con le stesse parole,
 *   niente scritte sotto i 12 px né in maiuscolo, i cartellini del fondo `rounded-md`, il tema scuro su ogni colore), e «Usa fondo riserva» spento, con la
 *   frase del perché, quando la fattura ha una riga addebitata a un'unità (come nella finestra dello sforo); il server la rifiuta comunque.
 *
 * La finestra è quella vera; il menu (vue-select) è uno stub che mostra le sue voci con lo spazio «option» della finestra (i cartellini del fondo), e i
 * valori dei menu si scrivono nello stato della finestra come li scriverebbe il menu.
 *
 * Cosa resta scoperto: la pagina che apre la finestra e manda i dati (`FatturaRegisterNewFuoriPreventivo.test.ts`); il server (`FuoriPreventivoFinestraTest`);
 * il colore e la larghezza a 375 px, che si guardano a video.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

// Il menu finto al posto di vue-select (che non ha un nome con cui sostituirlo): le sue voci con lo spazio «option» della finestra, quando c'è (i cartellini
// del fondo), o con la loro etichetta.
vi.mock('vue-select', () => ({
    default: {
        name: 'MenuFinto',
        props: ['modelValue', 'options', 'label'],
        template: `<div class="menu-stub">
            <div v-for="(o, i) in (options ?? [])" :key="i" class="menu-voce"><slot name="option" v-bind="o">{{ o[label ?? 'label'] }}</slot></div>
        </div>`,
    },
}));

// Con l'origine «Delibera assembleare» la finestra mostra «Scegli la delibera», che legge i permessi dalla pagina (1.12.0-beta.13): la pagina finta ha
// quello per vedere le assemblee. Il menu delle delibere non si apre in queste prove.
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: ['Vede le assemblee'] } } } }),
}));

import ModalSpesaImprevista from './ModalSpesaImprevista.vue';

const FONDO = { id: 90, nome: 'Fondo di riserva', saldo_attuale: 500_000, sottotipo_fondo: 'generico', is_override_assemblea: false, is_utilizzabile_per_imprevisti: true };

let finestra: VueWrapper | null = null;

async function apri(props: Record<string, unknown> = {}) {
    finestra = mount(ModalSpesaImprevista, {
        attachTo: document.body,
        props: {
            show: false, fornitoreNome: 'Idraulica Srl', condominioId: 28, fondiRiserva: [FONDO], importoImprevisto: 122_000,
            gestione: { nome: 'Gestione ordinaria 2026', tipo: 'ordinaria' }, ...props,
        },
    });
    // La finestra si prepara quando si apre, come nella pagina.
    await finestra.setProps({ show: true });
    await flushPromises();

    return finestra;
}

const stato = () => (finestra!.vm as unknown as { data: Record<string, unknown> }).data;
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');
const conferma = () => [...document.body.querySelectorAll('button')].find((b) => /Conferma voce/i.test(b.textContent ?? '')) as HTMLButtonElement;
const giustificativo = () => document.body.querySelector('textarea') as HTMLTextAreaElement | null;

async function compila(origine: 'gestione_corrente' | 'delibera_assembleare', strategia: string, fondo: number | null = null) {
    stato().tabella_millesimale_id = 3;
    stato().origine_decisionale = origine;
    stato().strategia_rientro = strategia;
    await flushPromises();
    stato().fondo_patrimoniale_id = fondo;
    await flushPromises();
}

async function scrivi(testoScritto: string) {
    const t = giustificativo()!;
    t.value = testoScritto;
    t.dispatchEvent(new Event('input'));
    await flushPromises();
}

afterEach(() => {
    finestra?.unmount();
    finestra = null;
    document.body.innerHTML = '';
});

describe('693 (a) — il giustificativo dell\'urgenza con ogni strategia', () => {
    test('urgenza con «Usa fondo riserva»: il giustificativo si vede, con la frase della decisione 533, ed è obbligatorio', async () => {
        await apri();
        await compila('gestione_corrente', 'fondo_riserva', FONDO.id);

        expect(giustificativo(), 'con il fondo il giustificativo non si vede').not.toBeNull();
        expect(testo()).toContain('Giustificativo dell\'urgenza (art. 1135 c.c.)');
        expect(testo()).toContain('Serve anche quando la spesa la copre il fondo: è la ragione dell\'urgenza, da riferire alla prossima assemblea.');
        expect(conferma().disabled, 'senza giustificativo la voce si conferma').toBe(true);

        await scrivi('Perdita improvvisa dalla colonna di scarico');
        expect(conferma().disabled).toBe(false);
    });

    test('urgenza con «Usa fondo riserva»: la finestra emette la motivazione scritta, nella motivazione dell\'urgenza e in quella dello sforo', async () => {
        await apri();
        await compila('gestione_corrente', 'fondo_riserva', FONDO.id);
        await scrivi('Perdita improvvisa dalla colonna di scarico');
        conferma().click();
        await flushPromises();

        const emesso = finestra!.emitted('confirm')?.[0]?.[0] as Record<string, unknown>;
        expect(emesso).toMatchObject({
            origine_decisionale: 'gestione_corrente', strategia_rientro: 'fondo_riserva', fondo_patrimoniale_id: FONDO.id, is_ordinario: true,
            motivazione_urgenza: 'Perdita improvvisa dalla colonna di scarico', motivazione_sforo: 'Perdita improvvisa dalla colonna di scarico',
        });
    });

    test('controprova — urgenza con il conguaglio: il giustificativo c\'era già ed era obbligatorio', async () => {
        await apri();
        await compila('gestione_corrente', 'conguaglio_fine_anno');

        expect(giustificativo()).not.toBeNull();
        expect(conferma().disabled).toBe(true);
    });

    test('con la delibera il giustificativo non si vede, e un testo scritto prima con l\'urgenza non parte', async () => {
        await apri();
        await compila('gestione_corrente', 'rata_integrativa');
        await scrivi('Scritto con l\'urgenza, poi cambiata in delibera');
        await compila('delibera_assembleare', 'rata_integrativa');

        expect(giustificativo()).toBeNull();
        conferma().click();
        await flushPromises();

        const emesso = finestra!.emitted('confirm')?.[0]?.[0] as Record<string, unknown>;
        expect(emesso).toMatchObject({ origine_decisionale: 'delibera_assembleare', is_ordinario: false, motivazione_urgenza: '', motivazione_sforo: '' });
    });

    test('riaperta dopo un rifiuto del server, la finestra riparte dai dati di prima', async () => {
        const prima = {
            nome_voce: 'Riparazione colonna', tabella_millesimale_id: 3, percentuale_proprietario: 100, percentuale_inquilino: 0, percentuale_usufruttuario: 0,
            show_advanced_percentages: false, origine_decisionale: 'gestione_corrente', data_assemblea: '', strategia_rientro: 'fondo_riserva', fondo_patrimoniale_id: FONDO.id,
            motivazione_urgenza: 'Perdita improvvisa dalla colonna di scarico', tipo_ripartizione: 'millesimale', is_ordinario: true, richiede_copertura: true,
            motivazione_sforo: 'Perdita improvvisa dalla colonna di scarico',
        };
        await apri({ precedente: prima });

        expect(stato()).toMatchObject({ nome_voce: 'Riparazione colonna', strategia_rientro: 'fondo_riserva', fondo_patrimoniale_id: FONDO.id,
            motivazione_urgenza: 'Perdita improvvisa dalla colonna di scarico' });
        expect(giustificativo()!.value).toBe('Perdita improvvisa dalla colonna di scarico');
    });
});

describe('693 (c) — in fondo alla finestra la gestione della fattura e che cosa succede', () => {
    const riga = () => document.body.querySelector('[data-test="gestione-della-spesa"]') as HTMLElement | null;

    test('ordinaria, urgenza e fondo: «Gestione ordinaria 2026 · coperta dal fondo «Fondo di riserva»», non «Destinazione: Piano ORDINARIO»', async () => {
        await apri();
        await compila('gestione_corrente', 'fondo_riserva', FONDO.id);

        expect(testo()).not.toContain('Destinazione');
        expect(riga()?.textContent?.replace(/\s+/g, ' ').trim()).toBe('Gestione ordinaria 2026 · coperta dal fondo «Fondo di riserva»');
    });

    test('straordinaria, urgenza e rata: si mette a rate con «Spese fuori preventivo», sulla gestione della fattura e non «ordinario»', async () => {
        await apri({ gestione: { nome: 'Lavori balcone 2026', tipo: 'straordinaria' } });
        await compila('gestione_corrente', 'rata_integrativa');

        expect(riga()?.textContent?.replace(/\s+/g, ' ').trim())
            .toBe('Gestione straordinaria «Lavori balcone 2026» · si mette a rate con «Spese fuori preventivo» nel nuovo piano rate');
    });

    test('il conguaglio: «resta nel conguaglio del rendiconto»; con la delibera la gestione è sempre quella della fattura', async () => {
        await apri({ gestione: { nome: 'Lavori balcone 2026', tipo: 'straordinaria' } });
        await compila('delibera_assembleare', 'conguaglio_fine_anno');

        expect(riga()?.textContent?.replace(/\s+/g, ' ').trim()).toBe('Gestione straordinaria «Lavori balcone 2026» · resta nel conguaglio del rendiconto');
    });

    test('testo normale: niente maiuscolo, almeno 12 px, con il tema scuro', async () => {
        await apri();
        await compila('gestione_corrente', 'conguaglio_fine_anno');

        const classi = riga()!.className;
        expect(classi).not.toMatch(/uppercase|text-\[(9|10|11)px\]/);
        expect(classi).toMatch(/text-(xs|sm)/);
        expect(classi).toMatch(/dark:text-/);
    });
});

describe('693, seconda passata — la finestra agli standard delle pagine', () => {
    const finestraVera = () => document.body.querySelector('.fixed.inset-0') as HTMLElement;

    /** Le classi che vietano gli standard (sotto i 12 px, maiuscolo) e i colori chiari senza il loro colore del tema scuro. */
    function fuoriStandard(): string[] {
        const errori: string[] = [];
        const chiari = /^(bg|text|border)-(white|slate|gray|amber|blue|emerald|rose|purple|indigo)(-(50|100|200|300|400|500|600|700|800|900))?(\/\d+)?$/;
        for (const el of [finestraVera(), ...finestraVera().querySelectorAll('*')]) {
            const classi = (el.getAttribute('class') ?? '').split(/\s+/).filter(Boolean);
            for (const c of classi) {
                if (/^text-\[(8|9|10|11)px\]$/.test(c) || c === 'uppercase') errori.push(`${el.tagName.toLowerCase()} «${(el.textContent ?? '').trim().slice(0, 40)}»: ${c}`);
            }
            for (const c of classi) {
                const m = c.match(chiari);
                if (!m || c === 'text-white' || /^bg-(amber|blue|emerald|rose|purple|indigo)-(600|700|800|900)$/.test(c)) continue;
                if (!classi.some((d) => d.startsWith(`dark:${m[1]}-`))) {
                    errori.push(`${el.tagName.toLowerCase()} «${(el.textContent ?? '').trim().slice(0, 40)}»: ${c} senza dark:${m[1]}-`);
                }
            }
        }

        return [...new Set(errori)];
    }

    test('le etichette con le maiuscole solo a inizio frase, con le stesse parole', async () => {
        await apri();
        await compila('gestione_corrente', 'rata_integrativa');

        for (const etichetta of ['Configura nuova voce di spesa', '1. Strategia di copertura per € 1.220,00', 'Rata integrativa', 'Usa fondo riserva',
            '2. Dati contabili e legali', 'Origine dell\'incarico', 'Conferma voce']) {
            expect(testo()).toContain(etichetta);
        }
        for (const prima of ['Configura Nuova Voce di Spesa', 'Strategia di Copertura', 'Rata Integrativa', 'Usa Fondo Riserva', 'Dati Contabili e Legali',
            'Origine dell\'Incarico', 'Conferma Voce']) {
            expect(testo()).not.toContain(prima);
        }
        const origini = finestra!.findAllComponents({ name: 'MenuFinto' }).map((m) => (m.props('options') ?? []).map((o: { label?: string }) => o.label)).flat();
        expect(origini).toEqual(expect.arrayContaining(['Intervento d\'urgenza (art. 1135 c.c.)', 'Delibera assembleare']));

        await compila('delibera_assembleare', 'rata_integrativa');
        expect(testo()).toContain('Data assemblea (raccomandata)');
    });

    test('niente scritte sotto i 12 px né in maiuscolo, e il tema scuro su ogni colore: con l\'urgenza e il fondo, con il menu del fondo aperto', async () => {
        await apri({ fondiRiserva: [FONDO, { ...FONDO, id: 91, nome: 'Fondo lavori', is_override_assemblea: true, saldo_attuale: 1_000 }] });
        await compila('gestione_corrente', 'fondo_riserva', 91);

        expect(fuoriStandard()).toEqual([]);
    });

    test('lo stesso con la delibera, la data dell\'assemblea e le percentuali aperte (con la somma sbagliata)', async () => {
        await apri();
        await compila('delibera_assembleare', 'conguaglio_fine_anno');
        stato().show_advanced_percentages = true;
        stato().percentuale_inquilino = 10;
        await flushPromises();

        expect(fuoriStandard()).toEqual([]);
    });

    test('i cartellini del fondo («Libero», «Sbloccato (deroga)») sono rounded-md, non pillole', async () => {
        await apri({ fondiRiserva: [FONDO, { ...FONDO, id: 91, nome: 'Fondo lavori', is_override_assemblea: true }] });
        await compila('gestione_corrente', 'fondo_riserva', FONDO.id);

        const cartellini = [...finestraVera().querySelectorAll('span')].filter((s) => /^(Libero|Sbloccato \(deroga\))$/.test((s.textContent ?? '').trim()));
        expect(cartellini).toHaveLength(2);
        for (const c of cartellini) {
            expect(c.className).toMatch(/\brounded-md\b/);
            expect(c.className).not.toMatch(/rounded-full/);
        }
    });
});

describe('693, seconda passata — «Usa fondo riserva» con una riga addebitata a un\'unità', () => {
    const sceltaFondo = () => document.body.querySelector('input[type="radio"][value="fondo_riserva"]') as HTMLInputElement;

    test('con una riga di un\'unità il fondo è spento, con la frase del perché', async () => {
        await apri({ hasSpesePrivate: true });
        await compila('gestione_corrente', 'conguaglio_fine_anno');

        expect(sceltaFondo().disabled).toBe(true);
        expect(testo()).toContain('Non disponibile: la fattura ha una riga addebitata a un\'unità, e il fondo di riserva copre solo spese comuni.');
    });

    test('il fondo scelto prima (dopo un rifiuto) con una riga di un\'unità torna ad «Attesa conguaglio», e la voce non si conferma con il fondo', async () => {
        await apri({ hasSpesePrivate: true, precedente: { nome_voce: 'Riparazione colonna', tabella_millesimale_id: 3, origine_decisionale: 'delibera_assembleare',
            strategia_rientro: 'fondo_riserva', fondo_patrimoniale_id: FONDO.id } });

        expect(stato().strategia_rientro).toBe('conguaglio_fine_anno');
        expect(stato().fondo_patrimoniale_id).toBeNull();

        stato().strategia_rientro = 'fondo_riserva';
        stato().fondo_patrimoniale_id = FONDO.id;
        await flushPromises();
        expect(conferma().disabled).toBe(true);
    });

    test('controprova — senza righe di un\'unità il fondo si sceglie, e non c\'è la frase', async () => {
        await apri();
        await compila('gestione_corrente', 'conguaglio_fine_anno');

        expect(sceltaFondo().disabled).toBe(false);
        expect(testo()).not.toContain('Non disponibile');
    });
});
