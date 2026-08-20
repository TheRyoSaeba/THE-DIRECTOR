import { Page, PageProps, Errors, ErrorBag } from '@inertiajs/core';
import type { AxiosStatic } from 'axios';

declare global {
    interface Window {
        route: any;
        axios: AxiosStatic;
    }
}

declare module '@inertiajs/core' {
    interface Page<SharedProps extends PageProps = PageProps> {
        props: SharedProps;
    }
}
