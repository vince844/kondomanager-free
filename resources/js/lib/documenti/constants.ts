import { CircleCheck,CircleX } from 'lucide-vue-next';
import type { PublishedType } from '@/types/documenti';

// Coda 306: la tendina e il filtro di `is_published` dicono la pubblicazione («Bozza» o
// la pubblicazione), non la privacy: i documenti la privacy non la salvano, e qui non si dice
// mai «Privato».
export const publishedConstants: PublishedType[] = [
  { 
    value: false, 
    label: 'documenti.publication.draft',
    icon: CircleX, 
    colorClass: 'text-red-500 bg-transparent'
  },
  { 
    value: true, 
    label: 'documenti.publication.published',
    icon: CircleCheck, 
    colorClass: 'text-green-500 bg-transparent'
  }
];
  