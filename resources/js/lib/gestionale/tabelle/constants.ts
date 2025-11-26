import { Percent, Flame, Droplets } from 'lucide-vue-next';
import type { StatusType } from '@/types/gestionale/tabelle';

export const typeConstants: StatusType[] = [
  { 
    value: 'standard', 
    label: 'Standard',
    icon: Percent, 
    colorClass: 'text-green-500 bg-transparent'
  },
  { 
    value: 'ascensore', 
    label: 'Ascensore',
    icon: Percent, 
    colorClass: 'text-green-500 bg-transparent'
  },
  { 
    value: 'riscaldamento', 
    label: 'Riscaldamento',
    icon: Flame, 
    colorClass: 'text-orange-500 bg-transparent'
  },
  { 
    value: 'acqua', 
    label: 'Acqua',
    icon: Droplets, 
    colorClass: 'text-blue-500 bg-transparent'
  },
  { 
    value: 'scale', 
    label: 'Scale',
    icon: Percent, 
    colorClass: 'text-green-500 bg-transparent'
  },
  { 
    value: 'speciale', 
    label: 'Speciale',
    icon: Percent, 
    colorClass: 'text-green-500 bg-transparent'
  },
  { 
    value: 'altro', 
    label: 'Altro',
    icon: Percent, 
    colorClass: 'text-green-500 bg-transparent'
  }
];
  