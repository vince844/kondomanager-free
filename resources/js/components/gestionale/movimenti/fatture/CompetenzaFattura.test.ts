// @vitest-environment jsdom

/**
 * Decisione 26 (1.11.0-beta.35) — il pannello della competenza su una fattura pregressa.
 *
 * Con una parte non coperta dai saldi iniziali il periodo è obbligatorio e si chiude prima dell'esercizio: il pannello lo
 * annuncia con la data d'inizio dell'esercizio, prima dell'invio (la regola vera sta in `StoreFatturaRequest`). Senza parte
 * scoperta resta un invito. Il testo generale dice che la competenza guida le rate in ogni piano rate straordinario, anche su
 * gestione ordinaria — fino alla beta.34 diceva «solo su una gestione straordinaria», e dalla decisione 26 era falso.
 *
 * Cosa NON copre: il controllo prima dell'invio (sta in `FatturaRegisterNew.test.ts`) e l'arrivo dell'importo della
 * pregressa dall'import XML.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';
import CompetenzaFattura from './CompetenzaFattura.vue';

const monta = (props: Record<string, unknown>) => mount(CompetenzaFattura, { props: { dal: '', al: '', ...props } });

describe('CompetenzaFattura — la pregressa e il suo periodo (decisione 26)', () => {
    test('pregressa con una parte non coperta: il periodo è obbligatorio, e si dice fino a quando', () => {
        const testo = monta({ pregressa: true, periodoObbligatorio: true, inizioEsercizio: '2026-01-01' }).text();
        expect(testo).toContain('il periodo in cui il costo è maturato è obbligatorio');
        expect(testo).toContain('si chiude prima del 01/01/2026');
    });

    test('pregressa tutta coperta: solo un invito, niente obbligo', () => {
        const testo = monta({ pregressa: true, periodoObbligatorio: false }).text();
        expect(testo).not.toContain('è obbligatorio');
        expect(testo).toContain('se una parte non è coperta dai saldi iniziali');
    });

    test('il testo generale non dice più «solo su una gestione straordinaria»', () => {
        const testo = monta({}).text();
        expect(testo).toContain('anche su una gestione ordinaria');
        expect(testo).not.toContain('Guida le rate solo se');
    });
});
