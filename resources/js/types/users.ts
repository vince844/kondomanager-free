
import { Anagrafica } from './anagrafiche';

export interface User {
    id: string
    name: string
    email: string
    anagrafica: Anagrafica
    roles: [] 
    permissions: [] 
  }