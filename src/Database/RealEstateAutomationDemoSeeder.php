<?php

declare(strict_types=1);

namespace Gereq\Database;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Real-estate CRM pipeline + automation (listing → CRM → e-mail).
 * Idempotent by e-mails / workflow names.
 */
final class RealEstateAutomationDemoSeeder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return list<string> */
    public function seed(): array
    {
        $log = [];

        $this->ensureEmailingModule($log);
        $this->seedEmailingAccount($log);
        $listings = $this->publishedListings();
        $customers = $this->seedLeadCustomers($listings, $log);
        $this->seedListingPipeline($customers, $listings, $log);
        $this->seedWorkflows($log);

        $this->em->flush();

        return $log;
    }

    /** @param list<string> $log */
    private function ensureEmailingModule(array &$log): void
    {
        if (!class_exists(\Gereq\Module\InstalledModule::class)) {
            return;
        }

        $row = $this->em->find(\Gereq\Module\InstalledModule::class, 'emailing');
        if ($row === null) {
            $version = '1.5.7';
            if (class_exists(\Modules\Emailing\EmailingModule::class)) {
                $version = (new \Modules\Emailing\EmailingModule())->version();
            }
            $this->em->persist(new \Gereq\Module\InstalledModule('emailing', $version, true));
            $log[] = 'Modul emailing: zapnut (nová instalace).';
            $this->em->flush();

            return;
        }

        if (!$row->enabled()) {
            $row->enable();
            $log[] = 'Modul emailing: zapnut.';
            $this->em->flush();
        }
    }

    /** @param list<string> $log */
    private function seedEmailingAccount(array &$log): void
    {
        if (!class_exists(\Modules\Emailing\Domain\MailAccount::class)) {
            return;
        }

        $repo = $this->em->getRepository(\Modules\Emailing\Domain\MailAccount::class);
        $email = 'info@realestates.cz';
        $existing = $repo->findOneBy(['email' => $email]);
        if ($existing instanceof \Modules\Emailing\Domain\MailAccount) {
            foreach ($repo->findBy(['isDefault' => true]) as $other) {
                if ($other->id() !== $existing->id()) {
                    $other->setDefault(false);
                }
            }
            $existing->setActive(true);
            $existing->setDefault(true);
            $log[] = 'Emailing: účet info@realestates.cz už existuje.';

            return;
        }

        $enc = '';
        if (class_exists(\Modules\Emailing\Application\CredentialVault::class)) {
            try {
                /** @var \Modules\Emailing\Application\CredentialVault $vault */
                $vault = gereq()->make(\Modules\Emailing\Application\CredentialVault::class);
                $enc = $vault->encrypt('');
            } catch (\Throwable) {
                $enc = '';
            }
        }

        foreach ($repo->findBy(['isDefault' => true]) as $other) {
            $other->setDefault(false);
        }

        $account = new \Modules\Emailing\Domain\MailAccount('RealEstates demo', $email);
        $account->setSmtp('null', 25, 'none', $email, $enc);
        $account->setIncoming('imap', 'null', 993, 'none', $email, $enc);
        $account->setDefault(true);
        $account->setActive(true);
        $account->setSignatureHtml(
            '<p>S pozdravem,<br><strong>RealEstates</strong><br>'
            . '<a href="mailto:info@realestates.cz">info@realestates.cz</a> · +420 777 000 000</p>'
        );
        $this->em->persist($account);
        $this->em->flush();
        $log[] = 'Emailing: demo účet info@realestates.cz (SMTP null → MAIL_DSN).';
    }

    /**
     * @return list<\Modules\Listings\Domain\Listing>
     */
    private function publishedListings(): array
    {
        if (!class_exists(\Modules\Listings\Domain\Listing::class)) {
            return [];
        }

        /** @var list<\Modules\Listings\Domain\Listing> */
        return $this->em->getRepository(\Modules\Listings\Domain\Listing::class)
            ->findBy(['status' => 'published'], ['createdAt' => 'ASC'], 8);
    }

    /**
     * @param list<\Modules\Listings\Domain\Listing> $listings
     * @param list<string> $log
     * @return list<\Modules\Customers\Domain\Customer>
     */
    private function seedLeadCustomers(array $listings, array &$log): array
    {
        if (!class_exists(\Modules\Customers\Domain\Customer::class)) {
            return [];
        }

        $defs = [
            ['Tereza Nováková', 'tereza.novakova@example.cz', '+420 777 111 201', 'Hledá 2+kk v Praze, rozpočet do 7,5 mil.'],
            ['Michal Kučera', 'michal.kucera@example.cz', '+420 603 222 310', 'Zájem o dům / řadovku v Brně, hypotéka předschválena.'],
            ['Alena Svobodová', 'alena.svobodova@example.cz', '+420 725 333 420', 'Pronájem bytu u metra, nástup od příštího měsíce.'],
            ['David Černý', 'david.cerny@example.cz', '+420 608 444 530', 'Investor — byty na pronájem, preferuje výnos > 4 %.'],
            ['Jana Procházková', 'jana.prochazkova.re@example.cz', '+420 731 555 640', 'Prohlídka už domluvena, čeká na nabídku smlouvy.'],
        ];

        $repo = $this->em->getRepository(\Modules\Customers\Domain\Customer::class);
        $out = [];
        $created = 0;
        foreach ($defs as $i => [$name, $email, $phone, $note]) {
            $customer = $repo->findOneBy(['email' => $email]);
            if (!$customer instanceof \Modules\Customers\Domain\Customer) {
                $customer = new \Modules\Customers\Domain\Customer($name, $email);
                $customer->setPhone($phone);
                $customer->setStatus('lead');
                $listingTitle = isset($listings[$i]) ? $listings[$i]->title() : 'katalog';
                $customer->setNote($note . ' (poptávka: ' . $listingTitle . ')');
                $this->em->persist($customer);
                ++$created;
            }
            $out[] = $customer;
        }
        $this->em->flush();
        if ($created > 0) {
            $log[] = "CRM leady: vytvořeno {$created} zájemců o nemovitosti.";
        }

        return $out;
    }

    /**
     * @param list<\Modules\Customers\Domain\Customer> $customers
     * @param list<\Modules\Listings\Domain\Listing> $listings
     * @param list<string> $log
     */
    private function seedListingPipeline(array $customers, array $listings, array &$log): void
    {
        if (!class_exists(\Modules\CRM\Domain\Deal::class) || $customers === [] || $listings === []) {
            return;
        }

        $stages = ['lead', 'lead', 'qualified', 'proposal', 'won'];
        $dealRepo = $this->em->getRepository(\Modules\CRM\Domain\Deal::class);
        $createdDeals = 0;
        $createdInquiries = 0;
        $createdActs = 0;

        foreach ($customers as $i => $customer) {
            $listing = $listings[$i % count($listings)];
            $title = 'Poptávka: ' . $listing->title();
            $existing = $dealRepo->findOneBy(['customerId' => $customer->id(), 'title' => $title]);
            if ($existing instanceof \Modules\CRM\Domain\Deal) {
                continue;
            }

            $deal = new \Modules\CRM\Domain\Deal($customer->id(), $title);
            $deal->setAmount($listing->price());
            $deal->setCurrency($listing->currency());
            $deal->setStage($stages[$i % count($stages)]);
            $deal->setExpectedCloseAt(new \DateTimeImmutable('+' . (14 + $i * 7) . ' days'));
            $deal->setNote('Demo poptávka z nabídky „' . $listing->title() . '“.');
            $this->em->persist($deal);
            ++$createdDeals;

            if (class_exists(\Modules\Listings\Domain\Inquiry::class)) {
                $inq = new \Modules\Listings\Domain\Inquiry(
                    $listing->id(),
                    $customer->name(),
                    $customer->email(),
                    'Dobrý den, mám zájem o prohlídku nabídky „' . $listing->title() . '“. Kdy by to šlo?',
                );
                if ($customer->phone() !== null) {
                    $inq->setPhone($customer->phone());
                }
                $this->em->persist($inq);
                ++$createdInquiries;
            }

            $this->em->flush();

            if (class_exists(\Modules\CRM\Domain\Activity::class)) {
                $acts = match ($deal->stage()) {
                    'lead' => [
                        ['task', 'Zavolat zájemci', 'Ověřit rozpočet a preferovanou lokalitu.', '+1 day', false],
                    ],
                    'qualified' => [
                        ['call', 'Kvalifikační hovor', 'Klient potvrdil rozpočet a termín prohlídky.', '-2 days', true],
                        ['meeting', 'Prohlídka nemovitosti', 'Domluveno na místě s makléřem.', '+3 days', false],
                    ],
                    'proposal' => [
                        ['email', 'Návrh smlouvy odeslán', 'Čekáme na vyjádření do pátku.', '-1 day', true],
                        ['task', 'Follow-up nabídky', 'Zavolat, pokud neodpoví.', '+2 days', false],
                    ],
                    'won' => [
                        ['note', 'Obchod uzavřen', 'Rezervační smlouva podepsána.', '-5 days', true],
                    ],
                    default => [],
                };
                foreach ($acts as [$type, $subject, $body, $due, $done]) {
                    $activity = new \Modules\CRM\Domain\Activity($customer->id(), $subject, $type);
                    $activity->setDealId($deal->id());
                    $activity->setBody($body);
                    $activity->setDueAt(new \DateTimeImmutable($due));
                    if ($done) {
                        $activity->complete();
                    }
                    $this->em->persist($activity);
                    ++$createdActs;
                }
            }
        }

        // One lost deal for the win-back scenario
        if (isset($customers[0], $listings[0])) {
            $lostTitle = 'Poptávka (neúspěšná): ' . $listings[0]->title();
            if ($dealRepo->findOneBy(['title' => $lostTitle]) === null) {
                $lost = new \Modules\CRM\Domain\Deal($customers[0]->id(), $lostTitle);
                $lost->setAmount($listings[0]->price());
                $lost->setCurrency($listings[0]->currency());
                $lost->setStage('lost');
                $lost->setNote('Klient zvolil jinou nemovitost — kandidát na win-back.');
                $this->em->persist($lost);
                ++$createdDeals;
            }
        }

        $this->em->flush();
        if ($createdDeals > 0 || $createdInquiries > 0) {
            $log[] = sprintf(
                'Listing→CRM: %d obchodů, %d poptávek, %d aktivit.',
                $createdDeals,
                $createdInquiries,
                $createdActs,
            );
        }
    }

    /** @param list<string> $log */
    private function seedWorkflows(array &$log): void
    {
        if (!class_exists(\Modules\CRM\Domain\Workflow::class)) {
            return;
        }

        $defs = [
            $this->workflowWelcome(),
            $this->workflowQualified(),
            $this->workflowWon(),
            $this->workflowLostWinback(),
            $this->workflowInboxViewing(),
            $this->workflowListingInquiryNurture(),
        ];

        $repo = $this->em->getRepository(\Modules\CRM\Domain\Workflow::class);
        $created = 0;
        $updated = 0;
        foreach ($defs as $def) {
            $name = $def['name'];
            $active = (bool) ($def['active'] ?? true);
            $existing = $repo->findOneBy(['name' => $name]);
            if ($existing instanceof \Modules\CRM\Domain\Workflow) {
                $existing->setDescription($def['description']);
                $existing->setGraph($def['graph']);
                $existing->setActive($active);
                ++$updated;
                continue;
            }
            $wf = new \Modules\CRM\Domain\Workflow($name);
            $wf->setDescription($def['description']);
            $wf->setGraph($def['graph']);
            $wf->setActive($active);
            $this->em->persist($wf);
            ++$created;
        }
        $this->em->flush();
        $log[] = sprintf('CRM automatizace: %d nových / %d obnovených workflow.', $created, $updated);
    }

    /** @return array{name: string, description: string, active?: bool, graph: array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}} */
    private function workflowWelcome(): array
    {
        return [
            'name' => 'Welcome po poptávce (listing → CRM)',
            'description' => 'Po vytvoření obchodu z webové poptávky: krátké potvrzení e-mailem a úkol makléři zavolat.',
            'active' => true,
            'graph' => [
                'nodes' => [
                    [
                        'id' => 't1',
                        'type' => 'trigger.event',
                        'x' => 60,
                        'y' => 180,
                        'data' => [
                            'event' => 'deal.created',
                            'conditions' => [
                                ['field' => 'title', 'op' => 'contains', 'value' => 'Poptávka'],
                            ],
                        ],
                    ],
                    [
                        'id' => 'd1',
                        'type' => 'logic.delay',
                        'x' => 280,
                        'y' => 180,
                        'data' => ['seconds' => 30],
                    ],
                    [
                        'id' => 'e1',
                        'type' => 'action.send_email',
                        'x' => 500,
                        'y' => 180,
                        'data' => [
                            'to' => '{{customer.email}}',
                            'subject' => 'Děkujeme za zájem o {{deal.title}}',
                            'body_html' => '<p>Dobrý den {{customer.name}},</p>'
                                . '<p>děkujeme za poptávku <strong>{{deal.title}}</strong>. '
                                . 'Makléř se vám ozve do jednoho pracovního dne a domluví prohlídku.</p>'
                                . '<p>S pozdravem,<br>tým RealEstates</p>',
                        ],
                    ],
                    [
                        'id' => 'a1',
                        'type' => 'action.create_activity',
                        'x' => 740,
                        'y' => 180,
                        'data' => [
                            'subject' => 'Zavolat zájemci: {{customer.name}}',
                            'activity_type' => 'task',
                            'body' => 'Nová poptávka z webu. Ověřit rozpočet a navrhnout termín prohlídky.',
                        ],
                    ],
                ],
                'edges' => [
                    ['from' => 't1', 'to' => 'd1', 'from_port' => 'out'],
                    ['from' => 'd1', 'to' => 'e1', 'from_port' => 'out'],
                    ['from' => 'e1', 'to' => 'a1', 'from_port' => 'out'],
                ],
            ],
        ];
    }

    /** @return array{name: string, description: string, active?: bool, graph: array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}} */
    private function workflowQualified(): array
    {
        return [
            'name' => 'Kvalifikace → další kroky',
            'description' => 'Po přesunu do fáze qualified: e-mail s tipy k prohlídce + úkol připravit podklady.',
            'active' => true,
            'graph' => [
                'nodes' => [
                    [
                        'id' => 't1',
                        'type' => 'trigger.event',
                        'x' => 60,
                        'y' => 180,
                        'data' => [
                            'event' => 'deal.stage_changed',
                            'conditions' => [
                                ['field' => 'stage', 'op' => 'eq', 'value' => 'qualified'],
                            ],
                        ],
                    ],
                    [
                        'id' => 'e1',
                        'type' => 'action.send_email',
                        'x' => 320,
                        'y' => 180,
                        'data' => [
                            'to' => '{{customer.email}}',
                            'subject' => 'Další krok: prohlídka {{deal.title}}',
                            'body_html' => '<p>Dobrý den {{customer.name}},</p>'
                                . '<p>výborně — váš zájem o <strong>{{deal.title}}</strong> jsme kvalifikovali. '
                                . 'Připravíme prohlídku a pošleme půdorys i energetický štítek předem.</p>'
                                . '<p>Pokud máte preferovaný termín, odpovězte na tento e-mail.</p>',
                        ],
                    ],
                    [
                        'id' => 'a1',
                        'type' => 'action.create_activity',
                        'x' => 580,
                        'y' => 180,
                        'data' => [
                            'subject' => 'Připravit podklady k prohlídce',
                            'activity_type' => 'task',
                            'body' => 'Půdorys, foto, EN štítek, dostupnost termínů.',
                        ],
                    ],
                ],
                'edges' => [
                    ['from' => 't1', 'to' => 'e1', 'from_port' => 'out'],
                    ['from' => 'e1', 'to' => 'a1', 'from_port' => 'out'],
                ],
            ],
        ];
    }

    /** @return array{name: string, description: string, active?: bool, graph: array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}} */
    private function workflowWon(): array
    {
        return [
            'name' => 'Poděkování po uzavření (won)',
            'description' => 'Po vyhraném obchodu: děkovací e-mail a interní poznámka k předání podkladů.',
            'active' => true,
            'graph' => [
                'nodes' => [
                    [
                        'id' => 't1',
                        'type' => 'trigger.event',
                        'x' => 60,
                        'y' => 180,
                        'data' => [
                            'event' => 'deal.stage_changed',
                            'conditions' => [
                                ['field' => 'stage', 'op' => 'eq', 'value' => 'won'],
                            ],
                        ],
                    ],
                    [
                        'id' => 'e1',
                        'type' => 'action.send_email',
                        'x' => 320,
                        'y' => 180,
                        'data' => [
                            'to' => '{{customer.email}}',
                            'subject' => 'Gratulujeme — {{deal.title}}',
                            'body_html' => '<p>Dobrý den {{customer.name}},</p>'
                                . '<p>děkujeme za důvěru. Obchod <strong>{{deal.title}}</strong> je uzavřen. '
                                . 'V nejbližších dnech vás budeme kontaktovat ohledně předání klíčů a dokumentů.</p>'
                                . '<p>Pokud budete potřebovat odhad, pojištění nebo stěhování, rádi doporučíme partnery.</p>',
                        ],
                    ],
                    [
                        'id' => 'a1',
                        'type' => 'action.create_activity',
                        'x' => 580,
                        'y' => 180,
                        'data' => [
                            'subject' => 'Předat podklady po uzavření',
                            'activity_type' => 'task',
                            'body' => 'Checklist: smlouva, klíče, předávací protokol, fakturace provize.',
                        ],
                    ],
                ],
                'edges' => [
                    ['from' => 't1', 'to' => 'e1', 'from_port' => 'out'],
                    ['from' => 'e1', 'to' => 'a1', 'from_port' => 'out'],
                ],
            ],
        ];
    }

    /** @return array{name: string, description: string, active?: bool, graph: array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}} */
    private function workflowLostWinback(): array
    {
        return [
            'name' => 'Win-back po lost (7 dní)',
            'description' => 'Po ztrátě obchodu počkat 7 dní, nabídnout alternativy a vytvořit follow-up úkol.',
            'active' => true,
            'graph' => [
                'nodes' => [
                    [
                        'id' => 't1',
                        'type' => 'trigger.event',
                        'x' => 60,
                        'y' => 180,
                        'data' => [
                            'event' => 'deal.stage_changed',
                            'conditions' => [
                                ['field' => 'stage', 'op' => 'eq', 'value' => 'lost'],
                            ],
                        ],
                    ],
                    [
                        'id' => 'd1',
                        'type' => 'logic.delay',
                        'x' => 280,
                        'y' => 180,
                        'data' => ['seconds' => 604800],
                    ],
                    [
                        'id' => 'e1',
                        'type' => 'action.send_email',
                        'x' => 500,
                        'y' => 180,
                        'data' => [
                            'to' => '{{customer.email}}',
                            'subject' => 'Máme nové tipy podobné {{deal.title}}',
                            'body_html' => '<p>Dobrý den {{customer.name}},</p>'
                                . '<p>u nabídky <strong>{{deal.title}}</strong> to tentokrát nevyšlo — '
                                . 'v katalogu ale máme podobné nemovitosti. Pokud máte stále zájem, '
                                . 'odpovězte a pošleme 2–3 tipy na míru.</p>',
                        ],
                    ],
                    [
                        'id' => 'a1',
                        'type' => 'action.create_activity',
                        'x' => 740,
                        'y' => 180,
                        'data' => [
                            'subject' => 'Win-back: nabídnout alternativy',
                            'activity_type' => 'task',
                            'body' => 'Zavolat / napsat s 2–3 podobnými nabídkami.',
                        ],
                    ],
                ],
                'edges' => [
                    ['from' => 't1', 'to' => 'd1', 'from_port' => 'out'],
                    ['from' => 'd1', 'to' => 'e1', 'from_port' => 'out'],
                    ['from' => 'e1', 'to' => 'a1', 'from_port' => 'out'],
                ],
            ],
        ];
    }

    /** @return array{name: string, description: string, active?: bool, graph: array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}} */
    private function workflowInboxViewing(): array
    {
        return [
            'name' => 'Inbox → CRM (prohlídka)',
            'description' => 'Když přijde e-mail s „prohlídka“ v předmětu, založí se CRM úkol (odesílatel musí být zákazník v CRM).',
            'active' => true,
            'graph' => [
                'nodes' => [
                    [
                        'id' => 't1',
                        'type' => 'trigger.event',
                        'x' => 60,
                        'y' => 200,
                        'data' => ['event' => 'emailing.message.received', 'conditions' => []],
                    ],
                    [
                        'id' => 'i1',
                        'type' => 'logic.if',
                        'x' => 300,
                        'y' => 200,
                        'data' => [
                            'field' => 'message.subject',
                            'op' => 'contains',
                            'value' => 'prohlídka',
                        ],
                    ],
                    [
                        'id' => 'a1',
                        'type' => 'action.create_activity',
                        'x' => 560,
                        'y' => 120,
                        'data' => [
                            'subject' => 'E-mail: žádost o prohlídku',
                            'activity_type' => 'task',
                            'body' => 'Přišla zpráva „{{message.subject}}“ od {{message.from}}. Domluvit termín.',
                        ],
                    ],
                ],
                'edges' => [
                    ['from' => 't1', 'to' => 'i1', 'from_port' => 'out'],
                    ['from' => 'i1', 'to' => 'a1', 'from_port' => 'true'],
                ],
            ],
        ];
    }

    /** @return array{name: string, description: string, active?: bool, graph: array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}} */
    private function workflowListingInquiryNurture(): array
    {
        return [
            'name' => 'Mini-drip po listing.inquiry',
            'description' => 'Alternativa k Welcome: přímý trigger z webu (potvrzení → tipy → follow-up). Ve výchozím stavu vypnuto, ať se nepřekrývá s Welcome.',
            'active' => false,
            'graph' => [
                'nodes' => [
                    [
                        'id' => 't1',
                        'type' => 'trigger.event',
                        'x' => 40,
                        'y' => 200,
                        'data' => ['event' => 'listing.inquiry', 'conditions' => []],
                    ],
                    [
                        'id' => 'e1',
                        'type' => 'action.send_email',
                        'x' => 260,
                        'y' => 200,
                        'data' => [
                            'to' => '{{customer.email}}',
                            'subject' => 'Přijali jsme poptávku: {{listing.title}}',
                            'body_html' => '<p>Dobrý den {{customer.name}},</p>'
                                . '<p>poptávku na <strong>{{listing.title}}</strong> jsme dostali. '
                                . 'Brzy se ozveme s návrhem termínu prohlídky.</p>',
                        ],
                    ],
                    [
                        'id' => 'd1',
                        'type' => 'logic.delay',
                        'x' => 480,
                        'y' => 200,
                        'data' => ['seconds' => 86400],
                    ],
                    [
                        'id' => 'e2',
                        'type' => 'action.send_email',
                        'x' => 700,
                        'y' => 200,
                        'data' => [
                            'to' => '{{customer.email}}',
                            'subject' => 'Tipy před prohlídkou — {{listing.title}}',
                            'body_html' => '<p>Dobrý den {{customer.name}},</p>'
                                . '<p>než přijdete na prohlídku <strong>{{listing.title}}</strong>, '
                                . 'mrkněte na lokalitu, dispozici a energetickou třídu v detailu nabídky. '
                                . 'Připravte si případné otázky na makléře.</p>',
                        ],
                    ],
                    [
                        'id' => 'd2',
                        'type' => 'logic.delay',
                        'x' => 920,
                        'y' => 200,
                        'data' => ['seconds' => 172800],
                    ],
                    [
                        'id' => 'a1',
                        'type' => 'action.create_activity',
                        'x' => 1140,
                        'y' => 200,
                        'data' => [
                            'subject' => 'Follow-up po drip kampani',
                            'activity_type' => 'call',
                            'body' => 'Zavolat zájemci z poptávky {{listing.title}} — stále aktuální?',
                        ],
                    ],
                ],
                'edges' => [
                    ['from' => 't1', 'to' => 'e1', 'from_port' => 'out'],
                    ['from' => 'e1', 'to' => 'd1', 'from_port' => 'out'],
                    ['from' => 'd1', 'to' => 'e2', 'from_port' => 'out'],
                    ['from' => 'e2', 'to' => 'd2', 'from_port' => 'out'],
                    ['from' => 'd2', 'to' => 'a1', 'from_port' => 'out'],
                ],
            ],
        ];
    }
}
