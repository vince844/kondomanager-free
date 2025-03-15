
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

export function usePermission() {
    const page = usePage();
    const auth = computed(() => page.props.auth);

    // Check if the user has at least one of the roles in the array
    const hasRole = (roles) => {
        const userRoles = auth.value?.user?.roles ?? [];
        // Check if any of the roles in the passed array match the user's roles
        return roles.some(role => userRoles.includes(role));
    };

    // Check if the user has a specific permission
    const hasPermission = (name) => {
        const permissions = auth.value?.user?.permissions ?? [];
        return permissions.includes(name);
    };

    return { hasRole, hasPermission };
}
