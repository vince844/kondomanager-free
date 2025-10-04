
import { CircleCheck, CircleX } from 'lucide-vue-next';
import { VisibilityType } from '@/types/eventi';
  
export const visibilityConstants: VisibilityType[] = [
  { 
    value: 'hidden', 
    label: 'Nascosta',
    icon: CircleX, 
    colorClass: 'text-red-500 bg-transparent'
  },
  { 
    value: 'public', 
    label: 'Pubblica', 
    icon: CircleCheck, 
    colorClass: 'text-green-500 bg-transparent'
  }
];
  