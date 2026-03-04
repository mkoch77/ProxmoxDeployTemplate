const Permissions = {
    user: window.APP_USER || {},

    has(permission) {
        const perms = this.user.permissions || [];
        return perms.includes(permission);
    },

    hasAny(permissions) {
        return permissions.some(p => this.has(p));
    },

    hasAll(permissions) {
        return permissions.every(p => this.has(p));
    },
};
