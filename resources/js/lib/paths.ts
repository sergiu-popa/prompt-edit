/**
 * The client's only URL literals; each mirrors a route in routes/web.php or Fortify's routes.
 */
export const paths = {
    home: '/',
    login: '/login',
    register: '/register',
    logout: '/logout',
    dashboard: '/dashboard',
    generations: '/generations',
} as const;
