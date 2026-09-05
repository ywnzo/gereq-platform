<?php

declare(strict_types=1);

namespace Modules\Newsletter\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Newsletter\Domain\Subscriber;

final class NewsletterProvider
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function subscribe(string $email, ?string $name = null, ?string $source = null): Subscriber
    {
        $email = strtolower(trim($email));
        $existing = $this->em->getRepository(Subscriber::class)->findOneBy(['email' => $email]);
        if ($existing instanceof Subscriber) {
            $existing->subscribe();
            if ($name !== null) {
                $existing->setName($name);
            }
            if ($source !== null) {
                $existing->setSource($source);
            }
            $this->em->flush();

            return $existing;
        }

        $subscriber = new Subscriber($email, $name);
        $subscriber->setSource($source);
        $this->em->persist($subscriber);
        $this->em->flush();

        return $subscriber;
    }

    public function unsubscribe(string $email): ?Subscriber
    {
        $email = strtolower(trim($email));
        $existing = $this->em->getRepository(Subscriber::class)->findOneBy(['email' => $email]);
        if (!$existing instanceof Subscriber) {
            return null;
        }
        $existing->unsubscribe();
        $this->em->flush();

        return $existing;
    }
}
