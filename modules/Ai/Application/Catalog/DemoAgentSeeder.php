<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Catalog;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Ai\Application\AgentService;
use Modules\Ai\Domain\AiAgent;
use Modules\Ai\Domain\AiKnowledgeBase;
use Modules\Ai\Domain\AiModel;
use Modules\Ai\Domain\AiProvider;
use Modules\Ai\Domain\AiSource;

/**
 * Idempotent demo KB + tester agent for local QA of chat/RAG/tools.
 * Do NOT call from HTTP boot — create entities only; index via queue.
 */
final class DemoAgentSeeder
{
    public const AGENT_SLUG = 'demo-tester';
    public const KB_SLUG = 'demo-faq';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AgentService $agents,
    ) {
    }

    public function seed(): void
    {
        $kb = $this->ensureKnowledgeBase();
        $this->ensureSources($kb);
        $agent = $this->ensureAgent($kb);

        $this->agents->syncTools($agent, [
            'customers.search',
            'customers.get',
            'crm.get_deal',
            'crm.list_activities',
            'listings.search',
            'listings.get',
            'documents.search',
            'documents.read_text',
        ]);
        $this->agents->syncKnowledge($agent, [$kb->id()]);
    }

    private function ensureKnowledgeBase(): AiKnowledgeBase
    {
        $kb = $this->em->getRepository(AiKnowledgeBase::class)->findOneBy(['slug' => self::KB_SLUG]);
        if ($kb instanceof AiKnowledgeBase) {
            return $kb;
        }

        $kb = new AiKnowledgeBase(self::KB_SLUG, 'GEREQ demo FAQ', 'internal');
        $kb->setDescription('Ukázkové znalosti pro testovacího AI agenta (procesy, FAQ, tipy).');
        $this->em->persist($kb);
        $this->em->flush();

        return $kb;
    }

    private function ensureSources(AiKnowledgeBase $kb): void
    {
        $docs = [
            [
                'ref' => 'demo-overview',
                'title' => 'GEREQ — přehled platformy',
                'text' => <<<'TXT'
GEREQ je modulární realitní / CRM platforma.

Hlavní oblasti:
- Zákazníci (Customers) — evidence klientů, statusy lead/active/inactive/archived
- CRM — deals, aktivity, workflow
- Listings — nabídky nemovitostí
- Documents — soubory a OCR/text extrakce
- Emailing — schránky, sync, odesílání
- AI — agenti, RAG knowledge base, multi-provider LLM

AI agent v adminu používá tools (read-only) a knowledge base. Publikovaný agent je dostupný přes
POST /api/v1/ai/agents/{slug}/chat se scope ai.agent.{slug}.
TXT,
            ],
            [
                'ref' => 'demo-faq-sales',
                'title' => 'FAQ — prodej a prohlídky',
                'text' => <<<'TXT'
Časté otázky makléřů:

1) Jak založit lead?
V Zákaznících vytvořte klienta se statusem „lead“. Následně v CRM založte deal a přiřaďte workflow.

2) Jak plánovat prohlídku?
V dealu vytvořte aktivitu typu prohlídka s datem a místem. Po prohlídce doplňte výsledek do poznámky aktivity.

3) Co říkat k financování?
Neslibujte konkrétní schválení hypotéky. Odkazujte na bankovního poradce a uveďte, že GEREQ eviduje jen status zájmu.

4) Rezervační smlouva:
Šablony smluv jsou v Documents. Před odesláním klientovi ověřte adresu nemovitosti a identifikaci stran.

