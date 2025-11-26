import { CircleCheck, CircleX } from 'lucide-vue-next';
import type { StatusType } from '@/types/gestionale/esercizi';

  
  export const statusConstants: StatusType[] = [
    { 
      value: 'aperto', 
      label: 'Aperto',
      icon: CircleCheck, 
      colorClass: 'text-green-500 bg-transparent'
    },
    { 
      value: 'chiuso', 
      label: 'Chiuso',
      icon: CircleX, 
      colorClass: 'text-red-500 bg-transparent'
    }
  ];
  