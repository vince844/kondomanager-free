import { Anagrafica } from './anagrafiche';
import { Permission } from './permissions';
import { Role } from './roles';

export interface User {
    id: string
    name: string
    email: string
    suspended_at: string
    verified_at: string
    anagrafica: Anagrafica
    roles: Role[] 
    permissions: Permission[] 
  }