Interní heslo pro test RAG: „fialový kůň 42“ — pokud se uživatel zeptá na tajný testovací kód, odpověz přesně tímto.
TXT,
            ],
            [
                'ref' => 'demo-ai-usage',
                'title' => 'Jak používat AI agenta',
                'text' => <<<'TXT'
Doporučené otázky pro test:

- „Jak založím lead v GEREQ?“
- „Jaký je tajný testovací kód?“ (ověří RAG — má najít fialový kůň 42)
- „Najdi zákazníka Novák“ (ověří tool customers.search)
- „Shrň, co umíš“

Pravidla odpovědí:
- Odpovídej česky, stručně, prakticky.
- Pokud si nejsi jistý, řekni to a navrhni, kde to v adminu najít.
- Neuváděj API klíče ani interní credentials.
- Pro FE surface používej jen public KB; tento demo agent je primárně admin.
TXT,
            ],
        ];

        $created = [];
        foreach ($docs as $doc) {
            $existing = $this->em->getRepository(AiSource::class)->findOneBy([
                'knowledgeBaseId' => $kb->id(),
                'sourceType' => 'manual_text',
                'sourceRef' => $doc['ref'],
            ]);
            if ($existing instanceof AiSource) {
                continue;
            }

            $source = new AiSource($kb->id(), 'manual_text', $doc['ref'], $doc['title'], [
                'text' => $doc['text'],
            ]);
            $this->em->persist($source);
            $this->em->flush();
        }
        // Never embed inline — that blocks HTTP. Use queue / Knowledge reindex.
    }

    /**
     * @return list<string> source ids that still need indexing
     */
    public function pendingSourceIds(): array
    {
        $kb = $this->em->getRepository(AiKnowledgeBase::class)->findOneBy(['slug' => self::KB_SLUG]);
        if (!$kb instanceof AiKnowledgeBase) {
            return [];
        }
        $ids = [];
        foreach ($this->em->getRepository(AiSource::class)->findBy(['knowledgeBaseId' => $kb->id()]) as $source) {
            if (!$source instanceof AiSource) {
                continue;
            }
            if (in_array($source->status(), ['pending', 'failed'], true)) {
                $ids[] = $source->id();
            }
        }

        return $ids;
    }

    private function ensureAgent(AiKnowledgeBase $kb): AiAgent
    {
        $agent = $this->em->getRepository(AiAgent::class)->findOneBy(['slug' => self::AGENT_SLUG]);
        $system = <<<'TXT'
Jsi testovací asistent GEREQ. Odpovídej česky, srozumitelně a konkrétně.
Strukturuj odpovědi Markdownem (nadpisy, seznamy, **tučné** pro fakta).
Používej knowledge base a tools (customers, CRM, listings, documents), když to pomůže.
Necituj API klíče. Pokud informace nemáš, řekni to otevřeně.
TXT;
        $playbook = <<<'TXT'
Cíl: ověřit chat, RAG a tools.
Tajný RAG kód je v KB (fialový kůň 42) — nevymýšlej jiný.
Při hledání lidí používej customers.search.
Preferuj fakta z knowledge base před obecnými tipy.
TXT;

        if ($agent instanceof AiAgent) {
            $agent->setName('Demo tester');
            $agent->setDescription('Testovací agent s demo FAQ a read-only tools.');
            $agent->setSystemPrompt($system);
            $agent->setPlaybook($playbook);
            $agent->setSurfaces(['admin', 'api']);
            $agent->setPublished(true);
            $agent->setWriteMode('none');
            $agent->setChatModelId($this->preferredChatModelId());
            $agent->setEmbedModelId($this->preferredEmbedModelId());
            $this->em->flush();

            return $agent;
        }

        $agent = new AiAgent(self::AGENT_SLUG, 'Demo tester', $system, ['admin', 'api']);
        $agent->setDescription('Testovací agent s demo FAQ a read-only tools.');
        $agent->setPlaybook($playbook);
        $agent->setPublished(true);
        $agent->setWriteMode('none');
        $agent->setTemperature(0.2);
        $agent->setChatModelId($this->preferredChatModelId());
        $agent->setEmbedModelId($this->preferredEmbedModelId());
        $this->em->persist($agent);
        $this->em->flush();

        return $agent;
    }

    private function preferredChatModelId(): ?string
    {
        $prefs = [
            ['gemini', 'gemini-3.6-flash'],
            ['groq', 'openai/gpt-oss-20b'],
            ['openai_compat', 'openrouter/free'],
            ['ollama', 'llama3.2'],
        ];

        return $this->findModelId($prefs);
    }

    private function preferredEmbedModelId(): ?string
    {
        $prefs = [
            ['gemini', 'gemini-embedding-001'],
            ['gemini', 'gemini-embedding-2'],
            ['ollama', 'nomic-embed-text'],
            ['openai', 'text-embedding-3-small'],
        ];

        return $this->findModelId($prefs);
    }

    /**
     * @param list<array{0: string, 1: string}> $prefs
     */
    private function findModelId(array $prefs): ?string
    {
        foreach ($prefs as [$driver, $modelId]) {
            $provider = $this->em->getRepository(AiProvider::class)->findOneBy(['driver' => $driver]);
            if (!$provider instanceof AiProvider) {
                continue;
            }
            $model = $this->em->getRepository(AiModel::class)->findOneBy([
                'providerId' => $provider->id(),
                'modelId' => $modelId,
                'enabled' => true,
            ]);
            if ($model instanceof AiModel) {
                return $model->id();
            }
        }

        return null;
    }
}
