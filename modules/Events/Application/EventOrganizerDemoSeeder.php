<?php

declare(strict_types=1);

namespace Modules\Events\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Customers\Application\StoreAuthService;
use Gereq\Customers\Domain\Customer;
use Gereq\Customers\Domain\CustomerAccount;
use Modules\Events\Domain\CatalogEvent;

/**
 * Demo pořadatelé pro KdeAco: store účty + u dvou důvěra (auto-publish).
 */
final class EventOrganizerDemoSeeder
{
    public const DEMO_PASSWORD = 'KdeAco-demo-2026';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StoreAuthService $auth,
        private readonly EventTrust $trust,
    ) {
    }

    /** @return list<array{name: string, email: string, phone: string, company: ?string, trusted: bool, region: string}> */
    public function people(): array
    {
        return [
            [
                'name' => 'Lucie Nováková',
                'email' => 'lucie.novakova@demo.kdeaco.cz',
                'phone' => '+420 777 101 201',
                'company' => 'Domažlice Live',
                'trusted' => true,
                'region' => 'Domažlice',
            ],
            [
                'name' => 'Pavel Švec',
                'email' => 'pavel.svec@demo.kdeaco.cz',
                'phone' => '+420 777 101 202',
                'company' => 'Plzeň Live',
                'trusted' => true,
                'region' => 'Plzeň-město',
            ],
            [
                'name' => 'Karolína Veselá',
                'email' => 'karolina.vesela@demo.kdeaco.cz',
                'phone' => '+420 777 101 203',
                'company' => 'Šumava Trails',
                'trusted' => false,
                'region' => 'Klatovy',
            ],
            [
                'name' => 'Martin Horák',
                'email' => 'martin.horak@demo.kdeaco.cz',
                'phone' => '+420 777 101 204',
                'company' => 'Běháme Tachovsko',
                'trusted' => false,
                'region' => 'Tachov',
            ],
            [
                'name' => 'Tereza Malá',
                'email' => 'tereza.mala@demo.kdeaco.cz',
                'phone' => '+420 777 101 205',
                'company' => null,
                'trusted' => false,
                'region' => 'Domažlice',
            ],
        ];
    }

    public function runMissing(): int
    {
        $emails = array_map(
            static fn (array $row): string => strtolower($row['email']),
            $this->people(),
        );
        $existing = (int) $this->em->getRepository(Customer::class)->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.email IN (:emails)')
            ->setParameter('emails', $emails)
            ->getQuery()
            ->getSingleScalarResult();
        if ($existing >= count($emails)) {
            return 0;
        }

        $created = 0;
        $customerIds = [];
        foreach ($this->people() as $row) {
            $email = strtolower($row['email']);
            $exists = $this->em->getRepository(Customer::class)->findOneBy(['email' => $email]) instanceof Customer;
            $customer = $this->ensurePerson($row);
            $customerIds[] = $customer->id();
            if ($row['trusted']) {
                $this->trust->grant($customer->id());
            }
            if (!$exists) {
                $created++;
            }
        }
        $byEmail = [];
        foreach ($this->people() as $i => $row) {
            $byEmail[strtolower($row['email'])] = $customerIds[$i];
        }
        $this->assignEvents($byEmail);
        $this->em->flush();

        return $created;
    }

    /** @param array{name: string, email: string, phone: string, company: ?string, trusted: bool, region: string} $row */
    private function ensurePerson(array $row): Customer
    {
        $email = strtolower($row['email']);
        $customer = $this->em->getRepository(Customer::class)->findOneBy(['email' => $email]);
        if (!$customer instanceof Customer) {
            $account = $this->auth->register(
                $email,
                self::DEMO_PASSWORD,
                $row['name'],
                $row['phone'],
                [
                    'region' => $row['region'],
                    'newsletter' => false,
                ],
            );
            $customer = $this->em->find(Customer::class, $account->customerId());
            if (!$customer instanceof Customer) {
                throw new \RuntimeException('Demo customer missing after register: ' . $email);
            }
        } else {
            $account = $this->em->getRepository(CustomerAccount::class)->findOneBy(['email' => $email]);
            if (!$account instanceof CustomerAccount) {
                $this->auth->register($email, self::DEMO_PASSWORD, $row['name'], $row['phone']);
            }
        }

        $customer->setName($row['name']);
        $customer->setPhone($row['phone']);
        $customer->setCompany($row['company']);
        $customer->setStatus('active');
        $customer->setPreferredRegion($row['region']);
        $customer->setNote($row['trusted']
            ? 'Demo pořadatel — důvěra (akce bez schválení).'
            : 'Demo pořadatel — akce čekají na schválení.');

        return $customer;
    }

    /**
     * @param array<string, string> $customerIdByEmail
     */
    private function assignEvents(array $customerIdByEmail): void
    {
        /** @var array<string, array{0: string, 1: string}> title => [email, organizer] */
        $map = [
            'Chodské slavnosti 2026' => ['lucie.novakova@demo.kdeaco.cz', 'Domažlice Live'],
            'The Silver Spoons – letní koncert' => ['lucie.novakova@demo.kdeaco.cz', 'Domažlice Live'],
            'Dětský den v Domažlicích' => ['lucie.novakova@demo.kdeaco.cz', 'Domažlice Live'],
            'Michal Hrůza – Open Air' => ['pavel.svec@demo.kdeaco.cz', 'Plzeň Live'],
            'Letní kino Plzeň' => ['pavel.svec@demo.kdeaco.cz', 'Plzeň Live'],
            'MTB závod Šumava' => ['karolina.vesela@demo.kdeaco.cz', 'Šumava Trails'],
            'Výlet na hrad Rabí' => ['karolina.vesela@demo.kdeaco.cz', 'Šumava Trails'],
            'Běh městem Tachov' => ['martin.horak@demo.kdeaco.cz', 'Běháme Tachovsko'],
            'Rock pod Šumavou 2026' => ['martin.horak@demo.kdeaco.cz', 'Běháme Tachovsko'],
            'Divadelní představení – Lakomec' => ['tereza.mala@demo.kdeaco.cz', 'Tereza Malá'],
            'Dětský den na klatovském náměstí' => ['tereza.mala@demo.kdeaco.cz', 'Tereza Malá'],
        ];

        foreach ($this->em->getRepository(CatalogEvent::class)->findAll() as $event) {
            if (!$event instanceof CatalogEvent) {
                continue;
            }
            $row = $map[$event->title()] ?? null;
            if ($row === null) {
                continue;
            }
            [$email, $organizer] = $row;
            $customerId = $customerIdByEmail[$email] ?? null;
            if ($customerId !== null) {
                $event->setSubmittedByCustomerId($customerId);
            }
            $event->setOrganizer($organizer);
        }
    }
}
