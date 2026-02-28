<?php

declare(strict_types=1);

namespace Rkn\Cms\Template\Extensions;

use Rkn\Cms\Content\Parser;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class MarkdownExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('markdown', [$this, 'markdown'], ['is_safe' => ['html']]),
        ];
    }

    public function markdown(string $text): string
    {
        $parser = new Parser();
        return $parser->renderString($text);
    }
}
