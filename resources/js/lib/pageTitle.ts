/**
 * Builds the document title Inertia sets for every page.
 */
export function formatPageTitle(pageTitle: string, appName: string): string {
    const title = pageTitle.trim();

    return title === '' ? appName : `${title} · ${appName}`;
}
