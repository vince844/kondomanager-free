import { Percent, Flame, Droplets, ArrowUpDown, Layers, Sun, Star, MoreHorizontal } from 'lucide-vue-next';
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
    icon: ArrowUpDown, 
    colorClass: 'text-indigo-500 bg-transparent'
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
    icon: Layers, 
    colorClass: 'text-purple-500 bg-transparent'
  },
  { 
    value: 'lastrico', 
    label: 'Lastrico',
    icon: Sun, 
    colorClass: 'text-amber-500 bg-transparent'
  },
  { 
    value: 'speciale', 
    label: 'Speciale',
    icon: Star, 
    colorClass: 'text-pink-500 bg-transparent'
  },
  { 
    value: 'altro', 
    label: 'Altro',
    icon: MoreHorizontal, 
    colorClass: 'text-slate-500 bg-transparent'
  }
];