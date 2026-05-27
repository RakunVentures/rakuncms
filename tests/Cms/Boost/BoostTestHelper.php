<?php

declare(strict_types=1);

namespace Tests\Cms\Boost;

use Rkn\Cms\Cli\BoostCommand;
use Rkn\Cms\Cli\InitCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class BoostTestHelper
{
    /**
     * @param array<string,string|null> $args
     */
    public static function run(string $tmpDir, array $args): CommandTester
    {
        $app = new Application();
        $app->addCommand(new InitCommand());
        $app->addCommand(new BoostCommand());
        $tester = new CommandTester($app->find('boost'));
        $tester->execute(array_merge(['path' => $tmpDir], $args));
        return $tester;
    }
}
