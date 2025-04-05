
import { Anagrafica } from './anagrafiche';

export interface User {
    id: string
    name: string
    email: string
    suspended_at: string
    anagrafica: Anagrafica
    roles: [] 
    permissions: [] 
  }