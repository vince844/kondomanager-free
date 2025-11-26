import { CircleArrowUp, CircleArrowRight, CircleArrowDown, CircleAlert, CircleCheck, CircleX } from 'lucide-vue-next';
import type { PriorityType, PublishedType } from '@/types/comunicazioni';
  
  export const priorityConstants: PriorityType[] = [
    { 
      value: 'bassa', 
      label: 'Bassa', 
      icon: CircleArrowDown,
      colorClass: 'text-green-500 bg-transparent'
    },
    { 
      value: 'media', 
      label: 'Media', 
      icon: CircleArrowRight,
      colorClass: 'text-blue-500 bg-transparent'
    },
    { 
      value: 'alta', 
      label: 'Alta', 
      icon: CircleArrowUp,
      colorClass: 'text-orange-500 bg-transparent'
    },
    { 
      value: 'urgente', 
      label: 'Urgente', 
      icon: CircleAlert, 
      colorClass: 'text-red-500 bg-transparent'
    }
  ];
  
  export const publishedConstants: PublishedType[] = [
    { 
      value: false, 
      label: 'Bozza',
      icon: CircleX, 
      colorClass: 'text-red-500 bg-transparent'
    },
    { 
      value: true, 
      label: 'Pubblicata',
      icon: CircleCheck, 
      colorClass: 'text-green-500 bg-transparent'
    }
  ];
  