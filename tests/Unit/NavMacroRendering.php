<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderMegaMenuConfig;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The harness that renders this plugin's real Twig against real
 * `MenuBuilderNode` objects, so a test can assert against the DOM an actual
 * browser receives rather than against template source.
 *
 * Shared by {@see MenuBuilderPreviewRenderTest} (what a resolved node looks
 * like, and the preview stage around it) and
 * {@see MenuBuilderAccessibilityTest} (what the markup promises assistive
 * technology). One harness, because two copies of it would be two
 * definitions of "the markup the front end gets".
 *
 * A booted Craft app isn't needed: the macros consume finished nodes and
 * exactly one Craft touchpoint (`craft.menuBuilder.iconAsset()`, stubbed
 * here the way the variable behaves, including returning null for a deleted
 * asset).
 *
 * Not a `*Test.php` file, so PHPUnit doesn't collect it; test files
 * `require_once` it explicitly, because this plugin's `autoload-dev` isn't
 * part of the consuming Craft install's autoloader (see tests/bootstrap.php).
 */
trait NavMacroRendering
{
    private const TEMPLATE_DIR = __DIR__ . '/../../src/templates';

    /** An asset icon the stub resolves; anything else stands for a deleted asset. */
    private const KNOWN_ASSET_ID = 7;

    /**
     * The plugin's real templates, plus one-line harnesses that call the
     * macros the way a front-end template would. They are loaded alongside
     * the real templates rather than added to `src/templates`, so nothing
     * exists in the shipped plugin purely for a test. (The `.twig` suffix is
     * explicit because Craft's loader appends it and a bare Twig
     * FilesystemLoader does not.)
     */
    private const HARNESS = '{% import "_macros/tree.twig" as menuMacros %}{{ menuMacros.render(nodes, disclosure) }}';

    private const LANDMARK_HARNESS = '{% import "_macros/tree.twig" as menuMacros %}{{ menuMacros.renderNav(menu, label, disclosure) }}';

    protected function twig(): Environment
    {
        $twig = new Environment(new ChainLoader([
            new FilesystemLoader(self::TEMPLATE_DIR),
            new ArrayLoader([
                '__nav' => self::HARNESS,
                '__navLandmark' => self::LANDMARK_HARNESS,
            ]),
        ]), [
            'autoescape' => 'html',
            'strict_variables' => true,
        ]);

        // Craft's translation filter, reduced to what the templates use of it.
        $twig->addFilter(new TwigFilter('t', static function(string $message, string $category = 'site', array $params = []): string {
            foreach ($params as $key => $value) {
                $message = str_replace('{' . $key . '}', (string)$value, $message);
            }

            return $message;
        }));
        $twig->addFunction(new TwigFunction('url', static fn(string $path = ''): string => '/cp/' . ltrim($path, '/')));

        // `craft.menuBuilder.iconAsset(node)` — the single Craft touchpoint in
        // the macros. Null for an asset that no longer exists, which is the
        // contract MenuBuilderVariable documents.
        $menuBuilder = new class(self::KNOWN_ASSET_ID) {
            public function __construct(private int $knownAssetId)
            {
            }

            public function iconAsset(MenuBuilderNode $node): ?object
            {
                if ($node->iconAssetId() !== $this->knownAssetId) {
                    return null;
                }

                return new class() {
                    public string $url = '/uploads/icon.svg';
                    public int $width = 32;
                    public int $height = 32;
                };
            }
        };

        $twig->addGlobal('craft', new class($menuBuilder) {
            public function __construct(public object $menuBuilder)
            {
            }
        });

        return $twig;
    }

    /**
     * The list on its own — what `render()` emits, and what a template that
     * owns its own wrapper gets.
     *
     * @param MenuBuilderNode[] $nodes
     */
    protected function renderNav(array $nodes, string $disclosure = 'details'): string
    {
        return $this->twig()->render('__nav', ['nodes' => $nodes, 'disclosure' => $disclosure]);
    }

