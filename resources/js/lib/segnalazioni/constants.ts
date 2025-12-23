import { CircleCheck, CircleX, History, CircleArrowUp, CircleArrowRight, CircleArrowDown, CircleAlert } from 'lucide-vue-next';
import type { PriorityType, StatoType, PublishedType } from '@/types/segnalazioni';
  
  export const priorityConstants: PriorityType[] = [
    { 
      value: 'bassa', 
      label: 'segnalazioni.priority.low', 
      icon: CircleArrowDown,
      colorClass: 'text-green-500 bg-transparent'
    },
    { 
      value: 'media', 
      label: 'segnalazioni.priority.medium', 
      icon: CircleArrowRight,
      colorClass: 'text-blue-500 bg-transparent'
    },
    { 
      value: 'alta', 
      label: 'segnalazioni.priority.high', 
      icon: CircleArrowUp,
      colorClass: 'text-orange-500 bg-transparent'
    },
    { 
      value: 'urgente', 
      label: 'segnalazioni.priority.urgent', 
      icon: CircleAlert, 
      colorClass: 'text-red-500 bg-transparent'
    }
  ];
  
  export const statoConstants: StatoType[] = [
    { 
      value: 'aperta', 
      label: 'segnalazioni.status.open',
      icon: CircleCheck,
      colorClass: 'text-green-500 bg-transparent'
    },
    { 
      value: 'in lavorazione', 
      label: 'segnalazioni.status.in_progress',
      icon: History,
      colorClass: 'text-yellow-500 bg-transparent'
    },
    { 
      value: 'chiusa', 
      label: 'segnalazioni.status.closed',
      icon: CircleX,
      colorClass: 'text-gray-500 bg-transparent'
    },
  ];
  
  export const publishedConstants: PublishedType[] = [
    { 
      value: false, 
      label: 'segnalazioni.visibility.private',
      icon: CircleX, 
      colorClass: 'text-red-500 bg-transparent'
    },
    { 
      value: true, 
      label: 'segnalazioni.visibility.public',
      icon: CircleCheck, 
      colorClass: 'text-green-500 bg-transparent'
    }
  ];
  