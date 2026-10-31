<?php

declare(strict_types=1);

namespace Modules\WebPlus\Submodules\Ai\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Setting\Settings;
use Modules\WebPlus\Application\WebPlusProvider;
use Modules\WebPlus\Submodules\Ai\Domain\AgentTool;

final class AiProvider
{
    private bool $defaultsEnsured = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly WebPlusProvider $webplus,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('webplus_ai.enabled', true);
    }

    public function find(string $id): ?AgentTool
    {
        $tool = $this->em->find(AgentTool::class, $id);

        return $tool instanceof AgentTool ? $tool : null;
    }

    public function findByName(string $name): ?AgentTool
    {
        $tool = $this->em->getRepository(AgentTool::class)->findOneBy([
            'name' => AgentTool::normalizeName($name),
        ]);

        return $tool instanceof AgentTool ? $tool : null;
    }

    /** @return list<AgentTool> */
    public function allOrdered(): array
    {
        /** @var list<AgentTool> $rows */
        $rows = $this->em->getRepository(AgentTool::class)->findBy([], [
            'sortOrder' => 'ASC',
            'name' => 'ASC',
        ]);

        return $rows;
    }

    /** @return list<AgentTool> */
    public function enabledOrdered(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        /** @var list<AgentTool> $rows */
        $rows = $this->em->getRepository(AgentTool::class)->findBy(
            ['enabled' => true],
            ['sortOrder' => 'ASC', 'name' => 'ASC'],
        );

        return $rows;
    }

    /**
     * Public WebMCP-friendly catalog.
     *
     * @return array{enabled: bool, tools: list<array<string, mixed>>}
     */
    public function catalog(): array
    {
        $tools = [];
        foreach ($this->enabledOrdered() as $tool) {
            $input = $tool->inputSchema();
            if ($input === []) {
                $input = ['type' => 'object', 'properties' => []];
            }
            if (isset($input['properties']) && is_array($input['properties']) && $input['properties'] === []) {
                $input['properties'] = new \stdClass();
            }
            $entry = [
                'name' => $tool->name(),
                'title' => $tool->title(),
                'description' => $tool->description(),
                'inputSchema' => $input,
                'mutating' => $tool->isMutating(),
            ];
            if ($tool->outputSchema() !== null) {
                $entry['outputSchema'] = $tool->outputSchema();
            }
            $tools[] = $entry;
        }

        return [
            'enabled' => $this->isEnabled(),
            'version' => '1.0.0',
            'tools' => $tools,
        ];
    }

    /**
     * Informal /ai.txt — AI usage policy + tool discovery pointers.
     * Scanned by Agent Readiness / JSON-LD Tester discovery panel.
     */
    public function aiTxt(): string
    {
        $site = $this->webplus->siteDefaults();
        $name = $site['site_name'] !== '' ? $site['site_name'] : 'Website';
        $base = rtrim((string) ($site['base_url'] ?? ''), '/');

        $lines = [
            '# ai.txt — ' . $name,
            '',
            '> Informal AI usage & agent discovery file (not a formal standard).',
            '',
            '## Contact',
            '- Site: ' . ($base !== '' ? $base : '/'),
            '- Name: ' . $name,
            '',
            '## Policy',
            '- Crawling: see /robots.txt',
            '- Site summary for LLMs: /llms.txt',
            '- Expanded context: /llms-full.txt',
            '- Structured data: JSON-LD on public pages',
            '',
        ];

        if ($this->isEnabled()) {
            $lines[] = '## Agent tools (WebMCP)';
            $lines[] = '- Machine catalog: /tools.json';
            $lines[] = '- Full tool schemas: /llms-full.txt';
            $lines[] = '- In-page registration: document.modelContext (storefront)';
            $lines[] = '';
            foreach ($this->enabledOrdered() as $tool) {
                $lines[] = sprintf(
                    '- `%s` — %s',
                    $tool->name(),
                    $tool->title() !== '' ? $tool->title() : $tool->description(),
                );
            }
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * /llms-full.txt — llms.txt plus embedded agent tool schemas
     * so readiness scanners can score descriptions / input schemas without extra fetches.
     */
    public function llmsFullTxt(): string
    {
        $base = trim($this->webplus->llmsTxt());
        if ($base === '') {
            $site = $this->webplus->siteDefaults();
            $name = $site['site_name'] !== '' ? $site['site_name'] : 'Website';
            $base = "# {$name}\n\n> Expanded generative-engine context (llms-full.txt).\n";
        }

        if (!$this->isEnabled()) {
            return rtrim($base) . "\n";
        }

        $this->ensureDefaults();
        $catalog = $this->catalog();
        $section = ["", "## Agent tools (WebMCP)", ""];
        $section[] = 'Machine-readable catalog: [/tools.json](/tools.json)';
        $section[] = '';

        foreach ($catalog['tools'] as $tool) {
            /** @var array<string, mixed> $tool */
            $name = (string) ($tool['name'] ?? '');
            $title = (string) ($tool['title'] ?? $name);
            $desc = (string) ($tool['description'] ?? '');
            $mutating = !empty($tool['mutating']);
            $section[] = "### `{$name}` — {$title}";
            $section[] = '';
            $section[] = $desc;
            $section[] = '';
            $section[] = '- Mutating: ' . ($mutating ? 'yes' : 'no (read-only)');
            $section[] = '';
            $section[] = '#### Input schema';
            $section[] = '';
            $section[] = '```json';
            $section[] = json_encode(
                $tool['inputSchema'] ?? new \stdClass(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            );
            $section[] = '```';
            $section[] = '';
            if (isset($tool['outputSchema'])) {
                $section[] = '#### Output schema';
                $section[] = '';
                $section[] = '```json';
                $section[] = json_encode(
                    $tool['outputSchema'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
                );
                $section[] = '```';
                $section[] = '';
            }
        }

        return rtrim($base) . "\n" . implode("\n", $section) . "\n";
    }

    /** Ensure built-in tools exist; refresh + migrate legacy builtin names. */
    public function ensureDefaults(): void
    {
        if ($this->defaultsEnsured) {
            return;
        }
        $this->defaultsEnsured = true;

        /** @var list<AgentTool> $all */
        $all = $this->em->getRepository(AgentTool::class)->findAll();
        $byName = [];
        foreach ($all as $tool) {
            $byName[$tool->name()] = $tool;
        }

        $this->migrateLegacyBuiltinNames($byName);

        foreach ($this->defaultDefinitions() as $i => $def) {
            $sort = ($i + 1) * 10;
            $existing = $byName[$def['name']] ?? null;
            if ($existing instanceof AgentTool) {
                if ($existing->isBuiltin()) {
                    $existing->setTitle($def['title']);
                    $existing->setDescription($def['description']);
                    $existing->setInputSchema($def['input_schema']);
                    $existing->setOutputSchema($def['output_schema']);
                    $existing->setSortOrder($sort);
                }
                continue;
            }
            $tool = new AgentTool($def['name'], $def['title'], $def['description']);
            $tool->setInputSchema($def['input_schema']);
            $tool->setOutputSchema($def['output_schema']);
            $tool->setEnabled(true);
            $tool->setMutating(false);
            $tool->setBuiltin(true);
            $tool->setSortOrder($sort);
            $this->em->persist($tool);
            $byName[$def['name']] = $tool;
        }

        $this->em->flush();
    }

    /**
     * Old MVP names were too similar for Agent Readiness similarity checks.
     *
     * @param array<string, AgentTool> $byName
     */
    private function migrateLegacyBuiltinNames(array &$byName): void
    {
        $map = [
            'search_listings' => 'find_properties',
            'get_listing' => 'get_property_detail',
        ];
        foreach ($map as $from => $to) {
            $old = $byName[$from] ?? null;
            if (!$old instanceof AgentTool) {
                continue;
            }
            $target = $byName[$to] ?? null;
            if ($target instanceof AgentTool) {
                $this->em->remove($old);
                unset($byName[$from]);
                continue;
            }
            $old->setName($to);
            unset($byName[$from]);
            $byName[$to] = $old;
        }
    }

    /**
     * @return list<array{
     *   name: string,
     *   title: string,
     *   description: string,
     *   input_schema: array<string, mixed>,
     *   output_schema: array<string, mixed>
     * }>
     */
    private function defaultDefinitions(): array
    {
        return [
            [
                'name' => 'find_properties',
                'title' => 'Hledat v katalogu',
                'description' => 'Vyhledá VÍCE nemovitostí v katalogu podle filtrů (město, sale/rent, typ). Vrací seznam výsledků. Nepoužívej pro detail jedné nabídky — na to je get_property_detail.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'city' => [
                            'type' => 'string',
                            'description' => 'Město nebo lokalita (např. Ostrava, Frýdlant nad Ostravicí).',
                        ],
                        'offer_type' => [
                            'type' => 'string',
                            'enum' => ['sale', 'rent'],
                            'description' => 'Prodej (sale) nebo pronájem (rent).',
                        ],
                        'property_type' => [
                            'type' => 'string',
                            'enum' => ['apartment', 'house', 'land', 'commercial'],
                            'description' => 'Typ nemovitosti.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 48,
                            'description' => 'Maximální počet výsledků (výchozí 12).',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'slug' => ['type' => 'string'],
                                    'title' => ['type' => 'string'],
                                    'city' => ['type' => 'string'],
                                    'price' => ['type' => 'number'],
                                    'offer_type' => ['type' => 'string'],
                                    'url' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'total' => ['type' => 'integer'],
                    ],
                    'required' => ['items', 'total'],
                ],
            ],
            [
                'name' => 'get_property_detail',
                'title' => 'Detail nemovitosti',
                'description' => 'Načte JEDNU nemovitost podle URL slug (např. byt-3kk-hlucin). Vrací plný detail. Nepoužívej pro vyhledávání v katalogu — na to je find_properties.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'slug' => [
                            'type' => 'string',
                            'description' => 'URL slug nabídky.',
                        ],
                    ],
                    'required' => ['slug'],
                    'additionalProperties' => false,
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'slug' => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'city' => ['type' => 'string'],
                        'price' => ['type' => 'number'],
                        'currency' => ['type' => 'string'],
                        'offer_type' => ['type' => 'string'],
                        'url' => ['type' => 'string'],
                    ],
                    'required' => ['slug', 'title'],
                ],
            ],
            [
                'name' => 'get_site_info',
                'title' => 'Informace o webu',
                'description' => 'Vrať název webu, základní URL a výchozí kontaktní údaje z Web+ nastavení. Použij pro představení kanceláře nebo ověření identity webu.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [],
                    'additionalProperties' => false,
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'site_name' => ['type' => 'string'],
                        'base_url' => ['type' => 'string'],
                        'default_og_image' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }
}
