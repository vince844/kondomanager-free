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

  // Coda 306: la tendina e il filtro di `is_published` dicono la pubblicazione («Bozza» o
  // la pubblicazione), non la privacy, che è un'indicazione a parte (`is_private`).
  export const publishedConstants: PublishedType[] = [
    { 
      value: false, 
      label: 'comunicazioni.publication.draft',
      icon: CircleX, 
      colorClass: 'text-red-500 bg-transparent'
    },
    { 
      value: true, 
      label: 'comunicazioni.publication.published',
      icon: CircleCheck, 
      colorClass: 'text-green-500 bg-transparent'
    }
  ];
  