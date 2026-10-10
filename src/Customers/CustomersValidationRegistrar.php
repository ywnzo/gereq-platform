<?php

declare(strict_types=1);

namespace Gereq\Customers;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Customers\Domain\Customer;
use Gereq\Module\ModuleContext;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Admin/API validation rulesets for customers entities. */
final class CustomersValidationRegistrar
{
    public function register(ModuleContext $context): void
    {
        $em = $context->get(EntityManagerInterface::class);
        $translator = $context->get(TranslatorInterface::class);
        $validation = $context->validation();

        $validation->rule(
            'customer_email_available',
            static function (mixed $value, array $params, array $data, array $ctx) use ($em): ?string {
                if (!is_string($value) || $value === '') {
                    return null;
                }

                $found = $em->getRepository(Customer::class)->findOneBy(['email' => strtolower(trim($value))]);
                if ($found instanceof Customer && $found->id() !== ($ctx['ignore_id'] ?? null)) {
                    return 'Zákazník s tímto e-mailem už existuje.';
                }

                return null;
            }
        );

        $validation->ruleset('customers.save', [
            'name' => 'required|min:2|max:120',
            'email' => 'required|email|max:190|customer_email_available',
            'phone' => 'nullable|phone|max:40',
            'is_company' => 'nullable|boolean',
            'company' => 'nullable|max:190',
            'ico' => 'nullable|max:12',
            'dic' => 'nullable|max:20',
            'company_address' => 'nullable|max:255',
            'note' => 'nullable|max:20000',
            'status' => 'required|in:active,lead,inactive,archived',
        ]);
        $validation->after('customers.save', static function (array $data, array $errors) use ($translator): array {
            $isCompany = in_array($data['is_company'] ?? null, [true, 1, '1', 'on', 'yes', 'true'], true);
            if (!$isCompany) {
                return $errors;
            }
            $ico = preg_replace('/\s+/', '', (string) ($data['ico'] ?? '')) ?? '';
            if ($ico === '') {
                $errors['ico'] = $translator->trans('validation.required');
            } elseif (preg_match('/^\d{8}$/', $ico) !== 1) {
                $errors['ico'] = $translator->trans('customers.validation.ico');
            }
            $company = trim((string) ($data['company'] ?? ''));
            if ($company === '') {
                $errors['company'] = $translator->trans('validation.required');
            }

            return $errors;
        });
        $context->forms()->entity('customers.save', Customer::class);

        $validation->ruleset('customers.bulk', [
            'action' => 'required|in:activate,lead,deactivate,archive,delete',
            'confirmed' => 'nullable',
        ]);

        $validation->ruleset('customers.address.save', [
            'type' => 'required|in:billing,shipping,other',
            'line1' => 'required|min:1|max:190',
            'line2' => 'nullable|max:190',
            'city' => 'required|min:1|max:120',
            'zip' => 'required|min:1|max:32',
            'country' => 'required|min:2|max:2',
            'is_primary' => 'nullable|boolean',
        ]);

        $validation->ruleset('customers.contact.save', [
            'name' => 'required|min:2|max:180',
            'email' => 'nullable|email|max:180',
            'phone' => 'nullable|phone|max:40',
            'role' => 'nullable|max:120',
            'is_primary' => 'nullable|boolean',
        ]);

        $validation->ruleset('customers.note.save', [
            'body' => 'required|min:1|max:20000',
        ]);

        $validation->ruleset('customers.tag.save', [
            'name' => 'required|min:1|max:120',
            'slug' => 'nullable|max:120',
            'color' => 'nullable|max:32',
        ]);

        $validation->ruleset('customers.tag.attach', [
            'tag_id' => 'nullable|max:36',
            'slug' => 'nullable|max:120',
        ]);

        $validation->ruleset('customers.relation.save', [
            'to_customer_id' => 'required|max:36',
            'type' => 'required|in:parent,child,partner,other',
        ]);
    }
}
