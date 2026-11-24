// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * 1.12.0-beta.14, tappa 7 (decisione 705) — il pannello «Cosa cambierà» dell'insieme: più persone che escono in un passaggio solo.
 *
 * La forma dei dati è quella che il server manda (§1.9 di `tappa7_progetto.md`), fissata qui: `insieme`, `impronta`, `riferimento`, `anagrafica`,
 * `parti` (una per persona che esce, con il suo pannello intero), `totale` (il riquadro «In tutto»), `obbligati` e `invarianti` (una volta sola),
 * `ordinaria`, `cancello` raggruppato per persona. Il componente non calcola: legge e mostra. Il caso è quello della bozza approvata
 * (`docs/design/beta14_piu_venditori_bozza.html`): Cecilia Angeli e Marta Acciaioli, al 50 % ciascuna dell'Int. 1A, vendono a Luca Toscani con l'atto
 * del 16 novembre 2026; il «Preventivo 2026» ha dodici rate da € 40,00 a testa, undici emesse. Le cifre, rifatte a mano: € 480,00 su 12 quote; 319
 * giorni a chi esce (1 gennaio–15 novembre), 46 a chi entra (16 novembre–31 dicembre); € 480,00 × 46 / 365 = € 60,49, di cui € 40,00 con la rata in
 * bozza del 5 dicembre; il conguaglio di ciascuna è € 20,49 a debito di Luca Toscani e a credito suo; in tutto € 40,98.
 *
 * Presidia: il badge «2 passaggi insieme»; una parte a cassetto per persona (la prima aperta) con il riassunto in riga; le tabelle delle rate di ogni
 * parte, che usano la sezione delle rate del pannello di oggi da sola (`solo-rate`); il riquadro «In tutto»; la frase di chi resta obbligato una sola
 * volta; le teste una volta; una sola casella «Le parti hanno regolato il conguaglio fra loro», per tutte; gli stati di attesa, errore, blocco.
 * La sezione delle rate da sola (`AnteprimaPassaggio` con `solo-rate`) non porta titolo, anagrafica, obbligati, invarianti né la casella della rinuncia.
 *
 * Cosa NON copre: il server (la prova a secco, l'impronta: `Beta14PiuVenditoriTest`); la pagina che incrocia il pannello con il modulo
 * (`PassaggioNewPiuVenditori.test.ts`).
 */

import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h, ref } from 'vue';

import AnteprimaGruppo from './AnteprimaGruppo.vue';
import AnteprimaPassaggio from './AnteprimaPassaggio.vue';

const FRASE_TOTALE = 'In tutto a Luca Toscani: € 40,98 di conguaglio a debito (€ 20,49 da ciascuna) e 2 quote in bozza sulla rata del 5 dicembre 2026, € 40,00 ciascuna, che da ora paga a suo nome.';
const OBBLIGATI = 'Luca Toscani risponde in solido con Cecilia Angeli e Marta Acciaioli per i contributi di questa unità relativi all\'esercizio 2026 e all\'esercizio 2025 (art. 63 co. 4 disp. att. c.c.).';
const TESTE = 'Teste in assemblea, come le conta la sala con la scelta del condominio («una testa»): oggi 9, dopo i passaggi 9.';