    /**
     * The whole navigation including its landmark — what `renderNav()`
     * emits for a real `MenuBuilderTree`.
     *
     * @param MenuBuilderNode[] $nodes
     * @param array<string,mixed> $groupConfig
     */
    protected function renderNavLandmark(array $nodes, array $groupConfig = [], ?string $label = null, string $disclosure = 'details'): string
    {
        $group = new MenuBuilderGroup($groupConfig + ['name' => 'Main', 'handle' => 'main']);

        return $this->twig()->render('__navLandmark', [
            'menu' => new MenuBuilderTree($group, $nodes),
            'label' => $label,
            'disclosure' => $disclosure,
        ]);
    }

    /**
     * @param MenuBuilderNode[] $nodes
     */
    protected function renderStage(array $nodes, bool $isMobile = false, bool $isFooter = false): string
    {
        $twig = $this->twig();

        return $twig->render('preview/_stage.twig', [
            // Markup, not a bare string: preview/index.twig hands the stage a
            // `{% set %}` capture, which Twig treats as already-escaped
            // output. Passing a plain string here would escape the navigation
            // into visible source and quietly test the wrong thing.
            'previewMarkup' => new Markup($twig->render('__nav', ['nodes' => $nodes, 'disclosure' => 'details']), 'UTF-8'),
            'isMobile' => $isMobile,
            'isFooter' => $isFooter,
            'siteName' => 'Example Site',
            'addressLabel' => 'example.test/news',
            'navLabel' => 'Preview of Main',
        ]);
    }

    protected function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    /**
     * @return DOMElement[]
     */
    protected function query(string $html, string $expression): array
    {
        $nodes = [];

        foreach ($this->xpath($html)->query($expression) as $node) {
            if ($node instanceof DOMElement) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /** A mega-menu parent with two columns: two children in column 1, one in column 2. */
    protected function megaParent(): MenuBuilderNode
    {
        return $this->node(1, title: 'Explore', url: '/explore', megaMenu: new MenuBuilderMegaMenuConfig(columns: 2), children: [
            $this->node(2, title: 'Latest posts', url: '/blog/latest', level: 2, megaMenuColumn: 1),
            $this->node(3, title: 'Older posts', url: '/blog/archive', level: 2, megaMenuColumn: 1),
            $this->node(4, title: 'Jump to', url: '/jump', level: 2, megaMenuColumn: 2),
        ]);
    }

    /**
     * @param MenuBuilderNode[] $children
     * @param array<string,mixed> $htmlAttributes
     */
    protected function node(
        int $id,
        string $title = 'Item',
        ?string $url = null,
        string $type = 'url',
        ?bool $isClickable = null,
        bool $isLinkAvailable = true,
        string $target = '_self',
        ?string $rel = null,
        ?string $ariaLabel = null,
        ?string $titleAttribute = null,
        ?string $icon = null,
        ?string $badge = null,
        ?string $badgeStyle = null,
        int $level = 1,
        ?MenuBuilderMegaMenuConfig $megaMenu = null,
        ?int $megaMenuColumn = null,
        bool $isDynamic = false,
        array $children = [],
        ?string $cssClass = null,
        ?string $htmlId = null,
        array $htmlAttributes = [],
        bool $isActive = false,
        bool $isActiveAncestor = false,
    ): MenuBuilderNode {
        $node = new MenuBuilderNode(
            id: $id,
            handle: null,
            type: $type,
            title: $title,
            url: $url,
            isClickable: $isClickable ?? ($url !== null),
            isLinkAvailable: $isLinkAvailable,
            target: $target,
            rel: $rel,
            cssClass: $cssClass,
            htmlId: $htmlId,
            htmlAttributes: $htmlAttributes,
            ariaLabel: $ariaLabel,
            titleAttribute: $titleAttribute,
            icon: $icon,
            badge: $badge,
            description: null,
            image: null,
            featured: false,
            level: $level,
            megaMenu: $megaMenu,
            megaMenuColumn: $megaMenuColumn,
            isDynamic: $isDynamic,
            badgeStyle: $badgeStyle,
        );
        $node->children = $children;
        $node->isActive = $isActive;
        $node->isActiveAncestor = $isActiveAncestor;

        foreach ($children as $child) {
            $child->parent = $node;
        }

        return $node;
    }
}
