<?php

declare(strict_types=1);

namespace Gereq\Setup;

use Gereq\Validation\ValidationRegistry;

final class SetupProfile
{
    public function __construct(private readonly ValidationRegistry $validation)
    {
        $validation->rule('setup_text', static fn (mixed $value): ?string => is_string($value) ? null : 'setup.error.operator');
        $validation->rule('setup_birth_date', static function (mixed $value): ?string {
            if (!is_string($value)) {
                return 'setup.error.birth_date';
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return $date !== false && $date->format('Y-m-d') === $value && $date <= new \DateTimeImmutable('today')
                ? null : 'setup.error.birth_date';
        });
        $validation->ruleset('setup.operator.type', ['operator_type' => 'required|setup_text|in:business,person']);
        $validation->ruleset('setup.operator.business', [
            'company_name' => 'required|setup_text|max:180',
            'ico' => ['required', 'setup_text', 'regex:/^[0-9]{8}$/D'],
            'dic' => 'nullable|setup_text|max:32',
            'address' => 'required|setup_text|max:500',
            'email' => 'required|setup_text|email|max:180',
        ]);
        $validation->ruleset('setup.operator.person', [
            'first_name' => 'required|setup_text|max:100',
            'last_name' => 'required|setup_text|max:100',
            'address' => 'required|setup_text|max:500',
            'birth_date' => 'required|setup_birth_date',
        ]);
    }

    /** @param array<string, mixed> $input
     *  @return array<string, string>
     */
    public function validate(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $input[$key] = trim($value);
            }
        }
        $type = $this->validation->validate('setup.operator.type', $input)['operator_type'];
        $data = $this->validation->validate('setup.operator.' . $type, $input);
        $profile = array_fill_keys(['company_name', 'ico', 'dic', 'email', 'first_name', 'last_name', 'address', 'birth_date'], '');
        foreach ($profile as $key => $_) {
            $profile[$key] = (string) ($data[$key] ?? '');
        }
        $profile['type'] = $type;

        return $profile;
    }
}
