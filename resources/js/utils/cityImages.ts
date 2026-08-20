const rawCityImageMap: Record<string, string> = {
    'new york': 'https://images.thedirector.app/newyork.jpeg',
    'tokyo': 'https://images.thedirector.app/tokyo.jpeg',
    'seoul': 'https://images.thedirector.app/seoul.jpeg',
};

export const getCityImage = (name: string, fallback?: string | null): string =>
    rawCityImageMap[name.toLowerCase()] || fallback || '/bg1.jpg';
