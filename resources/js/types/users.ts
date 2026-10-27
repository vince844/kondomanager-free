import { Anagrafica } from './anagrafiche';
import { Permission } from './permissions';
import { Role } from './roles';

export interface User {
    id: string
    name: string
    email: string
    suspended_at: string
    is_ultimo_amministratore?: boolean
    is_self?: boolean
    /** L'account è nella portata di chi guarda (Coda 258): se no, il menù non offre i comandi che il server rifiuterebbe. */
    gestibile?: boolean
    email_verified_at: string | null
    anagrafica: Anagrafica
    roles: Role[] 
    permissions: Permission[] 
  }