<?php

declare(strict_types=1);

/**
 * Bootstrap Twig Environment for Twiggy Language Server.
 * Uses the same TwigRuntime as the app (named methods = Go to Definition).
 *
 * @see https://github.com/moetelo/twiggy/issues/52
 */

use Gereq\Foundation\Application;
use Twig\Environment;

$basePath = dirname(__DIR__);

require $basePath . '/vendor/autoload.php';

$app = Application::create($basePath);

/** @var Environment $twig */
$twig = $app->make(Environment::class);

return $twig;