/** Il pannello di una persona che esce: 11 quote da € 40,00 emesse, la coppia di € 20,49 con Luca Toscani, la rata in bozza che passa a lui. */
function parteDi(nome: string, id: number) {
    return {
        nudi: null,
        riferimento: { uscente_fino_al: '2026-11-15', entrante_dal: '2026-11-16', frase: `${nome} risulterà titolare fino al 15 novembre 2026 compreso.` },
        anagrafica: { frasi: [`${nome} risulterà titolare fino al 15 novembre 2026 compreso.`], pertinenze: [] },
        rate: {
            stato: 'calcolato',
            emesse: Array.from({ length: 11 }, (_, i) => ({ rata: i + 1, scadenza: `2026-${String(i + 1).padStart(2, '0')}-05`, importo: 4000, importo_formattato: '€ 40,00', intestatario: nome, piano: 'Preventivo 2026', natura: 'ordinaria' })),
            piani_distinti: 1, totale_emesso: 44000, totale_emesso_formattato: '€ 440,00', altre: { quote: 0, intestatari: [] }, morosita: null,
            conguaglio: {
                stato: 'calcolato', anagrafica_uscente_id: id, anagrafica_entrante_id: 9, quote: [],
                per_gestione: [{
                    gestione_id: 1, gestione: 'Ordinaria 2026', immobile_id: 1, immobile_nome: 'Int. 1A', natura: 'ordinaria', gradino: ['gestione'], periodo: null,
                    quote: 11, quota_pura: 44000, pregressi: 0, giorni_uscente: 319, giorni_entrante: 46, giorni_periodo: 365, importo: 2049, importo_formattato: '€ 20,49',
                    non_risolte: 0, escluse: 0, esercizio_id: 1, importo_lordo: 6049, importo_lordo_formattato: '€ 60,49', bozze_passate: 1, bozze_passate_importo: 4000, bozze_passate_formattato: '€ 40,00',
                }],
                coppie: [{ gestione_id: 1, gestione: 'Ordinaria 2026', immobile_id: 1, esercizio_id: 1, importo: 2049, importo_formattato: '€ 20,49', anagrafica_entrante_id: 9, entrante_nome: 'Luca Toscani' }],
                totale_entrante: 2049, totale_entrante_formattato: '€ 20,49', totale_entrante_assoluto_formattato: '€ 20,49',
                riassegnazione: [{ piano_rate_id: 1, piano: 'Preventivo 2026', n: 1, quote: 1, dal: '2026-12-05', al: '2026-12-05', preventivo: 4000, preventivo_formattato: '€ 40,00', pregresso: 0 }],
                pregressi: 0, non_risolte: [], frasi: [],
            },
            piano_da_ricalcolare: null,
            frasi: [`Sulla gestione Ordinaria 2026: credito € 20,49 a ${nome}, debito € 20,49 a Luca Toscani.`],
            frasi_del_conguaglio: [], scelta_del_conguaglio: false,
        },
        obbligati: { frasi: [], copia_autentica_mancante: false },
        invarianti: { frasi: [] },
        cancello: { richiesto: true, motivi: [`11 quote di rate già emesse a ${nome} su questa unità`], informazioni: [], avvisi: [] },
        ordinaria: { applicabile: false, scelta: null, usufruttuario: null, nudo: null, voci: [], frasi: [], frasi_bloccate: [], frasi_altri_usufrutti: [], impronta: null, ereditata: null },
    };
}

/** L'anteprima dell'insieme come la manda il server (§1.9). */
function gruppo(extra: Record<string, unknown> = {}) {
    return {
        insieme: true as const,
        impronta: 'abc123',
        riferimento: { uscente_fino_al: '2026-11-15', entrante_dal: '2026-11-16', frase: 'Cecilia Angeli e Marta Acciaioli risulteranno titolari fino al 15 novembre 2026 compreso. Luca Toscani dal 16 novembre 2026.' },
        anagrafica: { frasi: ['Luca Toscani risulterà titolare dal 16 novembre 2026, come proprietario al 100 %.'], pertinenze: [] },
        parti: [
            { riga_id: 10, anagrafica_id: 1, nome: 'Cecilia Angeli', quota: 50, pertinenze: [], anteprima: parteDi('Cecilia Angeli', 1) },
            { riga_id: 11, anagrafica_id: 2, nome: 'Marta Acciaioli', quota: 50, pertinenze: [], anteprima: parteDi('Marta Acciaioli', 2) },
        ],
        totale: { quota_che_esce: 100, conguaglio_entrante: 4098, conguaglio_entrante_formattato: '€ 40,98', bozze: 2, frase: FRASE_TOTALE },
        obbligati: { frasi: [OBBLIGATI], copia_autentica_mancante: false },
        invarianti: { frasi: [TESTE] },
        ordinaria: { applicabile: false, scelta: null, usufruttuario: null, nudo: null, voci: [], frasi: [], frasi_bloccate: [], frasi_altri_usufrutti: [], impronta: null, ereditata: null },
        cancello: { richiesto: true, gruppi: [{ nome: 'Cecilia Angeli', motivi: ['11 quote di rate già emesse a Cecilia Angeli su questa unità'] }, { nome: 'Marta Acciaioli', motivi: ['11 quote di rate già emesse a Marta Acciaioli su questa unità'] }], informazioni: [], avvisi: [] },
        ...extra,
    };
}

const monta = (props: Record<string, unknown> = {}) => mount(AnteprimaGruppo, { props: { dati: gruppo(), inCorso: false, errore: false, mancante: [], ...props } as never });
const testo = (w: ReturnType<typeof monta>) => w.text().replace(/\s+/g, ' ');

