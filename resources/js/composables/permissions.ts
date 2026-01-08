import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import type { NavItem, User as BaseUser } from "@/types";
import type { Role } from "@/types/roles"; // Assuming you still need Role type if required elsewhere
import type { Permission } from "@/types/permissions"; // Assuming you still need Permission type if required elsewhere

// Extend the User interface to allow optional roles and permissions arrays
type ExtendedUser = BaseUser & {
  roles?: string[];  // Change to string array if roles are strings, not objects
  permissions?: string[];  // Change to string array if permissions are strings, not objects
};

type RolePrefix = 'admin' | 'user';

// Type for items that can be checked for access, like NavItems or raw role/permission arrays
type AccessCheckItem = NavItem | { roles?: string[]; permissions?: string[] } | string[];

const DEFAULT_ROLE_MAPPINGS = {
  'amministratore': 'admin',
  'collaboratore': 'admin'
} as const;

export function usePermission() {
  const page = usePage();
  // Extract auth data from page props
  const auth = computed<{ user: ExtendedUser }>(() => page.props.auth as { user: ExtendedUser });

  // Check if user has one of the specified roles
  const hasRole = (roles: string[]) => {
    const userRoles = auth.value?.user?.roles ?? [];
    return roles.some((role) => userRoles.includes(role));  // Check if any of the specified roles are present
  };

  // Check if user has one of the specified permissions
  const hasPermission = (permissions: string[]) => {
    const userPermissions = auth.value?.user?.permissions ?? [];
    return permissions.some((permission) => userPermissions.includes(permission));  // Check if any of the specified permissions are present
  };

  // Determine if access should be granted based on role or permission checks
  const canAccess = (item: AccessCheckItem): boolean => {

    // Protezione: se l’item non esiste, non deve rompere nulla
    if (!item) return true;

    // Se è un array di permissions → check diretto
    if (Array.isArray(item)) {
      return hasPermission(item);
    }

    const roles = item.roles ?? [];
    const permissions = item.permissions ?? [];

    const hasDefinedRoles = roles.length > 0;
    const hasDefinedPermissions = permissions.length > 0;

    const roleCheck = hasDefinedRoles ? hasRole(roles) : false;
    const permissionCheck = hasDefinedPermissions ? hasPermission(permissions) : false;

    if (hasDefinedRoles && hasDefinedPermissions) {
      return roleCheck || permissionCheck;
    }

    if (hasDefinedRoles) return roleCheck;
    if (hasDefinedPermissions) return permissionCheck;

    return true; // Nessuna restrizione → accesso sempre consentito
  };
/*   const canAccess = (item: AccessCheckItem): boolean => {
    if (Array.isArray(item)) {
      return hasPermission(item); // If item is an array of permissions, check against permissions
    }
  
    const roles = item.roles ?? [];  // Use "roles" instead of "role"
    const permissions = item.permissions ?? [];
  
    const hasDefinedRoles = roles.length > 0;
    const hasDefinedPermissions = permissions.length > 0;
  
    const roleCheck = hasDefinedRoles ? hasRole(roles) : false;
    const permissionCheck = hasDefinedPermissions ? hasPermission(permissions) : false;
  
    if (hasDefinedRoles && hasDefinedPermissions) {
      return roleCheck || permissionCheck; // Access granted if either role or permission matches
    }
  
    if (hasDefinedRoles) return roleCheck;
    if (hasDefinedPermissions) return permissionCheck;
  
    return true;  // If no roles or permissions specified, grant access
  }; */

  const getRolePrefix = (
    roleMappings: Record<string, string> = DEFAULT_ROLE_MAPPINGS
  ): RolePrefix => {
    const userRoles = auth.value?.user?.roles ?? [];
    
    // Check if user has either "amministratore", "collaboratore", or the permission "Accesso pannello amministratore"
    const hasAdminAccess = userRoles.some(role => role === 'amministratore' || role === 'collaboratore') || auth.value?.user?.permissions?.includes('Accesso pannello amministratore');

    // If the user has admin roles or the required permission, redirect to admin
    if (hasAdminAccess) {
      return 'admin';  // This will return 'admin' for users with roles 'amministratore' or 'collaboratore', or the permission 'Accesso pannello amministratore'
    }

    // Default to user if none of the above conditions are met
    return 'user';
  };

  function generateRoute(routeName: string): string;
  function generateRoute(routeName: string, params: Record<string, any>): [string, Record<string, any>];

  function generateRoute(routeName: string, params?: Record<string, any>) {
    const prefix = getRolePrefix();
    const fullRoute = `${prefix}.${routeName}`;
    
    // If params are passed, return a tuple [routeName, params]
    if (params) {
      return [fullRoute, params] as const;
    }

    return fullRoute;
  }

  const generatePath = (path: string, params?: Record<string, string | number>): string => {
    const prefix = getRolePrefix();
    let finalPath = `/${prefix}/${path}`;

    if (params) {
      Object.entries(params).forEach(([key, value]) => {
        finalPath = finalPath.replace(`:${key}`, String(value));
      });
    }

    return finalPath;
  };

  return { hasRole, hasPermission, canAccess, getRolePrefix, generateRoute, generatePath };
}
