import { Lock, Scale, Users } from 'lucide-vue-next';
import type { GuideItem } from '@/components/PageHeaderGuide.vue';

/**
 * Le tre card dell'intestazione, uguali su tutte e cinque le schermate.
 *
 * Si ripetono di proposito invece di comparire solo all'ingresso: sono le tre cose che tolgono
 * la paura di versare in un software sconosciuto la contabilità di qualcun altro, e chi è a metà
 * strada ha bisogno di rileggerle lì, non di ricordarsele.
 */
export const guideImport: GuideItem[] = [
  {
    title: 'Niente entra finché non confermi',
    description: 'Le prime tre schermate leggono e basta: puoi ricaricare i file quante volte vuoi.',
    icon: Lock,
    colorVariant: 'emerald',
  },
  {
    title: 'I saldi devono quadrare',
    description: 'Il totale di controllo lo leggo dentro il tuo riparto: se non torna, i saldi non entrano.',
    icon: Scale,
    colorVariant: 'blue',
  },
  {
    title: 'Le scelte le fai tu',
    description: 'Duplicati e nomi doppi te li chiedo prima di scrivere, non dopo.',
    icon: Users,
    colorVariant: 'amber',
  },
];
