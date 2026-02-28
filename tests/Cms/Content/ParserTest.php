<?php

declare(strict_types=1);

use Rkn\Cms\Content\Parser;

test('renders markdown string to HTML', function () {
    $parser = new Parser();
    $html = $parser->renderString('# Hello World');

    expect($html)->toContain('<h1>Hello World</h1>');
});

test('renders GFM features', function () {
    $parser = new Parser();

    $html = $parser->renderString("| A | B |\n|---|---|\n| 1 | 2 |");
    expect($html)->toContain('<table>');

    $html = $parser->renderString('~~strikethrough~~');
    expect($html)->toContain('<del>strikethrough</del>');
});

test('parses file with frontmatter', function () {
    // Create a temp markdown file
    $tmpFile = tempnam(sys_get_temp_dir(), 'rkn_test_');
    file_put_contents($tmpFile, <<<'MD'
---
title: Test Post
tags:
  - php
  - cms
---

# Hello

This is a test.
MD);

    $parser = new Parser();
    $result = $parser->parse($tmpFile);

    expect($result['frontmatter']['title'])->toBe('Test Post');
    expect($result['frontmatter']['tags'])->toBe(['php', 'cms']);
    expect($result['html'])->toContain('<h1>Hello</h1>');
    expect($result['html'])->toContain('This is a test.');

    unlink($tmpFile);
});

test('handles file without frontmatter', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'rkn_test_');
    file_put_contents($tmpFile, '# Just Markdown');

    $parser = new Parser();
    $result = $parser->parse($tmpFile);

    expect($result['frontmatter'])->toBe([]);
    expect($result['html'])->toContain('<h1>Just Markdown</h1>');

    unlink($tmpFile);
});
