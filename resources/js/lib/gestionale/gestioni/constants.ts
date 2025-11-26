import { Sun, Zap } from 'lucide-vue-next';
import type { StatusType } from '@/types/gestionale/gestioni';

  
  export const typeConstants: StatusType[] = [
    { 
      value: 'ordinaria', 
      label: 'Ordinaria',
      icon: Sun, 
      colorClass: 'text-green-500 bg-transparent'
    },
    { 
      value: 'straordinaria', 
      label: 'Straordinaria',
      icon: Zap, 
      colorClass: 'text-yellow-500 bg-transparent'
    }
  ];
  