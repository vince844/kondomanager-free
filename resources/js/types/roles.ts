import { Permission } from './permissions';

export interface Role {
    id: string
    name: string
    description: string
    permissions: Permission[]
}