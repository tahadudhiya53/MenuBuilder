<?php

namespace Tahadudhiya\MenuBuilder\services;

use craft\base\Component;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;

/**
 * Marks isActive/isActiveAncestor on an already-built node tree by comparing
 * each node's resolved URL path against the current request — never a naive
 * full-string comparison, so trailing slashes/query strings/fragments don't
 * cause false negatives.
 */
class MenuBuilderActiveResolver extends Component
{
    /**
     * @param MenuBuilderNode[] $topLevelNodes
     */
    public function mark(array $topLevelNodes, string $currentUri): void
    {
        $currentPath = $this->normalize($currentUri);

        foreach ($topLevelNodes as $node) {
            $this->markNode($node, $currentPath);
        }
    }

    /**
     * @return bool Whether this node or any descendant is active.
     */
    private function markNode(MenuBuilderNode $node, string $currentPath): bool
    {
        $node->isActive = $node->url !== null && $this->normalize($node->url) === $currentPath;

        $anyChildActive = false;
        foreach ($node->children as $child) {
            if ($this->markNode($child, $currentPath)) {
                $anyChildActive = true;
            }
        }

        $node->isActiveAncestor = $anyChildActive;

        return $node->isActive || $anyChildActive;
    }

    private function normalize(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        return rtrim($path, '/') ?: '/';
    }
}
