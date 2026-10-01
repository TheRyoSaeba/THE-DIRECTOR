import { Page, PageProps, Errors, ErrorBag } from '@inertiajs/core';

declare global {
    interface Window {
        route: any;
    }
}

declare module '@inertiajs/core' {
    interface Page<SharedProps extends PageProps = PageProps> {
        props: SharedProps;
    }
}
