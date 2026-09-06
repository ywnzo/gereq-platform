<?php

declare(strict_types=1);

namespace Modules\iDoklad\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Audit\Auditor;
use Gereq\Debug\FullServiceLogger;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Customers\Domain\Customer;
use Modules\Customers\Domain\CustomerAddress;
use Modules\iDoklad\Application\iDokladService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class iDokladCustomerController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Auditor $auditor,
        private readonly TranslatorInterface $translator,
        private readonly iDokladService $iDoklad,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    /**
     * Vytvoří kontakt v iDokladu ze zákazníka, nebo aktualizuje už propojený.
     */
    public function sync(Request $request, string $id): Response
    {
        $customer = $this->requireCustomer($id);
        if (!$this->iDoklad->isEnabled()) {
            $this->flash('error', $this->translator->trans('idoklad.error.not_configured'));

            return $this->backToCustomer($customer->id());
        }

        $existing = $this->iDoklad->findLink('customer', $customer->id(), 'contact');
        $iDokladId = $existing !== null ? (int) $existing->iDokladId() : null;
        if ($iDokladId !== null && $iDokladId <= 0) {
            $iDokladId = null;
        }

        $payload = $this->contactPayload($customer);
        FullServiceLogger::log('idoklad.customer.sync.start', [
            'customer_id' => $customer->id(),
            'idoklad_id' => $iDokladId,
            'company' => $payload['company'] ?? null,
        ]);

        try {
            $result = $this->iDoklad->setContact($payload, $iDokladId);
        } catch (\Throwable $e) {
            FullServiceLogger::log('idoklad.customer.sync.error', [
                'customer_id' => $customer->id(),
                'error' => $e,
            ]);
            $this->flash('error', $this->translator->trans('idoklad.customer.sync_failed'));

            return $this->backToCustomer($customer->id());
        }

        if (empty($result['isSucceed']) || !isset($result['data']['Id'])) {
            $message = (string) ($result['message'] ?? 'idoklad.customer.sync_failed');
            if (str_starts_with($message, 'idoklad.')) {
                $message = $this->translator->trans($message);
            }
            $this->flash('error', $message !== '' ? $message : $this->translator->trans('idoklad.customer.sync_failed'));

            return $this->backToCustomer($customer->id());
        }

        $remoteId = (string) $result['data']['Id'];
        $this->iDoklad->rememberLink('customer', $customer->id(), $remoteId, 'contact', [
            'company' => $result['data']['CompanyName'] ?? ($payload['company'] ?? null),
            'synced_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
        $this->auditor->log(
            $iDokladId === null ? 'idoklad.customer.linked' : 'idoklad.customer.synced',
            'customer',
            $customer->id(),
            null,
            ['idoklad_id' => $remoteId],
        );
        $this->flash(
            'success',
            $this->translator->trans(
                $iDokladId === null ? 'idoklad.customer.linked' : 'idoklad.customer.synced',
                ['%id%' => $remoteId],
            ),
        );

        return $this->backToCustomer($customer->id());
    }

    /**
     * Napáruje existující iDoklad kontakt (PartnerId) bez vytváření nového.
     */
    public function attach(Request $request, string $id): Response
    {
        $customer = $this->requireCustomer($id);
        if (!$this->iDoklad->isEnabled()) {
            $this->flash('error', $this->translator->trans('idoklad.error.not_configured'));

            return $this->backToCustomer($customer->id());
        }

        $raw = trim((string) $request->request->get('idoklad_id', ''));
        $partnerId = (int) $raw;
        if ($partnerId <= 0 || !ctype_digit($raw)) {
            $this->flash('error', $this->translator->trans('idoklad.customer.invalid_id'));

            return $this->backToCustomer($customer->id());
        }

        try {
            $remote = $this->iDoklad->getContact($partnerId);
        } catch (\Throwable $e) {
            FullServiceLogger::log('idoklad.customer.attach.error', [
                'customer_id' => $customer->id(),
                'idoklad_id' => $partnerId,
                'error' => $e,
            ]);
            $this->flash('error', $this->translator->trans('idoklad.customer.attach_failed'));

            return $this->backToCustomer($customer->id());
        }

        if (empty($remote['isSucceed'])) {
            $message = (string) ($remote['message'] ?? 'idoklad.customer.attach_failed');
            if (str_starts_with($message, 'idoklad.')) {
                $message = $this->translator->trans($message);
            }
            $this->flash('error', $message !== '' ? $message : $this->translator->trans('idoklad.customer.attach_failed'));

            return $this->backToCustomer($customer->id());
        }

        $company = is_array($remote['data'] ?? null)
            ? (string) ($remote['data']['CompanyName'] ?? '')
            : '';
        $this->iDoklad->rememberLink('customer', $customer->id(), (string) $partnerId, 'contact', [
            'company' => $company !== '' ? $company : null,
            'attached_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
        $this->auditor->log('idoklad.customer.attached', 'customer', $customer->id(), null, [
            'idoklad_id' => (string) $partnerId,
        ]);
        $this->flash('success', $this->translator->trans('idoklad.customer.attached', [
            '%id%' => (string) $partnerId,
        ]));

        return $this->backToCustomer($customer->id());
    }

    public function unlink(Request $request, string $id): Response
    {
        $customer = $this->requireCustomer($id);
        $removed = $this->iDoklad->forgetLink('customer', $customer->id(), 'contact');
        if ($removed) {
            $this->auditor->log('idoklad.customer.unlinked', 'customer', $customer->id());
            $this->flash('success', $this->translator->trans('idoklad.customer.unlinked'));
        } else {
            $this->flash('error', $this->translator->trans('idoklad.customer.not_linked'));
        }

        return $this->backToCustomer($customer->id());
    }

    private function requireCustomer(string $id): Customer
    {
        if (!class_exists(Customer::class)) {
            $this->abort(404);
        }
        $customer = $this->em->find(Customer::class, $id);
        if (!$customer instanceof Customer) {
            $this->abort(404, $this->translator->trans('customers.error.not_found'));
        }

        return $customer;
    }

    private function backToCustomer(string $customerId): Response
    {
        return $this->redirect('admin.customers.show', ['id' => $customerId]);
    }

    /** @return array<string, mixed> */
    private function contactPayload(Customer $customer): array
    {
        $payload = [
            'name' => $customer->name(),
            'email' => $customer->email(),
            'phone' => (string) ($customer->phone() ?? ''),
            'company' => (string) ($customer->company() ?? ''),
            'country' => 'CZ',
        ];

        if (!class_exists(CustomerAddress::class)) {
            $payload['country_id'] = $this->iDoklad->resolveCountryId('CZ');

            return $payload;
        }

        $addresses = $this->em->getRepository(CustomerAddress::class)->findBy(
            ['customerId' => $customer->id()],
            ['isPrimary' => 'DESC', 'createdAt' => 'DESC'],
        );
        $address = null;
        foreach ($addresses as $row) {
            if (!$row instanceof CustomerAddress) {
                continue;
            }
            if ($row->type() === 'billing') {
                $address = $row;
                break;
            }
            if ($address === null) {
                $address = $row;
            }
        }
        if ($address !== null) {
            $payload['street'] = trim($address->line1() . ($address->line2() ? ', ' . $address->line2() : ''));
            $payload['city'] = $address->city();
            $payload['zip'] = $address->zip();
            $payload['country'] = $address->country() !== '' ? $address->country() : 'CZ';
        }

        $payload['country_id'] = $this->iDoklad->resolveCountryId($payload['country']);

        return $payload;
    }
}
