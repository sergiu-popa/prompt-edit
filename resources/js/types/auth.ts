/**
 * The signed-in user every page receives as `auth.user` (AuthenticatedUserResource).
 */
export interface AuthUser {
    id: number;
    name: string;
    email: string;
}

export interface AuthProps {
    user: AuthUser | null;
}
