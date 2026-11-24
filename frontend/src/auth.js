export function can(user, permission) {
  return Boolean(user?.permissions?.includes(permission) || user?.roles?.includes('admin'));
}

export function hasRole(user, role) {
  return Boolean(user?.roles?.includes(role) || user?.roles?.includes('admin'));
}