describe('AnteprimaGruppo — l\'insieme di passaggi (tappa 7, decisione 705)', () => {
    test('il titolo «Cosa cambierà» porta il badge «2 passaggi insieme»', () => {
        const w = monta();

        expect(testo(w)).toContain('Cosa cambierà');
        expect(w.find('[data-testid="badge-insieme"]').text()).toBe('2 passaggi insieme');
        expect(w.find('[data-testid="badge-insieme"]').classes()).toContain('rounded-md');
    });

    test('il blocco 1: la frase di tutte con la quota finale di chi entra', () => {
        expect(testo(monta())).toContain('Luca Toscani risulterà titolare dal 16 novembre 2026, come proprietario al 100 %.');
    });

    test('il blocco 2: una parte per ciascuna, la prima aperta, con il riassunto in riga e le sue rate', () => {
        const w = monta();
        const parti = w.findAll('details[data-testid="parte"]');

        expect(parti).toHaveLength(2);
        expect(parti[0].attributes('open')).toBeDefined();
        expect(parti[1].attributes('open')).toBeUndefined();
        expect(parti[0].find('summary').text().replace(/\s+/g, ' ')).toContain('La parte di Cecilia Angeli');
        expect(parti[0].find('summary').text().replace(/\s+/g, ' ')).toContain('50 %');
        expect(parti[0].find('summary').text().replace(/\s+/g, ' ')).toContain('11 quote emesse · € 440,00 · conguaglio € 20,49');
        expect(parti[1].find('summary').text().replace(/\s+/g, ' ')).toContain('La parte di Marta Acciaioli');
        // Dentro, le frasi e le tabelle di oggi: le rate emesse e la rata in bozza che passa a Luca Toscani.
        expect(parti[0].text().replace(/\s+/g, ' ')).toContain('Rata 1');
        expect(parti[0].text().replace(/\s+/g, ' ')).toContain('Sulla gestione Ordinaria 2026: credito € 20,49 a Cecilia Angeli, debito € 20,49 a Luca Toscani.');
        expect(parti[0].text().replace(/\s+/g, ' ')).toContain('Bozze che passano a chi entra');
        expect(parti[1].text().replace(/\s+/g, ' ')).toContain('credito € 20,49 a Marta Acciaioli');
    });

    test('le parti non ripetono il pannello intero: niente titolo, niente «Chi resta obbligato», niente teste, niente casella della rinuncia per parte', () => {
        const w = monta();
        const dentro = w.findAll('details[data-testid="parte"]').map((p) => p.text()).join(' ');

        expect(dentro).not.toContain('Cosa cambierà');
        expect(dentro).not.toContain('Chi resta obbligato');
        expect(dentro).not.toContain('Cosa non cambia');
        expect(dentro).not.toContain('Le parti hanno regolato il conguaglio fra loro');
        expect(w.findAll('input[type="checkbox"], button[role="checkbox"]')).toHaveLength(1);
    });

    test('il riquadro «In tutto» dice quello che arriva a chi entra, con la frase del server', () => {
        const w = monta();

        expect(w.find('[data-testid="in-tutto"]').text().replace(/\s+/g, ' ')).toBe(FRASE_TOTALE);
    });

    test('chi resta obbligato e le teste si dicono una volta sola, con i nomi di tutte', () => {
        const t = testo(monta());

        expect(t.split(OBBLIGATI)).toHaveLength(2);
        expect(t).toContain('Luca Toscani risponde in solido con Cecilia Angeli e Marta Acciaioli');
        expect(t.split(TESTE)).toHaveLength(2);
    });

    test('una casella sola «Le parti hanno regolato il conguaglio fra loro», per tutte, con la ragione che resta nel passaggio', async () => {
        const Contenitore = defineComponent({
            components: { AnteprimaGruppo },
            setup() { return { dati: gruppo(), rinuncia: ref(false), nota: ref('') }; },
            render() { return h(AnteprimaGruppo, { dati: this.dati as never, inCorso: false, errore: false, mancante: [], rinuncia: this.rinuncia, notaRinuncia: this.nota, 'onUpdate:rinuncia': (v: boolean) => { this.rinuncia = v; }, 'onUpdate:notaRinuncia': (v: string) => { this.nota = v; } }); },
        });
        const w = mount(Contenitore);

        expect(w.text()).toContain('Le parti hanno regolato il conguaglio fra loro');
        expect(w.text()).toContain('Vale per tutte le persone che escono');
        await w.find('button[role="checkbox"], input[type="checkbox"]').trigger('click');
        expect((w.vm as unknown as { rinuncia: boolean }).rinuncia).toBe(true);
        expect(w.find('input[placeholder^="Es: regolato"]').exists()).toBe(true);
    });

    test('senza coppie da scrivere la casella della rinuncia non c\'è', () => {
        const senza = gruppo();
        senza.parti.forEach((p) => { (p.anteprima.rate as { conguaglio: unknown }).conguaglio = null; });
        const w = monta({ dati: senza });

        expect(w.text()).not.toContain('Le parti hanno regolato il conguaglio fra loro');
    });

    test('con la rinuncia il badge della sezione dice «regolato fra le parti», altrimenti «conguaglio proposto»', () => {
        expect(testo(monta())).toContain('conguaglio proposto');
        expect(testo(monta({ rinuncia: true }))).toContain('regolato fra le parti');
    });

    test('un piano da ricalcolare in una parte si dice, con la frase del server', () => {
        const d = gruppo();
        (d.parti[1].anteprima.rate as { piano_da_ricalcolare: string | null }).piano_da_ricalcolare = 'Ricalcola prima il piano «Preventivo 2026», poi registra questi passaggi.';
        const w = monta({ dati: d });

        expect(testo(w)).toContain('Ricalcola prima il piano «Preventivo 2026», poi registra questi passaggi.');
        expect(testo(w)).toContain('piano da ricalcolare');
    });

    test('gli stati: in attesa dice che cosa manca, errore non mostra cifre, bloccato aspetta il campo', () => {
        const attesa = monta({ dati: null, mancante: ['la data dell\'atto', 'chi entra'] });
        expect(testo(attesa)).toContain('mancano:');
        expect(testo(attesa)).toContain('la data dell\'atto');
        expect(attesa.find('[data-testid="badge-insieme"]').exists()).toBe(false);

        const errore = monta({ errore: true });
        expect(testo(errore)).toContain('Non riesco a calcolare le conseguenze: riprova.');
        expect(testo(errore)).not.toContain('€ 40,98');

        const bloccato = monta({ dati: null, bloccato: true });
        expect(testo(bloccato)).toContain('Un dato del modulo non è accettabile.');
    });

    test('niente testo sotto i 12 px nel pannello nuovo (le tabelle di ogni parte sono quelle di oggi, riusate)', () => {
        const copia = monta().element.cloneNode(true) as HTMLElement;
        copia.querySelectorAll('details[data-testid="parte"] > div').forEach((d) => d.remove());

        expect(copia.innerHTML).not.toMatch(/text-\[(9|10|11)px\]/);
    });
});

