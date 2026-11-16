/**
 * Le tre card in alto della pagina «Rate scadute», come nelle altre pagine: un titolo, una frase, un'icona, un colore. La guida intera è nel cassetto
 * («Guida»). Le frasi dicono che cosa fa la pagina: la data dell'elenco, le colonne, la vista «In scadenza nei prossimi N giorni»; le lettere e la
 * spesa del ritardo per le persone scelte, la lettera e l'accordo dalla riga, gli interessi dai suggerimenti; le regole di «Recupero dei crediti».
 */
import { CalendarClock, Mail, Scale } from 'lucide-vue-next';
import type { GuideItem } from '@/components/PageHeaderGuide.vue';

export const GUIDE_RATE_SCADUTE: GuideItem[] = [
    {
        title: 'Chi deve pagare',
        description: 'Le persone con rate scadute e non versate alla data scelta: lo scaduto, da quanti giorni, l\'ultima lettera e la data dei sei mesi per agire. '
            + 'Con «In scadenza», le rate che stanno per scadere.',
        icon: CalendarClock,
        colorVariant: 'amber',
    },
    {
        title: 'Lettere e azioni',
        description: 'Scegli con i filtri e le caselle, poi prepara le lettere o le spese del ritardo. Dalla riga di una persona, una lettera a lei sola e '
            + 'l\'accordo di rientro; gli interessi si propongono dai suggerimenti in alto.',
        icon: Mail,
        colorVariant: 'blue',
    },
    {
        title: 'Le regole',
        description: 'La tolleranza, il tasso degli interessi e il termine delle lettere vengono da «Recupero dei crediti»; senza regole vale il tasso legale. '
            + 'I suggerimenti in alto ne tengono conto.',
        icon: Scale,
        colorVariant: 'emerald',
    },
];
