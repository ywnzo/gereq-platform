<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Audit\Auditor;
use Gereq\Foundation\Application;
use Gereq\Setup\SetupState;
use Gereq\User\Entity\User;

$base = getenv('GEREQ_INSTANCE_ROOT') ?: '/app';
require $base . '/vendor/autoload.php';
ob_start();
try {
    $container = Application::create($base)->container();
    $marker = json_decode(file_get_contents($base . '/storage/.provision-bootstrap.json'), true, 16, JSON_THROW_ON_ERROR);
    if (($marker['version'] ?? null) !== 2 || !is_string($marker['user_id'] ?? null)) {
        throw new RuntimeException('Invalid bootstrap owner');
    }
    $em = $container->get(EntityManagerInterface::class);
    $owner = $em->find(User::class, $marker['user_id']);
    if (!$owner instanceof User) {
        throw new RuntimeException('Bootstrap owner unavailable');
    }
    if ($owner->name() === 'Instance owner' && $container->get(SetupState::class)->isPending()) {
        $owner->setName('Administrator');
        $em->flush();
        $container->get(Auditor::class)->log('provision.administrator.initialized', 'user', $owner->id());
    }
    ob_end_clean();
    echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    ob_end_clean();
    fwrite(STDERR, 'Administrator initialization failed.' . PHP_EOL);
    exit(1);
}
