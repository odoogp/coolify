<?php

namespace App\Services\ProjectAgent;

use App\Models\ProjectAgent;
use App\Models\ProjectAgentMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ProjectAgentRunner
{
    private const MAX_TOOL_ROUNDS = 6;

    public function __construct(
        public ProjectAgent $agent,
        public ProjectAgentToolGate $gate,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{content: string, meta: array<string, mixed>}
     */
    public function chat(string $userMessage, array $history = [], ?string $environmentUuid = null): array
    {
        if (! $this->agent->isReady()) {
            throw new \RuntimeException('Configure the project agent provider and API key first.');
        }

        $this->gate = new ProjectAgentToolGate($this->gate->project, $this->agent);
        $tools = new ProjectAgentTools($this->agent, $this->gate);
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($environmentUuid)],
            ...array_values(array_filter($history, fn (array $row): bool => in_array($row['role'] ?? '', ['user', 'assistant'], true))),
            ['role' => 'user', 'content' => $userMessage],
        ];

        $toolsUsed = [];
        $content = '';

        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $payload = [
                'model' => $this->agent->model,
                'messages' => $messages,
                'temperature' => 0.2,
                'tools' => $tools->definitions(),
                'tool_choice' => 'auto',
            ];

            $response = Http::timeout(90)
                ->withToken((string) $this->agent->api_key)
                ->acceptJson()
                ->post($this->agent->resolvedBaseUrl().'/chat/completions', $payload);

            if (! $response->successful()) {
                throw new \RuntimeException('The AI provider rejected the request (HTTP '.$response->status().').');
            }

            $message = data_get($response->json(), 'choices.0.message', []);
            $toolCalls = data_get($message, 'tool_calls', []);

            if (is_array($toolCalls) && $toolCalls !== []) {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => data_get($message, 'content'),
                    'tool_calls' => $toolCalls,
                ];

                foreach ($toolCalls as $call) {
                    $name = (string) data_get($call, 'function.name', '');
                    $rawArgs = (string) data_get($call, 'function.arguments', '{}');
                    $args = json_decode($rawArgs, true);
                    if (! is_array($args)) {
                        $args = [];
                    }
                    $result = $tools->execute($name, $args);
                    $toolsUsed[] = $name;
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) data_get($call, 'id', ''),
                        'content' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ];
                }

                continue;
            }

            $content = (string) data_get($message, 'content', '');
            break;
        }

        if ($content === '') {
            $content = __('The agent returned an empty reply.');
        }

        return [
            'content' => $content,
            'meta' => [
                'provider' => $this->agent->provider,
                'model' => $this->agent->model,
                'environment_uuid' => $environmentUuid,
                'tools_used' => array_values(array_unique($toolsUsed)),
            ],
        ];
    }

    public function persistTurn(string $userMessage, string $assistantMessage, ?int $userId, ?string $environmentUuid, array $meta = []): void
    {
        ProjectAgentMessage::query()->create([
            'project_agent_id' => $this->agent->id,
            'user_id' => $userId,
            'environment_uuid' => $environmentUuid,
            'role' => 'user',
            'content' => $userMessage,
            'meta' => null,
        ]);
        ProjectAgentMessage::query()->create([
            'project_agent_id' => $this->agent->id,
            'user_id' => null,
            'environment_uuid' => $environmentUuid,
            'role' => 'assistant',
            'content' => $assistantMessage,
            'meta' => $meta,
        ]);
    }

    private function systemPrompt(?string $environmentUuid): string
    {
        $services = $this->gate->listServices()
            ->take(40)
            ->map(fn (array $row): string => '- '.$row['name'].' ['.$row['environment'].'] '.$row['uuid'].' status='.$row['status'])
            ->implode("\n");

        $connected = $this->gate->describeConnectedNodes($this->agent->connectedNodeIds());
        $connectedText = collect($connected)
            ->map(fn (array $row): string => '- '.$row['id'].': '.$row['summary'])
            ->implode("\n");

        $projectName = (string) $this->agent->project->name;

        return Str::of(<<<'PROMPT'
            You are the getcloud.sh project agent for ONE project only: :project.
            You MUST stay inside this project. Never ask for or act on other projects, teams, servers outside this project, or instance-wide settings.
            Your access is granted ONLY by canvas connections the user drew to you. If nothing is connected, say so and ask them to connect nodes (apps, databases, volumes, GitHub).
            Prefer list_connected_context, then tools for status/start/restart/deployments/rollback/module drafts — only against connected services.
            Destructive or mutating tools require confirm=true. Ask the user for confirmation before calling those tools.
            You must not claim you started, restarted, rolled back, or wrote files unless a tool result confirms it.
            Current environment focus: :environment
            Services unlocked by your connections:
            :services
            Canvas nodes connected to you:
            :connected
            When drafting an Odoo module, the agent must be connected to that Odoo stack; call draft_odoo_module with that service_uuid (save=true when the user wants to apply later).
            PROMPT)
            ->replace(':project', $projectName)
            ->replace(':environment', $environmentUuid ?: 'any in this project')
            ->replace(':services', $services !== '' ? $services : '- (none — connect nodes on the canvas)')
            ->replace(':connected', $connectedText !== '' ? $connectedText : '- (none)')
            ->toString();
    }
}
