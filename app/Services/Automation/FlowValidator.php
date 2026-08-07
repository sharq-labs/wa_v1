<?php

namespace App\Services\Automation;

use App\Enums\NodeType;
use App\Enums\TemplateStatus;
use App\Models\WhatsAppTemplate;
use App\Models\Workspace;

/**
 * Validates a flow definition before publishing. A definition that fails
 * validation can be saved as draft but can never be published.
 */
class FlowValidator
{
    /**
     * @return array<int, array{node_id: ?string, message: string}>
     */
    public function validate(Workspace $workspace, array $definition): array
    {
        $errors = [];
        $nodes = $definition['nodes'] ?? [];
        $edges = $definition['edges'] ?? [];

        if ($nodes === []) {
            return [['node_id' => null, 'message' => 'The flow is empty.']];
        }

        $nodeIds = array_column($nodes, 'id');
        $nodeById = array_combine($nodeIds, $nodes);

        // Exactly one trigger.
        $triggers = array_values(array_filter($nodes, function (array $n) {
            $type = NodeType::tryFrom($n['type'] ?? '');

            return $type?->isTrigger() ?? false;
        }));

        if (count($triggers) === 0) {
            $errors[] = ['node_id' => null, 'message' => 'The flow needs a trigger node.'];
        } elseif (count($triggers) > 1) {
            $errors[] = ['node_id' => $triggers[1]['id'] ?? null, 'message' => 'Only one trigger node is allowed per flow.'];
        }

        // Edges must reference existing nodes.
        foreach ($edges as $edge) {
            if (! in_array($edge['source'] ?? null, $nodeIds, true)) {
                $errors[] = ['node_id' => null, 'message' => 'An edge references a missing source node.'];
            }
            if (! in_array($edge['target'] ?? null, $nodeIds, true)) {
                $errors[] = ['node_id' => null, 'message' => 'An edge references a missing target node.'];
            }
        }

        foreach ($nodes as $node) {
            $type = NodeType::tryFrom($node['type'] ?? '');

            if (! $type) {
                $errors[] = ['node_id' => $node['id'] ?? null, 'message' => "Unknown node type [{$node['type']}]."];

                continue;
            }

            $errors = array_merge($errors, $this->validateNode($workspace, $node, $type, $edges));
        }

        // Unreachable-loop guard: warn on cycles that contain no wait nodes.
        $cycleNode = $this->findTightCycle($nodeIds, $nodeById, $edges);
        if ($cycleNode !== null) {
            $errors[] = ['node_id' => $cycleNode, 'message' => 'The flow contains a loop with no wait or delay inside it.'];
        }

        return $errors;
    }

