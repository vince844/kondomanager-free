import { 
    CircleCheck,
    CircleX, 
    History, 
    CircleArrowUp,
    CircleArrowRight,
    CircleArrowDown,
    CircleAlert
} from 'lucide-vue-next';
import type { PriorityType, StatoType, PublishedType } from '@/types/segnalazioni';
  
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
  
  export const statoConstants: StatoType[] = [
    { 
      value: 'aperta', 
      label: 'Aperta',
      icon: CircleCheck,
      colorClass: 'text-green-500 bg-transparent'
    },
    { 
      value: 'in lavorazione', 
      label: 'In lavorazione',
      icon: History,
      colorClass: 'text-yellow-500 bg-transparent'
    },
    { 
      value: 'chiusa', 
      label: 'Chiusa',
      icon: CircleX,
      colorClass: 'text-gray-500 bg-transparent'
    },
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
  