import { Anagrafica } from './anagrafiche';

export interface Segnalazione {
    id: string;
    subject: string;
    description: string;
    created_by: string;
    assigned_to: string;
    condominio_id: string;
    priority: 'bassa' | 'media' | 'alta' | 'urgente';  
    stato: 'aperta' | 'in lavorazione' | 'chiusa';  
    is_resolved: boolean;
    is_locked: boolean;
    is_featured: boolean;
    is_private: boolean;
    is_published: boolean;
    is_approved: boolean;
    can_comment: boolean; 
    anagrafiche: Anagrafica[];
  }