describe('AnteprimaPassaggio con solo-rate — la sezione delle rate da sola', () => {
    const solo = () => mount(AnteprimaPassaggio, { props: { dati: parteDi('Cecilia Angeli', 1) as never, inCorso: false, errore: false, mancante: [], tipo: 'vendita', soloRate: true } });

    test('porta le tabelle, le frasi e il conguaglio, senza il guscio del pannello né gli altri blocchi', () => {
        const t = solo().text().replace(/\s+/g, ' ');

        expect(t).toContain('Rata 1');
        expect(t).toContain('Emesso a Cecilia Angeli · 11 quote');
        expect(t).toContain('Sulla gestione Ordinaria 2026: credito € 20,49 a Cecilia Angeli, debito € 20,49 a Luca Toscani.');
        expect(t).toContain('Bozze che passano a chi entra');
        expect(t).toContain('Debito a chi entra, credito a chi esce');
        expect(t).not.toContain('Cosa cambierà');
        expect(t).not.toContain('1. Anagrafica');
        expect(t).not.toContain('2. Rate già emesse');
        expect(t).not.toContain('3. Chi resta obbligato');
        expect(t).not.toContain('4. Cosa non cambia');
        expect(t).not.toContain('Le parti hanno regolato il conguaglio fra loro');
        expect(solo().find('aside').exists()).toBe(false);
    });

    test('senza solo-rate il pannello di oggi resta quello di prima, con la casella e tutti i blocchi', () => {
        const w = mount(AnteprimaPassaggio, { props: { dati: parteDi('Cecilia Angeli', 1) as never, inCorso: false, errore: false, mancante: [], tipo: 'vendita' } });
        const t = w.text().replace(/\s+/g, ' ');

        expect(w.find('aside').exists()).toBe(true);
        expect(t).toContain('Cosa cambierà');
        expect(t).toContain('3. Chi resta obbligato');
        expect(t).toContain('Le parti hanno regolato il conguaglio fra loro');
    });
});
