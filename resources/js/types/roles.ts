import { Permission } from './permissions';

export interface Role {
    id: number
    name: string
    description: string
    users_count: number
    is_protected: boolean
    /** È il ruolo dei nuovi iscritti: non si elimina finché non se ne sceglie un altro (Coda 258). */
    is_registration_role?: boolean
    permissions: Permission[]
}