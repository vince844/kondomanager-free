import { CircleArrowUp, CircleArrowRight, CircleArrowDown, CircleAlert, CircleCheck, CircleX } from 'lucide-vue-next';
import type { PriorityType, PublishedType } from '@/types/comunicazioni';
  
  export const priorityConstants: PriorityType[] = [
    { 
      value: 'bassa', 
      label: 'comunicazioni.priority.low', 
      icon: CircleArrowDown,
      colorClass: 'text-green-500 bg-transparent'
    },
    { 
      value: 'media', 
      label: 'comunicazioni.priority.medium', 
      icon: CircleArrowRight,
      colorClass: 'text-blue-500 bg-transparent'
    },
    { 
      value: 'alta', 
      label: 'comunicazioni.priority.high', 
      icon: CircleArrowUp,
      colorClass: 'text-orange-500 bg-transparent'
    },
    { 
      value: 'urgente', 
      label: 'comunicazioni.priority.urgent', 
      icon: CircleAlert, 
      colorClass: 'text-red-500 bg-transparent'
    }
  ];
  
  export const publishedConstants: PublishedType[] = [
    { 
      value: false, 
      label: 'comunicazioni.visibility.private',
      icon: CircleX, 
      colorClass: 'text-red-500 bg-transparent'
    },
    { 
      value: true, 
      label: 'comunicazioni.visibility.public',
      icon: CircleCheck, 
      colorClass: 'text-green-500 bg-transparent'
    }
  ];
  