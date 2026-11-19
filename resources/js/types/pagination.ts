/**
 * A page of a Laravel paginated API Resource collection, as Inertia resolves it:
 * the items, then the paginator's links and meta (PaginatedResourceResponse).
 */
export interface Paginated<T> {
    data: T[];
    links: PaginationLinks;
    meta: PaginationMeta;
}

/** Absolute URLs; `prev` and `next` are null on the first and last page. */
export interface PaginationLinks {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
}

export interface PaginationMeta {
    current_page: number;
    /** The first and last item's position overall; null on an empty page. */
    from: number | null;
    to: number | null;
    last_page: number;
    per_page: number;
    total: number;
    path: string;
    links: PaginationPageLink[];
}

/** One entry of the paginator's numbered links, with Previous and Next at the ends. */
export interface PaginationPageLink {
    url: string | null;
    label: string;
    page: number | null;
    active: boolean;
}
