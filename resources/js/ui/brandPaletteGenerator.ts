type Palette = readonly [string, string, string, string, string, string, string, string, string];

const PALETTES: Record<string, readonly Palette[]> = {
    balanced: [
        ['#3457d5', '#168077', '#e68a45', '#f3f5fa', '#ffffff', '#e8edf8', '#111827', '#1e293b', '#293752'],
        ['#a63d58', '#315e87', '#d78b38', '#f9f4f2', '#ffffff', '#f1e4e5', '#20171b', '#302126', '#422c35'],
        ['#386b53', '#536da5', '#cc7042', '#f2f6f1', '#ffffff', '#e3ede4', '#15201a', '#203229', '#2c4438'],
    ],
    pastel: [
        ['#8a78be', '#73a7a2', '#df9aab', '#f8f5fb', '#ffffff', '#f0e9f6', '#211c2d', '#30293d', '#40354f'],
        ['#8b9fc5', '#c78e9c', '#d2b277', '#f6f8fc', '#ffffff', '#e9eef8', '#1b2230', '#293448', '#36435c'],
        ['#7fae92', '#a497c4', '#e0aa83', '#f5f9f5', '#ffffff', '#e8f2e9', '#19251e', '#29382f', '#36483b'],
    ],
    monochrome: [
        ['#39434c', '#6b7882', '#a1acb4', '#f2f4f5', '#ffffff', '#e3e7ea', '#15191c', '#252c31', '#343e45'],
        ['#50475a', '#7d7289', '#aca1b8', '#f5f3f7', '#ffffff', '#e9e5ed', '#1d1922', '#2c2632', '#3b3341'],
        ['#3f514b', '#71867c', '#a2b6ab', '#f2f6f3', '#ffffff', '#e3ebe5', '#161d19', '#26332b', '#35453b'],
    ],
    nature: [
        ['#557947', '#9c7655', '#c98b4a', '#f6f7ef', '#ffffff', '#e9efde', '#1a2117', '#293425', '#384831'],
        ['#256c70', '#527f9a', '#d79862', '#f0f7f7', '#ffffff', '#e1eeee', '#132123', '#213438', '#2b474b'],
        ['#a65d43', '#6e7550', '#d4a75f', '#faf4ee', '#ffffff', '#f0e5da', '#241a17', '#382821', '#4c372c'],
    ],
};

const KEYS = ['primary', 'secondary', 'accent', 'light_background', 'light_surface', 'light_header', 'dark_background', 'dark_surface', 'dark_header'] as const;
const lastChoice = new Map<string, number>();

export function generateBrandPalette(style: string): Record<string, string> {
    const options = PALETTES[style] ?? PALETTES.balanced;
    const previous = lastChoice.get(style);
    let index = Math.floor(Math.random() * options.length);
    if (options.length > 1 && index === previous) index = (index + 1) % options.length;
    lastChoice.set(style, index);

    return Object.fromEntries(KEYS.map((key, i) => [key, options[index][i]]));
}
