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
      colorClass: 'text-green-500 bg-green-50'
    },
    { 
      value: 'media', 
      label: 'Media', 
      icon: CircleArrowRight,
      colorClass: 'text-blue-500 bg-blue-50'
    },
    { 
      value: 'alta', 
      label: 'Alta', 
      icon: CircleArrowUp,
      colorClass: 'text-orange-500 bg-orange-50'
    },
    { 
      value: 'urgente', 
      label: 'Urgente', 
      icon: CircleAlert, 
      colorClass: 'text-red-500 bg-red-50'
    }
  ];
  
  export const statoConstants: StatoType[] = [
    { 
      value: 'aperta', 
      label: 'Aperta',
      icon: CircleCheck,
      colorClass: 'text-green-500 bg-green-50'
    },
    { 
      value: 'in lavorazione', 
      label: 'In lavorazione',
      icon: History,
      colorClass: 'text-yellow-500 bg-yellow-50'
    },
    { 
      value: 'chiusa', 
      label: 'Chiusa',
      icon: CircleX,
      colorClass: 'text-gray-500 bg-gray-50'
    },
  ];
  
  export const publishedConstants: PublishedType[] = [
    { 
      value: false, 
      label: 'Bozza',
      icon: CircleX, 
      colorClass: 'text-red-500 bg-red-50'
    },
    { 
      value: true, 
      label: 'Pubblicata',
      icon: CircleCheck, 
      colorClass: 'text-green-500 bg-green-50'
    }
  ];
  