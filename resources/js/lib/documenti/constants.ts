import { CircleCheck,CircleX } from 'lucide-vue-next';
import type { PublishedType } from '@/types/documenti';
  
export const publishedConstants: PublishedType[] = [
  { 
    value: false, 
    label: 'documenti.visibility.private',
    icon: CircleX, 
    colorClass: 'text-red-500 bg-transparent'
  },
  { 
    value: true, 
    label: 'documenti.visibility.public',
    icon: CircleCheck, 
    colorClass: 'text-green-500 bg-transparent'
  }
];
  