    protected function validateNode(Workspace $workspace, array $node, NodeType $type, array $edges): array
    {
        $errors = [];
        $config = $node['config'] ?? [];
        $id = $node['id'] ?? null;

        $push = function (string $message) use (&$errors, $id) {
            $errors[] = ['node_id' => $id, 'message' => $message];
        };

        switch ($type) {
            case NodeType::TriggerKeyword:
                if (array_filter($config['keywords'] ?? []) === []) {
                    $push('Keyword trigger needs at least one keyword.');
                }
                break;

            case NodeType::SendText:
                if (trim((string) ($config['text'] ?? '')) === '') {
                    $push('Send Text node has no message text.');
                }
                break;

            case NodeType::SendImage:
            case NodeType::SendVideo:
            case NodeType::SendAudio:
            case NodeType::SendDocument:
                if (trim((string) ($config['url'] ?? '')) === '') {
                    $push('Media node has no file URL.');
                }
                break;

            case NodeType::AskQuestion:
                if (trim((string) ($config['question'] ?? '')) === '') {
                    $push('Ask Question node has no question text.');
                }
                if (trim((string) ($config['save_to'] ?? '')) === '') {
                    $push('Ask Question node needs a save destination.');
                }
                break;

            case NodeType::SendButtons:
                $buttons = $config['buttons'] ?? [];
                if (trim((string) ($config['body'] ?? '')) === '') {
                    $push('Buttons node has no body text.');
                }
                if ($buttons === [] || count($buttons) > 3) {
                    $push('Buttons node needs between 1 and 3 buttons.');
                }
                foreach ($buttons as $i => $button) {
                    if (trim((string) ($button['title'] ?? '')) === '') {
                        $push('Button #'.($i + 1).' has no title.');
                    }
                }
                break;

            case NodeType::SendList:
                $sections = $config['sections'] ?? [];
                if (trim((string) ($config['body'] ?? '')) === '') {
                    $push('List node has no body text.');
                }
                if (trim((string) ($config['button'] ?? '')) === '') {
                    $push('List node needs a button label.');
                }
                if ($sections === []) {
                    $push('List node needs at least one section with rows.');
                    break;
                }
                $rowCount = 0;
                foreach ($sections as $si => $section) {
                    $rows = $section['rows'] ?? [];
                    if ($rows === []) {
                        $push('List section #'.($si + 1).' has no rows.');
                    }
                    foreach ($rows as $ri => $row) {
                        $rowCount++;
                        if (trim((string) ($row['title'] ?? '')) === '') {
                            $push('List row #'.($ri + 1).' in section #'.($si + 1).' has no title.');
                        }
                    }
                }
                if ($rowCount > 10) {
                    $push('List messages support a maximum of 10 rows.');
                }
                if (count($sections) > 10) {
                    $push('List messages support a maximum of 10 sections.');
                }
                break;

            case NodeType::Condition:
                if (($config['conditions'] ?? []) === []) {
                    $push('Condition node has no conditions configured.');
                }
                $handles = array_map(fn ($e) => $e['sourceHandle'] ?? null,
                    array_filter($edges, fn ($e) => ($e['source'] ?? null) === $id));
                if (! in_array('true', $handles, true) && ! in_array('false', $handles, true)) {
                    $push('Condition node has no TRUE or FALSE branch connected.');
                }
                break;

            case NodeType::SendTemplate:
                $templateId = $config['template_id'] ?? null;
                if (! $templateId) {
                    $push('Send Template node has no template selected.');
                    break;
                }
                $template = WhatsAppTemplate::query()->forWorkspace($workspace)->find($templateId);
                if (! $template) {
                    $push('Selected template no longer exists.');
                } elseif ($template->status !== TemplateStatus::Approved) {
                    $push("Template [{$template->name}] is not approved.");
                }
                break;

            case NodeType::HttpRequest:
            case NodeType::SendWebhook:
                $url = (string) ($config['url'] ?? '');
                if (! filter_var($url, FILTER_VALIDATE_URL) && ! str_contains($url, '{{')) {
                    $push('HTTP node URL is not a valid URL.');
                }
                break;

            case NodeType::AssignAgent:
                if (empty($config['user_id']) && empty($config['team_id']) && empty($config['strategy'])) {
                    $push('Assign Agent node needs an agent, a team or a strategy.');
                }
                break;

            case NodeType::Delay:
                if ((int) ($config['amount'] ?? 0) < 1) {
                    $push('Delay node needs a positive duration.');
                }
                break;

            case NodeType::GoToNode:
                if (empty($config['target_node_id'])) {
                    $push('Go To node has no target selected.');
                }
                break;

            case NodeType::StartAutomation:
                if (empty($config['automation_id'])) {
                    $push('Start Automation node has no automation selected.');
                }
                break;

            default:
                break;
        }

        return $errors;
    }

    /**
     * Detects cycles that contain no waiting node (ask/buttons/delay/until).
     * Those would burn through the step limit instantly.
     *
     * @return string|null a node id inside the cycle
     */
    protected function findTightCycle(array $nodeIds, array $nodeById, array $edges): ?string
    {
        $waitTypes = [
            NodeType::AskQuestion->value,
            NodeType::SendButtons->value,
            NodeType::Delay->value,
            NodeType::WaitUntil->value,
        ];

        $adjacency = [];
        foreach ($edges as $edge) {
            $source = $edge['source'] ?? null;
            $target = $edge['target'] ?? null;
            if ($source && $target) {
                $adjacency[$source][] = $target;
            }
        }

        $visiting = [];
        $done = [];

        $dfs = function (string $nodeId, array $path) use (&$dfs, &$visiting, &$done, $adjacency, $nodeById, $waitTypes): ?string {
            if (isset($done[$nodeId])) {
                return null;
            }

            if (isset($visiting[$nodeId])) {
                // Cycle found — check whether any node inside it waits.
                $cycleStart = array_search($nodeId, $path, true);
                $cycle = array_slice($path, (int) $cycleStart);

                foreach ($cycle as $id) {
                    if (in_array($nodeById[$id]['type'] ?? '', $waitTypes, true)) {
                        return null; // waits inside — acceptable loop
                    }
                }

                return $nodeId;
            }

            $visiting[$nodeId] = true;
            $path[] = $nodeId;

            foreach ($adjacency[$nodeId] ?? [] as $next) {
                $found = $dfs($next, $path);
                if ($found !== null) {
                    return $found;
                }
            }

            unset($visiting[$nodeId]);
            $done[$nodeId] = true;

            return null;
        };

        foreach ($nodeIds as $id) {
            $found = $dfs($id, []);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
