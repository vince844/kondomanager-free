/**
 * La guida delle stampe: la frase sul piede dice quello che i modelli fanno.
 *
 * Si leggono i sorgenti, come nei test delle altre guide: conta la frase, e che i modelli la sostengano.
 */
import { describe, expect, test } from 'vitest';
import fs from 'node:fs';

const guida = fs.readFileSync('resources/js/components/guides/PrintSetupGuide.vue', 'utf8').replace(/\s+/g, ' ');
const frontespizio = fs.readFileSync('resources/views/pdf/gestionale/rendiconto_frontespizio.blade.php', 'utf8');
const f24 = fs.readFileSync('resources/views/pdf/gestionale/modello_f24.blade.php', 'utf8');

describe('PrintSetupGuide', () => {
    test('il frontespizio del fascicolo ha il piede, e la guida non dice il contrario', () => {
        expect(frontespizio).toContain("@include('pdf._carta.piede_inter'");
        expect(guida).not.toContain('Il frontespizio del fascicolo e il modello F24 non hanno il piede');
        expect(guida).toContain('Anche il frontespizio del fascicolo ha il piede, con il numero di pagina del fascicolo');
    });

    test('il modello F24 resta senza la nota legale e il numero di pagina (ha solo il titolo della copia), e la guida lo dice', () => {
        expect(f24).not.toContain('piede_inter');
        expect(f24).not.toContain('nota_legale_stampe');
        expect(guida).toContain('il modello F24 non ha né la nota legale né il numero di pagina');
        expect(guida).not.toContain('il modello F24 non ha il piede');
    });
});
