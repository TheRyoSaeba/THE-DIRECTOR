import ReactMarkdown from 'react-markdown';

const allowedElements = [
    'p', 'br', 'strong', 'em', 'b', 'i',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'ul', 'ol', 'li',
    'blockquote',
    'code', 'pre',
    'a',
    'hr',
];

const markdownComponents = {
    a: ({ href, children: c }) => (
        <a
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            className="text-cyan-400 hover:text-cyan-300 underline"
        >
            {c}
        </a>
    ),
    p: ({ children: c }) => <p className="mb-2 last:mb-0 whitespace-pre-wrap">{c}</p>,
    ul: ({ children: c }) => <ul className="list-disc list-inside mb-2 space-y-1">{c}</ul>,
    ol: ({ children: c }) => <ol className="list-decimal list-inside mb-2 space-y-1">{c}</ol>,
    li: ({ children: c }) => <li className="ml-2">{c}</li>,
    blockquote: ({ children: c }) => (
        <blockquote className="border-l-2 border-slate-600 pl-3 my-2 text-slate-400 italic">{c}</blockquote>
    ),
    code: ({ className: codeClass, children: c }) =>
        codeClass ? (
            <code className={codeClass}>{c}</code>
        ) : (
            <code className="bg-slate-800 px-1 py-0.5 rounded text-cyan-300 text-xs">{c}</code>
        ),
    pre: ({ children: c }) => (
        <pre className="bg-slate-800/50 p-3 rounded-lg my-2 overflow-x-auto text-xs">{c}</pre>
    ),
    h1: ({ children: c }) => <h1 className="text-lg font-bold mb-2 mt-3">{c}</h1>,
    h2: ({ children: c }) => <h2 className="text-base font-bold mb-2 mt-3">{c}</h2>,
    h3: ({ children: c }) => <h3 className="text-sm font-bold mb-1 mt-2">{c}</h3>,
    hr: () => <hr className="border-slate-700 my-3" />,
};

/**
 * Renders a markdown string with game-appropriate styling.
 * Supports custom alignment: [center]text[/center], [right]text[/right], [left]text[/left].
 */
export default function MarkdownRenderer({ children, className = '' }) {
    if (!children) return null;

    // Split components by alignment tags (long and short versions)
    const parts = children.split(/(\[(?:center|c)\][\s\S]*?\[\/(?:center|c)\]|\[(?:right|r)\][\s\S]*?\[\/(?:right|r)\]|\[(?:left|l)\][\s\S]*?\[\/(?:left|l)\])/g);

    return (
        <div className={className || undefined}>
            {parts.map((part, i) => {
                if (part.startsWith('[center]') || part.startsWith('[c]')) {
                    const content = part.replace(/\[(?:center|c)\]|\[\/(?:center|c)\]/g, '');
                    return <div key={i} className="text-center"><ReactMarkdown allowedElements={allowedElements} unwrapDisallowed={true} components={markdownComponents}>{content}</ReactMarkdown></div>;
                }
                if (part.startsWith('[right]') || part.startsWith('[r]')) {
                    const content = part.replace(/\[(?:right|r)\]|\[\/(?:right|r)\]/g, '');
                    return <div key={i} className="text-right"><ReactMarkdown allowedElements={allowedElements} unwrapDisallowed={true} components={markdownComponents}>{content}</ReactMarkdown></div>;
                }
                if (part.startsWith('[left]') || part.startsWith('[l]')) {
                    const content = part.replace(/\[(?:left|l)\]|\[\/(?:left|l)\]/g, '');
                    return <div key={i} className="text-left"><ReactMarkdown allowedElements={allowedElements} unwrapDisallowed={true} components={markdownComponents}>{content}</ReactMarkdown></div>;
                }
                return (
                    <ReactMarkdown
                        key={i}
                        allowedElements={allowedElements}
                        unwrapDisallowed={true}
                        components={markdownComponents}
                    >
                        {part}
                    </ReactMarkdown>
                );
            })}
        </div>
    );
}
